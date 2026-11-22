<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\Contracts\Assistant as AssistantContract;
use App\Domain\Assistant\Ui\RunTail;
use App\Models\AiConversationMeta;
use App\Models\AiRunEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RunController
{
    public function stream(Request $request, string $runId, RunTail $runTail): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! Str::isUuid($runId)) {
            abort(404);
        }

        if (AiRunEvent::query()->where('run_id', $runId)->where('user_id', '<>', $user->id)->exists()) {
            abort(404);
        }

        /** @var string|null $conversationId */
        $conversationId = AiRunEvent::query()->where('run_id', $runId)->where('user_id', $user->id)->value('conversation_id');
        $dispatchedAt = null;

        if ($conversationId === null) {
            $meta = Cache::get("assistant:run:{$runId}");
            if (is_array($meta) && isset($meta['userId'], $meta['conversationId'])) {
                if ($meta['userId'] !== $user->id) {
                    abort(404);
                }
                $conversationId = (string) $meta['conversationId'];
                $dispatchedAt = isset($meta['dispatchedAt']) ? (int) $meta['dispatchedAt'] : null;
            } else {
                $userConvIds = AiConversationMeta::query()
                    ->where('user_id', $user->id)
                    ->pluck('conversation_id');

                foreach ($userConvIds as $userConvId) {
                    $marker = Cache::get("assistant:running:{$userConvId}");
                    if (is_array($marker) && ($marker['runId'] ?? '') === $runId) {
                        $conversationId = (string) $userConvId;
                        $dispatchedAt = isset($marker['dispatchedAt']) ? (int) $marker['dispatchedAt'] : null;
                        break;
                    }
                }
            }
        }

        if ($conversationId === null && AiRunEvent::query()->where('run_id', $runId)->doesntExist()) {
            abort(404);
        }

        if ($conversationId !== null && $dispatchedAt === null) {
            $marker = Cache::get("assistant:running:{$conversationId}");
            if (is_array($marker) && ($marker['runId'] ?? '') === $runId && isset($marker['dispatchedAt'])) {
                $dispatchedAt = (int) $marker['dispatchedAt'];
            }
        }

        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'x-vercel-ai-ui-message-stream' => 'v1',
            'x-resumable-stream-id' => $runId,
        ];

        return response()->stream(function () use ($runTail, $runId, $user, $conversationId, $dispatchedAt): void {
            foreach ($runTail->stream($runId, $user->id, $conversationId, fromSeq: 0, dispatchedAt: $dispatchedAt) as $chunk) {
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

    public function cancel(Request $request, string $runId, AssistantContract $assistant): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! Str::isUuid($runId)) {
            abort(404);
        }

        if (AiRunEvent::query()->where('run_id', $runId)->where('user_id', '<>', $user->id)->exists()) {
            abort(404);
        }

        $meta = Cache::get("assistant:run:{$runId}");
        if (is_array($meta) && isset($meta['userId']) && $meta['userId'] !== $user->id) {
            abort(404);
        }

        $assistant->cancel($runId);

        if (is_array($meta) && isset($meta['conversationId'])) {
            $convId = (string) $meta['conversationId'];
            $marker = Cache::get("assistant:running:{$convId}");
            if (is_array($marker) && ($marker['runId'] ?? '') === $runId) {
                Cache::forget("assistant:running:{$convId}");
            }
        }

        return response()->noContent();
    }
}
