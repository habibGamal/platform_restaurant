<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Models\AiRunEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * The stored event log of an assistant run: the queued job writes it, the chat page polls it.
 *
 * Rows are never changed once written (apart from linking them to the saved answer), so a reader
 * that has seen up to `seq` N only ever needs the rows after N. Text deltas are buffered and
 * written as one new row every ~200 characters, after a ~250 ms pause, or before any other event.
 * One instance must be the only writer of a run: it numbers the rows itself.
 */
final class RunLog
{
    private const FLUSH_CHARS = 200;

    private const FLUSH_MS = 250;

    private const FINISHING_TYPES = ['done', 'error'];

    /** @var array<string, int> */
    private array $lastSeq = [];

    /** @var array<string, array{userId: int, conversationId: ?string, text: string, startedAtMs: float}> */
    private array $pendingText = [];

    public function append(string $runId, int $userId, AssistantEvent $event, ?string $conversationId = null): void
    {
        $conversationId ??= $event->conversationId;

        if ($event->type === 'text_delta') {
            $pending = $this->pendingText[$runId] ?? ['userId' => $userId, 'conversationId' => $conversationId, 'text' => '', 'startedAtMs' => $this->nowMs()];
            $pending['text'] .= (string) $event->text;
            $this->pendingText[$runId] = $pending;

            if (mb_strlen($pending['text']) >= self::FLUSH_CHARS || $this->nowMs() - $pending['startedAtMs'] >= self::FLUSH_MS) {
                $this->flush($runId);
            }

            return;
        }

        $this->flush($runId);
        $this->insert($runId, $userId, $conversationId, $event->type, $this->payload($event));

        if ($event->type === 'done' && $event->messageId !== null) {
            AiRunEvent::query()->where('run_id', $runId)->where('user_id', $userId)->update(['message_id' => $event->messageId]);
        }
    }

    /** Write the buffered text of the run, if any. */
    public function flush(string $runId): void
    {
        $pending = $this->pendingText[$runId] ?? null;
        unset($this->pendingText[$runId]);

        if ($pending === null || $pending['text'] === '') {
            return;
        }

        $this->insert($runId, $pending['userId'], $pending['conversationId'], 'text_delta', ['text' => $pending['text']]);
    }

    /**
     * The run's events after $afterSeq, oldest first; empty when the run belongs to another user.
     *
     * @return list<array{seq: int, type: string, payload: array<string, mixed>}>
     */
    public function since(string $runId, int $userId, int $afterSeq): array
    {
        return $this->rows(AiRunEvent::query()
            ->where('run_id', $runId)
            ->where('user_id', $userId)
            ->where('seq', '>', $afterSeq));
    }

    /**
     * The work log of the run that produced a saved assistant message.
     *
     * @return list<array{seq: int, type: string, payload: array<string, mixed>}>
     */
    public function forMessage(string $messageId): array
    {
        return $this->rows(AiRunEvent::query()->where('message_id', $messageId));
    }

    public function isFinished(string $runId): bool
    {
        return AiRunEvent::query()
            ->where('run_id', $runId)
            ->whereIn('type', self::FINISHING_TYPES)
            ->exists();
    }

    /**
     * @param  Builder<AiRunEvent>  $query
     * @return list<array{seq: int, type: string, payload: array<string, mixed>}>
     */
    private function rows(Builder $query): array
    {
        return array_values($query->orderBy('seq')
            ->get(['seq', 'type', 'payload'])
            ->map(fn (AiRunEvent $row): array => ['seq' => $row->seq, 'type' => $row->type, 'payload' => $row->payload])
            ->all());
    }

    /** @param  array<string, mixed>  $payload */
    private function insert(string $runId, int $userId, ?string $conversationId, string $type, array $payload): void
    {
        $seq = ($this->lastSeq[$runId] ??= (int) AiRunEvent::query()->where('run_id', $runId)->max('seq')) + 1;
        $this->lastSeq[$runId] = $seq;

        AiRunEvent::query()->create([
            'run_id' => $runId,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'seq' => $seq,
            'type' => $type,
            'payload' => $payload,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(AssistantEvent $event): array
    {
        return array_filter([
            'text' => $event->text,
            'toolName' => $event->toolName,
            'conversationId' => $event->conversationId,
            'messageId' => $event->messageId,
            'errorCode' => $event->errorCode,
            'agent' => $event->agent,
            'failed' => $event->failed,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function nowMs(): float
    {
        return (float) now()->getPreciseTimestamp(3);
    }
}
