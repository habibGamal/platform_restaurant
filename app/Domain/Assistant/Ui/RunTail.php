<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Ui;

use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\RunLog;
use Closure;
use Generator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Server-Sent Events tail loop for assistant runs.
 *
 * Reads stored run-log events, encodes them into Vercel AI SDK UI message stream v1 parts,
 * and yields SSE frames until the run reaches a terminal state (done/error) or max_seconds.
 * Also runs the queue-wait watchdog when no worker has written events yet.
 */
final class RunTail
{
    public const string QUEUE_UNAVAILABLE_AR = 'المساعد مش متاح دلوقتي. حاول تاني بعد شوية.';

    /** @var Closure(): int */
    private Closure $clock;

    /** @var Closure(int): void */
    private Closure $sleeper;

    /** @var Closure(): bool */
    private Closure $connectionAborted;

    private int $pollMs;

    private int $maxSeconds;

    private int $queueNoticeSeconds;

    private int $queueGiveUpSeconds;

    public function __construct(
        private readonly RunLog $log,
        ?Closure $clock = null,
        ?Closure $sleeper = null,
        ?Closure $connectionAborted = null,
        ?int $pollMs = null,
        ?int $maxSeconds = null,
        ?int $queueNoticeSeconds = null,
        ?int $queueGiveUpSeconds = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) now()->getTimestamp();
        $this->sleeper = $sleeper ?? static function (int $us): void {
            usleep($us);
        };
        $this->connectionAborted = $connectionAborted ?? static fn (): bool => connection_aborted() === 1;
        $this->pollMs = $pollMs ?? (int) config('assistant.stream.poll_ms', 250);
        $this->maxSeconds = $maxSeconds ?? (int) config('assistant.stream.max_seconds', 960);
        $this->queueNoticeSeconds = $queueNoticeSeconds ?? (int) config('assistant.stream.queue_notice_seconds', 60);
        $this->queueGiveUpSeconds = $queueGiveUpSeconds ?? (int) config('assistant.stream.queue_give_up_seconds', 960);
    }

    /**
     * Stream run events as server-sent event lines until done, error, timeout, or disconnect.
     *
     * @return Generator<int, string>
     */
    public function stream(
        string $runId,
        int $userId,
        ?string $conversationId = null,
        int $fromSeq = 0,
        ?int $dispatchedAt = null,
    ): Generator {
        if ($dispatchedAt === null && $conversationId !== null) {
            $marker = Cache::get("assistant:running:{$conversationId}");
            if (is_array($marker) && ($marker['runId'] ?? '') === $runId && isset($marker['dispatchedAt'])) {
                $dispatchedAt = (int) $marker['dispatchedAt'];
            }
        }

        $startedAt = $dispatchedAt ?? ($this->clock)();
        $lastSeq = $fromSeq;
        $noticeSent = false;
        $encoder = new UiStreamEncoder($runId);

        while (true) {
            if (($this->connectionAborted)()) {
                return;
            }

            $rows = $this->log->since($runId, $userId, $lastSeq);

            if ($rows !== []) {
                $finished = false;
                yield from $this->processRows($rows, $encoder, $lastSeq, $finished, $conversationId, $runId);
                if ($finished) {
                    return;
                }
            } else {
                if ($lastSeq === 0) {
                    $waited = ($this->clock)() - $startedAt;

                    if ($waited >= $this->queueGiveUpSeconds) {
                        try {
                            $this->log->append(
                                $runId,
                                $userId,
                                AssistantEvent::error('queue_unavailable', self::QUEUE_UNAVAILABLE_AR, $conversationId),
                                $conversationId
                            );
                        } catch (UniqueConstraintViolationException) {
                            // A worker wrote the run's first row at this very moment: show its rows instead.
                        }

                        $rows = $this->log->since($runId, $userId, $lastSeq);
                        if ($rows !== []) {
                            $finished = false;
                            yield from $this->processRows($rows, $encoder, $lastSeq, $finished, $conversationId, $runId);
                            if ($finished) {
                                return;
                            }
                        }

                        $this->forgetRunningMarker($conversationId, $runId);
                        foreach ($encoder->finish() as $part) {
                            yield $this->formatPart($part);
                        }
                        yield "data: [DONE]\n\n";

                        return;
                    }

                    if ($waited >= $this->queueNoticeSeconds && ! $noticeSent) {
                        foreach ($encoder->status('queue_wait') as $part) {
                            yield $this->formatPart($part);
                        }
                        $noticeSent = true;
                    }
                }
            }

            $elapsed = ($this->clock)() - $startedAt;
            if ($elapsed >= $this->maxSeconds) {
                $this->forgetRunningMarker($conversationId, $runId);
                foreach ($encoder->finish() as $part) {
                    yield $this->formatPart($part);
                }
                yield "data: [DONE]\n\n";

                return;
            }

            ($this->sleeper)($this->pollMs * 1000);
        }
    }

    /**
     * Encode and yield UI stream parts for a batch of run-log rows.
     *
     * @param  list<array{seq: int, type: string, payload: array<string, mixed>}>  $rows
     * @return Generator<int, string>
     */
    private function processRows(
        array $rows,
        UiStreamEncoder $encoder,
        int &$lastSeq,
        bool &$finished,
        ?string $conversationId,
        string $runId,
    ): Generator {
        foreach ($rows as $row) {
            $lastSeq = $row['seq'];
            foreach ($encoder->encode($row) as $part) {
                yield $this->formatPart($part);
            }

            if (in_array($row['type'], ['done', 'error'], true)) {
                $finished = true;
                $this->forgetRunningMarker($conversationId, $runId);
                foreach ($encoder->finish() as $part) {
                    yield $this->formatPart($part);
                }
                yield "data: [DONE]\n\n";

                return;
            }
        }
    }

    private function forgetRunningMarker(?string $conversationId, string $runId): void
    {
        if ($conversationId === null) {
            return;
        }

        $marker = Cache::get("assistant:running:{$conversationId}");
        if (is_array($marker) && ($marker['runId'] ?? '') === $runId) {
            Cache::forget("assistant:running:{$conversationId}");
        }
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function formatPart(array $part): string
    {
        return 'data: '.json_encode($part, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
    }
}
