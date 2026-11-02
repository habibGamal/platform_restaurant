<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Ai\Agents\RestaurantAnalyst;
use App\Domain\AiBilling\BudgetExceededException;
use App\Domain\AiBilling\BudgetGuard;
use App\Domain\Assistant\Contracts\Assistant;
use App\Jobs\RunAssistantQuestion;
use App\Models\AiConversationMeta;
use App\Models\AiRunEvent;
use App\Models\User;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Error as SdkError;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

final class AssistantService implements Assistant
{
    private const MALFORMED_REPLY_AR = 'النموذج رجّع رد غير سليم. أعد إرسال السؤال من فضلك.';

    public function __construct(
        private readonly AiAccess $aiAccess = new AiAccess,
        private readonly BudgetGuard $budgetGuard = new BudgetGuard,
        private readonly ConversationGuard $conversationGuard = new ConversationGuard,
    ) {}

    /**
     * Stream an answer. Yields AssistantEvent items; the last one is always
     * a `done` or `error` event carrying the conversation id.
     *
     * @return Generator<int, AssistantEvent>
     */
    public function stream(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): Generator
    {
        $gateError = $this->gateError($user, $conversationId);
        if ($gateError !== null) {
            yield $gateError;

            return;
        }

        $conversationId ??= $this->startConversation($user, $channel, $text);

        yield from $this->runStream($user, $channel, $conversationId, $text, $runId);
    }

    /**
     * Run the gates now and answer in a queued job that writes every event to the run log.
     *
     * Returns the conversation id (started here when $conversationId is null) so the page can
     * show the conversation before the job runs. A gate failure is logged and nothing is queued.
     */
    public function start(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): ?string
    {
        // A run id that already has rows belongs to an earlier (or another user's) run: never write into it.
        if (AiRunEvent::query()->where('run_id', $runId)->exists()) {
            return $conversationId;
        }

        $gateError = $this->gateError($user, $conversationId);
        if ($gateError !== null) {
            app(RunLog::class)->append($runId, $user->id, $gateError, $conversationId);

            return $conversationId;
        }

        $conversationId ??= $this->startConversation($user, $channel, $text);

        RunAssistantQuestion::dispatch($user->id, $channel->value, $conversationId, $text, $runId);

        return $conversationId;
    }

    /**
     * Stream the agents' answer for a conversation that already passed the gates.
     *
     * @return Generator<int, AssistantEvent>
     */
    public function runStream(User $user, Channel $channel, string $conversationId, string $text, string $runId): Generator
    {
        // Specialist events arrive while the coordinator runs a tool; they are mapped in order before its next event.
        $specialistEvents = [];
        $observer = function (string $specialist, StreamEvent $event) use (&$specialistEvents): void {
            $specialistEvents[] = [$specialist, $event];
        };
        $agent = (new RestaurantAnalyst($user, $channel, $runId, $observer))->continue($conversationId, as: $user);
        $mapping = new AssistantStreamEvents($agent->tools(), $channel);

        $lastMessageId = null;
        $endedWithError = false;

        try {
            $streamResponse = $agent->stream($text);

            foreach ($streamResponse as $event) {
                // Yielded one by one: `yield from` an array reuses its keys and iterator_to_array() would drop events.
                foreach ($specialistEvents as [$specialist, $specialistEvent]) {
                    foreach ($mapping->map($specialistEvent, $specialist) as $mapped) {
                        yield $mapped;
                    }
                }
                $specialistEvents = [];
                foreach ($mapping->map($event) as $mapped) {
                    yield $mapped;
                }

                // Child terminal events must never end the coordinator's run.
                if (! $mapping->isCoordinator($event)) {
                    continue;
                }
                if ($event instanceof TextDelta) {
                    $lastMessageId = $event->messageId;
                } elseif ($event instanceof StreamEnd) {
                    if ($event->reason === 'cancelled') {
                        $endedWithError = true;
                        if (Cache::has("ai:cancel:{$user->id}:{$runId}")) {
                            yield AssistantEvent::error('cancelled', 'تم إيقاف المحادثة بواسطة المستخدم.', $conversationId);
                        } else {
                            yield AssistantEvent::error('budget_exceeded', 'تم إيقاف المساعد الذكي لتجاوز الميزانية أو الحد الأقصى للاستهلاك.', $conversationId);
                        }
                    } elseif ($event->reason === 'refusal') {
                        $endedWithError = true;
                        yield AssistantEvent::error('provider_error', 'اعتذر النموذج عن الإجابة على هذا الطلب.', $conversationId);
                    }
                } elseif ($event instanceof SdkError) {
                    $endedWithError = true;
                    yield AssistantEvent::error('provider_error', 'حدث خطأ في مزود خدمة الذكاء الاصطناعي. يرجى المحاولة لاحقاً.', $conversationId);
                }
            }

            if (! $endedWithError) {
                foreach ($specialistEvents as [$specialist, $specialistEvent]) {
                    foreach ($mapping->map($specialistEvent, $specialist) as $mapped) {
                        yield $mapped;
                    }
                }
                foreach ($mapping->flush() as $mapped) {
                    yield $mapped;
                }

                yield $mapping->coordinatorPrintedToolCall()
                    ? AssistantEvent::error('provider_error', self::MALFORMED_REPLY_AR, $conversationId)
                    : AssistantEvent::done($conversationId, $streamResponse->assistantMessageId ?? $lastMessageId);
            }
        } catch (Throwable $e) {
            report($e);

            [$code, $message] = $this->describeProviderFailure($e);

            yield AssistantEvent::error($code, $message, $conversationId);
        } finally {
            $this->touchConversation($conversationId);
        }
    }

    /**
     * Answer without streaming (WhatsApp, scheduled jobs).
     */
    public function ask(User $user, Channel $channel, ?string $conversationId, string $text): AssistantReply
    {
        $gateError = $this->gateError($user, $conversationId);
        if ($gateError !== null) {
            return new AssistantReply(
                text: (string) $gateError->text,
                conversationId: $conversationId,
                errorCode: $gateError->errorCode,
            );
        }

        $conversationId ??= $this->startConversation($user, $channel, $text);

        $agent = (new RestaurantAnalyst($user, $channel))->continue($conversationId, as: $user);

        try {
            $response = $agent->prompt($text);

            /** @var list<string> $toolsUsed */
            $toolsUsed = $response->toolCalls
                ->map(fn ($tc): string => (string) $tc->name)
                ->unique()
                ->values()
                ->all();

            $lastStep = $response->steps->last();
            $finishReason = $lastStep?->finishReason;

            if ($finishReason === FinishReason::Cancelled) {
                return new AssistantReply(
                    text: 'تم إيقاف المساعد الذكي لتجاوز الميزانية أو الحد الأقصى للاستهلاك.',
                    conversationId: $conversationId,
                    errorCode: 'budget_exceeded',
                );
            }

            if ($finishReason === FinishReason::ContentFilter) {
                return new AssistantReply(
                    text: 'اعتذر النموذج عن الإجابة على هذا الطلب.',
                    conversationId: $conversationId,
                    errorCode: 'provider_error',
                );
            }

            if (AssistantStreamEvents::containsPrintedToolCall($response->text)) {
                return new AssistantReply(
                    text: self::MALFORMED_REPLY_AR,
                    conversationId: $conversationId,
                    errorCode: 'provider_error',
                );
            }

            return new AssistantReply(
                text: AssistantStreamEvents::withoutPlanLines($response->text),
                conversationId: $conversationId,
                messageId: $response->assistantMessageId,
                toolsUsed: $toolsUsed,
            );
        } catch (Throwable $e) {
            report($e);

            [$code, $message] = $this->describeProviderFailure($e);

            return new AssistantReply(
                text: $message,
                conversationId: $conversationId,
                errorCode: $code,
            );
        } finally {
            $this->touchConversation($conversationId);
        }
    }

    /**
     * Ask a running stream to stop after its current step.
     */
    public function cancel(string $runId): void
    {
        if (! Str::isUuid($runId)) {
            return;
        }

        $userId = Auth::id();
        if ($userId === null) {
            return;
        }

        // Outlives the longest run (job timeout 900 s) plus its time in the queue.
        Cache::put("ai:cancel:{$userId}:{$runId}", true, 960);
    }

    /**
     * Run the access, budget, rate-limit and conversation-ownership gates.
     *
     * Returns the error event to show when a gate refuses, or null when the question may run.
     */
    private function gateError(User $user, ?string $conversationId): ?AssistantEvent
    {
        if (! $this->aiAccess->allows($user)) {
            $code = $this->aiAccess->denialReason($user) ?? 'forbidden';

            return AssistantEvent::error('forbidden', $this->aiAccess->messageAr($code), $conversationId);
        }

        try {
            $this->budgetGuard->assertCanRun($user);
        } catch (BudgetExceededException $e) {
            return AssistantEvent::error('budget_exceeded', $e->getMessage(), $conversationId);
        }

        $maxMessages = (int) config('ai_billing.rate_limits.messages_per_minute_per_user', 20);
        $rateLimitKey = "ai:rate_limit:user:{$user->id}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxMessages)) {
            return AssistantEvent::error(
                'rate_limited',
                'تم تجاوز الحد المسموح به من الرسائل في الدقيقة. يرجى الانتظار قليلاً والمحاولة لاحقاً.',
                $conversationId
            );
        }
        RateLimiter::hit($rateLimitKey, 60);

        if ($conversationId !== null) {
            try {
                $this->conversationGuard->assertBelongsTo($conversationId, $user);
            } catch (AuthorizationException) {
                return AssistantEvent::error('forbidden', 'ليس لديك صلاحية الوصول إلى هذه المحادثة.', $conversationId);
            }
        }

        return null;
    }

    /**
     * Turn a provider failure into an error code and an Arabic message the user can act on.
     *
     * @return array{0: string, 1: string}
     */
    private function describeProviderFailure(Throwable $e): array
    {
        $status = $e instanceof RequestException ? $e->response->status() : null;

        return match (true) {
            $status === 401, $status === 403 => ['provider_auth', 'مزود الذكاء الاصطناعي رفض مفتاح الاتصال. راجع المفتاح في إعدادات المساعد.'],
            $status === 429 => ['provider_busy', 'مزود الذكاء الاصطناعي عليه ضغط دلوقتي. أعد إرسال السؤال بعد دقيقة.'],
            default => ['provider_error', 'تعذر الوصول لمزود الذكاء الاصطناعي. أعد إرسال السؤال بعد قليل.'],
        };
    }

    /**
     * Create the SDK conversation row and our ownership record under the same id.
     *
     * The SDK only creates its conversation row when an agent starts without an id, so a
     * conversation we name up front must be stored here, or its messages would point at nothing.
     */
    private function startConversation(User $user, Channel $channel, string $text): string
    {
        $title = Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 80, '…');

        $conversationId = app(ConversationStore::class)->storeConversation(
            Conversation::participantType($user),
            Conversation::participantKey($user),
            $title !== '' ? $title : 'محادثة جديدة',
            (string) Str::uuid7(),
        );

        $this->conversationGuard->register($conversationId, $user, $channel, $title !== '' ? $title : null);

        return $conversationId;
    }

    /**
     * Update conversation last activity timestamp.
     */
    private function touchConversation(?string $conversationId): void
    {
        if ($conversationId === null) {
            return;
        }

        AiConversationMeta::query()
            ->where('conversation_id', $conversationId)
            ->update(['last_activity_at' => now()]);
    }
}
