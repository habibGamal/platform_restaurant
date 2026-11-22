<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Ui;

use App\Models\AiMessagePart;
use App\Models\AiRunEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Persists and reads per-message parts (tool outputs and folded worklog snapshots).
 */
final class MessagePartsRepository
{
    /** The coordinator never sends its own label; specialists normally do (agent_started text). */
    public const array AGENT_LABELS = [
        'assistant' => 'المساعد',
        'marketing' => 'التسويق',
        'finance' => 'المالية',
        'operations' => 'التشغيل',
        'data_checker' => 'مدقق البيانات',
    ];

    /**
     * Fold a series of run events into a worklog snapshot.
     *
     * @param  iterable<int, array{type: string, payload?: array<string, mixed>}|AiRunEvent>  $rows
     * @param  array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}|null  $initial
     * @return array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}
     */
    public static function foldWorkLog(iterable $rows, ?array $initial = null): array
    {
        $log = $initial ?? ['plan' => null, 'agents' => []];

        foreach ($rows as $row) {
            if ($row instanceof AiRunEvent) {
                $type = $row->type;
                $payload = $row->payload;
            } else {
                $type = $row['type'];
                $payload = $row['payload'] ?? [];
            }

            $log = self::reduceStep($log, $type, $payload);
        }

        return $log;
    }

    /**
     * Fold a single event into the worklog state.
     *
     * @param  array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}  $log
     * @param  array<string, mixed>  $payload
     * @return array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}
     */
    public static function reduceStep(array $log, string $type, array $payload): array
    {
        $text = is_string($payload['text'] ?? null) ? trim($payload['text']) : '';

        if ($type === 'plan') {
            $log['plan'] = $text !== '' ? $text : $log['plan'];

            return $log;
        }
        if ($type === 'done' || $type === 'error') {
            return self::finishWorkLog($log, $type === 'done' ? 'done' : 'failed');
        }

        $agent = is_string($payload['agent'] ?? null) ? $payload['agent'] : null;
        if ($agent === null || ! in_array($type, ['agent_started', 'step_started', 'step_finished', 'agent_finished'], true)) {
            return $log;
        }

        $index = array_search($agent, array_column($log['agents'], 'agent'), true);
        if ($index === false) {
            $log['agents'][] = [
                'agent' => $agent,
                'label' => self::AGENT_LABELS[$agent] ?? $agent,
                'status' => 'running',
                'step' => null,
                'findings' => [],
                'steps' => 0,
            ];
            $index = array_key_last($log['agents']);
        }
        $row = $log['agents'][$index];

        if ($type === 'agent_started') {
            $row['status'] = 'running';
            if ($agent !== 'assistant' && $text !== '') {
                $row['label'] = $text;
            }
        } elseif ($type === 'step_started') {
            $row['status'] = 'running';
            $row['step'] = $text !== '' ? $text : null;
            $row['steps']++;
        } elseif ($type === 'step_finished') {
            $row['step'] = null;
            if ($text !== '') {
                $row['findings'][] = $text;
            }
        } else {
            $row['status'] = ($payload['failed'] ?? false) === true ? 'failed' : 'done';
            $row['step'] = null;
        }

        $log['agents'][$index] = $row;

        return $log;
    }

    /**
     * Agents still marked running when the run ended get its final status.
     *
     * @param  array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}  $log
     * @return array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}
     */
    public static function finishWorkLog(array $log, string $status): array
    {
        foreach ($log['agents'] as $i => $agent) {
            if ($agent['status'] === 'running') {
                $log['agents'][$i]['status'] = $status;
                $log['agents'][$i]['step'] = null;
            }
        }

        return $log;
    }

    /**
     * Persist the run's tool_output rows in seq order, plus one worklog snapshot.
     */
    public function persistRun(string $runId, string $messageId): int
    {
        if ($messageId === '') {
            return 0;
        }

        /** @var Collection<int, AiRunEvent> $events */
        $events = AiRunEvent::query()
            ->where('run_id', $runId)
            ->orderBy('seq')
            ->get();

        if ($events->isEmpty()) {
            return 0;
        }

        $firstEvent = $events->first();
        $userId = $firstEvent->user_id;

        $conversationId = '';
        foreach ($events as $event) {
            if (! empty($event->conversation_id)) {
                $conversationId = $event->conversation_id;
                break;
            }
            if (is_string($event->payload['conversationId'] ?? null)) {
                $conversationId = $event->payload['conversationId'];
                break;
            }
        }

        $toolOutputs = $events->where('type', 'tool_output')->values();
        $workLog = self::foldWorkLog($events->all());

        return DB::transaction(function () use ($messageId, $conversationId, $userId, $toolOutputs, $workLog): int {
            AiMessagePart::query()->where('message_id', $messageId)->delete();

            $position = 0;
            foreach ($toolOutputs as $toolOutput) {
                $payload = $toolOutput->payload;
                AiMessagePart::query()->create([
                    'message_id' => $messageId,
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'position' => $position++,
                    'kind' => 'tool',
                    'tool_name' => is_string($payload['toolName'] ?? null) ? $payload['toolName'] : null,
                    'tool_call_id' => is_string($payload['toolCallId'] ?? null) ? $payload['toolCallId'] : null,
                    'payload' => $payload,
                ]);
            }

            AiMessagePart::query()->create([
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'position' => $position++,
                'kind' => 'worklog',
                'tool_name' => null,
                'tool_call_id' => null,
                'payload' => $workLog,
            ]);

            return $position;
        });
    }

    /**
     * Stored parts for a batch of messages, keyed by message_id and ordered by position.
     *
     * @param  list<string>|array<array-key, string>  $messageIds
     * @return array<string, list<array{kind: string, toolName: ?string, toolCallId: ?string, payload: array<string, mixed>}>>
     */
    public function forMessages(array $messageIds): array
    {
        $result = [];
        foreach ($messageIds as $messageId) {
            $result[(string) $messageId] = [];
        }

        if ($result === []) {
            return [];
        }

        /** @var Collection<int, AiMessagePart> $parts */
        $parts = AiMessagePart::query()
            ->whereIn('message_id', array_keys($result))
            ->orderBy('position')
            ->get();

        foreach ($parts as $part) {
            $result[(string) $part->message_id][] = [
                'kind' => $part->kind,
                'toolName' => $part->tool_name,
                'toolCallId' => $part->tool_call_id,
                'payload' => $part->payload,
            ];
        }

        return $result;
    }
}
