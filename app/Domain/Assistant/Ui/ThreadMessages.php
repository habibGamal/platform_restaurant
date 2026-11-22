<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Ui;

use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Models\ConversationMessage;

final class ThreadMessages
{
    /**
     * Convert stored messages into AI SDK UIMessage array shapes.
     *
     * @return list<array{
     *     id: string,
     *     role: 'user'|'assistant',
     *     parts: list<array<string, mixed>>
     * }>
     */
    public static function for(string $conversationId): array
    {
        /** @var Collection<int, ConversationMessage> $records */
        $records = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return [];
        }

        $messageIds = $records->map(fn (ConversationMessage $msg): string => (string) $msg->getKey())->all();
        $partsByMessage = (new MessagePartsRepository)->forMessages($messageIds);

        $result = [];

        foreach ($records as $record) {
            $msgId = (string) $record->getKey();
            $role = (string) $record->role;

            if ($role === 'user') {
                $result[] = [
                    'id' => $msgId,
                    'role' => 'user',
                    'parts' => [
                        [
                            'type' => 'text',
                            'text' => (string) ($record->content ?? ''),
                        ],
                    ],
                ];

                continue;
            }

            // Role is assistant
            $storedParts = $partsByMessage[$msgId] ?? [];
            $parts = [];

            // 1. data-worklog? (if present)
            foreach ($storedParts as $part) {
                if ($part['kind'] === 'worklog') {
                    $parts[] = [
                        'type' => 'data-worklog',
                        'id' => 'worklog',
                        'data' => self::formatWorkLogData($part['payload']),
                    ];
                    break;
                }
            }

            // 2. tool parts in position order
            foreach ($storedParts as $part) {
                if ($part['kind'] === 'tool') {
                    $toolName = (string) ($part['toolName'] ?? $part['payload']['toolName'] ?? '');
                    $toolCallId = (string) ($part['toolCallId'] ?? $part['payload']['toolCallId'] ?? '');
                    $input = is_array($part['payload']['input'] ?? null) ? $part['payload']['input'] : [];
                    $output = $part['payload']['output'] ?? null;

                    $parts[] = [
                        'type' => "tool-{$toolName}",
                        'state' => 'output-available',
                        'toolCallId' => $toolCallId,
                        'input' => $input,
                        'output' => $output,
                    ];
                }
            }

            // 3. text part: storedAnswerText(content)
            $parts[] = [
                'type' => 'text',
                'text' => AnswerText::clean((string) ($record->content ?? '')),
            ];

            $result[] = [
                'id' => $msgId,
                'role' => 'assistant',
                'parts' => $parts,
            ];
        }

        return $result;
    }

    /**
     * Convert stored worklog snapshot to the UI stream encoder / React renderer shape.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     plan: ?string,
     *     steps: list<array{
     *         agent: string,
     *         label: string,
     *         status: 'done'|'failed',
     *         summary?: string
     *     }>
     * }
     */
    private static function formatWorkLogData(array $payload): array
    {
        $plan = isset($payload['plan']) && is_string($payload['plan']) ? $payload['plan'] : null;

        $steps = [];
        $agents = isset($payload['agents']) && is_array($payload['agents']) ? $payload['agents'] : [];

        foreach ($agents as $agent) {
            if (! is_array($agent)) {
                continue;
            }

            $agentName = (string) ($agent['agent'] ?? '');
            $label = (string) ($agent['label'] ?? $agentName);

            $rawStatus = (string) ($agent['status'] ?? '');
            /** @var 'done'|'failed' $status */
            $status = in_array($rawStatus, ['done', 'failed'], true) ? $rawStatus : 'done';

            $stepData = [
                'agent' => $agentName,
                'label' => $label,
                'status' => $status,
            ];

            $lastFinding = null;
            if (isset($agent['findings']) && is_array($agent['findings'])) {
                for ($i = count($agent['findings']) - 1; $i >= 0; $i--) {
                    $finding = $agent['findings'][$i];
                    if (is_string($finding) && trim($finding) !== '') {
                        $lastFinding = trim($finding);
                        break;
                    }
                }
            }

            $stepText = isset($agent['step']) && is_string($agent['step']) && trim($agent['step']) !== ''
                ? trim($agent['step'])
                : null;

            $summary = $lastFinding ?? $stepText;
            if ($summary !== null) {
                $stepData['summary'] = $summary;
            }

            $steps[] = $stepData;
        }

        if ($steps === [] && isset($payload['steps']) && is_array($payload['steps'])) {
            foreach ($payload['steps'] as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $agentName = (string) ($step['agent'] ?? '');
                $label = (string) ($step['label'] ?? $agentName);
                $rawStatus = (string) ($step['status'] ?? '');
                /** @var 'done'|'failed' $status */
                $status = in_array($rawStatus, ['done', 'failed'], true) ? $rawStatus : 'done';

                $stepData = [
                    'agent' => $agentName,
                    'label' => $label,
                    'status' => $status,
                ];
                if (isset($step['summary']) && is_string($step['summary']) && trim($step['summary']) !== '') {
                    $stepData['summary'] = trim($step['summary']);
                }
                $steps[] = $stepData;
            }
        }

        return [
            'plan' => $plan,
            'steps' => $steps,
        ];
    }
}
