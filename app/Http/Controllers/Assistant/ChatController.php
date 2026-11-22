<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\Channel;
use App\Domain\Assistant\Contracts\Assistant as AssistantContract;
use App\Domain\Assistant\ConversationGuard;
use App\Domain\Assistant\RunLog;
use App\Domain\Assistant\Ui\RunTail;
use App\Http\Requests\Assistant\ChatRequest;
use App\Models\AiRunEvent;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ChatController
{
    public function __invoke(
        ChatRequest $request,
        AssistantContract $assistant,
        RunTail $runTail,
        ConversationGuard $guard,
        RunLog $runLog,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $runId = $request->input('runId');
        if ($runId !== null) {
            if (! is_string($runId) || ! Str::isUuid($runId)) {
                abort(400, 'A valid UUID runId is required.');
            }

            if (AiRunEvent::query()->where('run_id', $runId)->where('user_id', '<>', $user->id)->exists()) {
                abort(400, 'A valid UUID runId is required.');
            }
        } else {
            $runId = (string) Str::uuid();
        }

        $conversationId = $request->conversationId();
        $dispatchedAt = now()->getTimestamp();
        $targetConvId = $conversationId;

        if ($conversationId !== null) {
            $guard->assertBelongsTo($conversationId, $user);

            try {
                /** @var Response|null $conflictResponse */
                $conflictResponse = Cache::lock("assistant:conversation:{$conversationId}", 10)->block(5, function () use (
                    $conversationId,
                    $runLog,
                    $assistant,
                    $user,
                    $request,
                    $runId,
                    $dispatchedAt
                ): ?Response {
                    if ($this->isConversationRunning($conversationId, $runLog)) {
                        return response()->json([
                            'message' => 'فيه سؤال شغال دلوقتي. استنى لما يخلص.',
                        ], 409);
                    }

                    $assistant->start(
                        $user,
                        Channel::Chat,
                        $conversationId,
                        $request->userPrompt(),
                        $runId
                    );

                    $ttl = (int) config('assistant.stream.queue_give_up_seconds', 960) + 60;
                    Cache::put("assistant:running:{$conversationId}", [
                        'runId' => $runId,
                        'dispatchedAt' => $dispatchedAt,
                    ], $ttl);
                    Cache::put("assistant:run:{$runId}", [
                        'conversationId' => $conversationId,
                        'userId' => $user->id,
                        'dispatchedAt' => $dispatchedAt,
                    ], $ttl);

                    return null;
                });

                if ($conflictResponse instanceof Response) {
                    return $conflictResponse;
                }
            } catch (LockTimeoutException) {
                return response()->json([
                    'message' => 'فيه سؤال شغال دلوقتي. استنى لما يخلص.',
                ], 409);
            }
        } else {
            $actualConversationId = $assistant->start(
                $user,
                Channel::Chat,
                null,
                $request->userPrompt(),
                $runId
            );

            $targetConvId = $actualConversationId;
            if ($targetConvId !== null) {
                $ttl = (int) config('assistant.stream.queue_give_up_seconds', 960) + 60;
                Cache::put("assistant:running:{$targetConvId}", [
                    'runId' => $runId,
                    'dispatchedAt' => $dispatchedAt,
                ], $ttl);
                Cache::put("assistant:run:{$runId}", [
                    'conversationId' => $targetConvId,
                    'userId' => $user->id,
                    'dispatchedAt' => $dispatchedAt,
                ], $ttl);
            }
        }

        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'x-vercel-ai-ui-message-stream' => 'v1',
            'x-resumable-stream-id' => $runId,
        ];

        return response()->stream(function () use ($runTail, $runId, $user, $targetConvId, $dispatchedAt): void {
            foreach ($runTail->stream($runId, $user->id, $targetConvId, fromSeq: 0, dispatchedAt: $dispatchedAt) as $chunk) {
                echo $chunk;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                if (connection_aborted()) {
                    break;
                }
            }
        }, 200, $headers);
    }

    private function isConversationRunning(string $conversationId, RunLog $runLog): bool
    {
        $marker = Cache::get("assistant:running:{$conversationId}");
        if (is_array($marker) && isset($marker['runId'], $marker['dispatchedAt'])) {
            $markerRunId = (string) $marker['runId'];
            $dispatchedAt = (int) $marker['dispatchedAt'];
            $giveUpSeconds = (int) config('assistant.stream.queue_give_up_seconds', 960);
            $waited = now()->getTimestamp() - $dispatchedAt;

            if ($waited < $giveUpSeconds && ! $runLog->isFinished($markerRunId)) {
                return true;
            }
        }

        return AiRunEvent::query()
            ->where('conversation_id', $conversationId)
            ->whereNotIn('run_id', function ($query) use ($conversationId): void {
                $query->select('run_id')
                    ->from('ai_run_events')
                    ->where('conversation_id', $conversationId)
                    ->whereIn('type', ['done', 'error']);
            })
            ->exists();
    }
}
