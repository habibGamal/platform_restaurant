<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\RunLog;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Answers one question and writes every event to the run log, which the chat page polls.
 *
 * The gates already ran in AssistantService::start(), so this runs the agents directly.
 */
class RunAssistantQuestion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Connection and queue name of the assistant worker (config/queue.php). */
    public const QUEUE = 'assistant';

    private const FAILED_AR = 'تعذر إكمال الإجابة. أعد إرسال السؤال من فضلك.';

    public int $timeout = 900;

    /** A run is never retried: a second attempt would answer (and bill) the question twice. */
    public int $tries = 1;

    /** Seconds the unique lock is held at most; longer than the timeout so a duplicate can never overlap. */
    public int $uniqueFor = 960;

    public function __construct(
        public int $userId,
        public string $channel,
        public string $conversationId,
        public string $text,
        public string $runId,
    ) {
        $this->onConnection(self::QUEUE)->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return $this->runId;
    }

    public function handle(AssistantService $assistant, RunLog $log): void
    {
        $user = User::query()->find($this->userId);

        // Already finished: the page gave up waiting for a worker; answering now would only bill it.
        if ($user === null || $log->isFinished($this->runId)) {
            return;
        }

        try {
            $events = $assistant->runStream($user, Channel::from($this->channel), $this->conversationId, $this->text, $this->runId);

            foreach ($events as $event) {
                $log->append($this->runId, $this->userId, $event, $this->conversationId);
            }
        } catch (Throwable $e) {
            report($e);

            // The answer may already be logged as done (or error); a second terminal event would contradict it.
            $log->flush($this->runId);
            if ($log->isFinished($this->runId)) {
                return;
            }

            $log->append($this->runId, $this->userId, AssistantEvent::error('provider_error', self::FAILED_AR, $this->conversationId), $this->conversationId);
        } finally {
            $log->flush($this->runId);
        }
    }

    /** Called when the worker gives up on the job (timeout, crash), so the page stops waiting. */
    public function failed(?Throwable $exception): void
    {
        $log = new RunLog;

        if ($log->isFinished($this->runId) || ! User::query()->whereKey($this->userId)->exists()) {
            return;
        }

        $log->append($this->runId, $this->userId, AssistantEvent::error('provider_error', self::FAILED_AR, $this->conversationId), $this->conversationId);
    }
}
