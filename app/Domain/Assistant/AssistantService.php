<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Ai\Agents\RestaurantAnalyst;
use App\Domain\AiBilling\BudgetExceededException;
use App\Domain\AiBilling\BudgetGuard;
use App\Domain\Assistant\Contracts\Assistant;
use App\Models\AiConversationMeta;
use App\Models\User;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Error as SdkError;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as SdkToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as SdkToolResult;
use Throwable;

final class AssistantService implements Assistant
{
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
        // Gate 1: AiAccess
        if (! $this->aiAccess->allows($user)) {
            $code = $this->aiAccess->denialReason($user) ?? 'forbidden';
            $messageAr = $this->aiAccess->messageAr($code);
            yield AssistantEvent::error('forbidden', $messageAr, $conversationId);

            return;
        }

        // Gate 2: BudgetGuard
        try {
            $this->budgetGuard->assertCanRun($user);
        } catch (BudgetExceededException $e) {
            yield AssistantEvent::error('budget_exceeded', $e->getMessage(), $conversationId);

            return;
        }

        // Gate 3: Per-user rate limit with Laravel RateLimiter
        $maxMessages = (int) config('ai_billing.rate_limits.messages_per_minute_per_user', 20);
        $rateLimitKey = "ai:rate_limit:user:{$user->id}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxMessages)) {
            yield AssistantEvent::error(
                'rate_limited',
                'تم تجاوز الحد المسموح به من الرسائل في الدقيقة. يرجى الانتظار قليلاً والمحاولة لاحقاً.',
                $conversationId
            );

            return;
        }
        RateLimiter::hit($rateLimitKey, 60);

        // Gate 4: Conversation ownership / registration
        if ($conversationId !== null) {
            try {
                $this->conversationGuard->assertBelongsTo($conversationId, $user);
            } catch (AuthorizationException) {
                yield AssistantEvent::error('forbidden', 'ليس لديك صلاحية الوصول إلى هذه المحادثة.', $conversationId);

                return;
            }
        } else {
            $conversationId = $this->startConversation($user, $channel, $text);
        }

        $agent = (new RestaurantAnalyst($user, $channel, $runId))->continue($conversationId, as: $user);

        $lastMessageId = null;
        $endedWithError = false;

        try {
            $streamResponse = $agent->stream($text);

            foreach ($streamResponse as $event) {
                if ($event instanceof TextDelta) {
                    $lastMessageId = $event->messageId;
                    yield AssistantEvent::textDelta($event->delta);
                } elseif ($event instanceof SdkToolCall) {
                    yield AssistantEvent::toolCall($event->toolCall->name);
                } elseif ($event instanceof SdkToolResult) {
                    yield AssistantEvent::toolResult($event->toolResult->name);
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
                yield AssistantEvent::done($conversationId, $lastMessageId);
            }
        } catch (Throwable $e) {
            report($e);

            yield AssistantEvent::error('provider_error', 'حدث خطأ في مزود خدمة الذكاء الاصطناعي. يرجى المحاولة لاحقاً.', $conversationId);
        } finally {
            $this->touchConversation($conversationId);
        }
    }

    /**
     * Answer without streaming (WhatsApp, scheduled jobs).
     */
    public function ask(User $user, Channel $channel, ?string $conversationId, string $text): AssistantReply
    {
        // Gate 1: AiAccess
        if (! $this->aiAccess->allows($user)) {
            $code = $this->aiAccess->denialReason($user) ?? 'forbidden';

            return new AssistantReply(
                text: $this->aiAccess->messageAr($code),
                conversationId: $conversationId,
                errorCode: 'forbidden',
            );
        }

        // Gate 2: BudgetGuard
        try {
            $this->budgetGuard->assertCanRun($user);
        } catch (BudgetExceededException $e) {
            return new AssistantReply(
                text: $e->getMessage(),
                conversationId: $conversationId,
                errorCode: 'budget_exceeded',
            );
        }

        // Gate 3: Per-user rate limit with Laravel RateLimiter
        $maxMessages = (int) config('ai_billing.rate_limits.messages_per_minute_per_user', 20);
        $rateLimitKey = "ai:rate_limit:user:{$user->id}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxMessages)) {
            return new AssistantReply(
                text: 'تم تجاوز الحد المسموح به من الرسائل في الدقيقة. يرجى الانتظار قليلاً والمحاولة لاحقاً.',
                conversationId: $conversationId,
                errorCode: 'rate_limited',
            );
        }
        RateLimiter::hit($rateLimitKey, 60);

        // Gate 4: Conversation ownership / registration
        if ($conversationId !== null) {
            try {
                $this->conversationGuard->assertBelongsTo($conversationId, $user);
            } catch (AuthorizationException) {
                return new AssistantReply(
                    text: 'ليس لديك صلاحية الوصول إلى هذه المحادثة.',
                    conversationId: $conversationId,
                    errorCode: 'forbidden',
                );
            }
        } else {
            $conversationId = $this->startConversation($user, $channel, $text);
        }

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

            return new AssistantReply(
                text: $response->text,
                conversationId: $conversationId,
                messageId: $response->assistantMessageId,
                toolsUsed: $toolsUsed,
            );
        } catch (Throwable $e) {
            report($e);

            return new AssistantReply(
                text: 'حدث خطأ في مزود خدمة الذكاء الاصطناعي. يرجى المحاولة لاحقاً.',
                conversationId: $conversationId,
                errorCode: 'provider_error',
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

        Cache::put("ai:cancel:{$userId}:{$runId}", true, 600);
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
