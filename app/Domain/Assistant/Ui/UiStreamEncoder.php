<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Ui;

/**
 * Encodes stored run-log rows ({seq, type, payload}) into Vercel AI SDK UI message stream v1 parts.
 *
 * Pure PHP class with no database access. Stateful per assistant run stream.
 */
final class UiStreamEncoder
{
    private const array AGENT_LABELS = [
        'assistant' => 'المساعد',
        'marketing' => 'التسويق',
        'finance' => 'المالية',
        'operations' => 'التشغيل',
        'data_checker' => 'مدقق البيانات',
    ];

    private bool $started = false;

    private bool $textStarted = false;

    private bool $textOpen = false;

    private bool $finished = false;

    private ?string $conversationId = null;

    private ?string $messageId = null;

    private ?string $plan = null;

    /**
     * @var list<array{
     *     agent: string,
     *     label: string,
     *     status: 'running'|'done'|'failed',
     *     summary?: string
     * }>
     */
    private array $steps = [];

    public function __construct(private readonly string $runId) {}

    /**
     * Encode one run-log row into UI stream parts.
     *
     * @param  array{seq: int, type: string, payload: array<string, mixed>}  $row
     * @return list<array<string, mixed>>
     */
    public function encode(array $row): array
    {
        $type = $row['type'];
        $payload = $row['payload'];

        if (isset($payload['conversationId']) && is_string($payload['conversationId'])) {
            $this->conversationId = $payload['conversationId'];
        }
        if (isset($payload['messageId']) && is_string($payload['messageId'])) {
            $this->messageId = $payload['messageId'];
        }

        $parts = match ($type) {
            'text_delta' => $this->encodeTextDelta($payload),
            'plan', 'agent_started', 'step_started', 'step_finished', 'agent_finished', 'tool_call', 'tool_result' => $this->encodeWorkLogEvent($type, $payload),
            'tool_output' => $this->encodeToolOutput($payload),
            'done' => $this->encodeDone($payload),
            'error' => $this->encodeError($payload),
            default => [],
        };

        if ($parts !== [] && ! $this->started) {
            $this->started = true;
            array_unshift($parts, [
                'type' => 'start',
                'messageId' => "run-{$this->runId}",
            ]);
        }

        return $parts;
    }

    /**
     * Terminal finish part when ending the stream.
     *
     * @return list<array<string, mixed>>
     */
    public function finish(): array
    {
        if ($this->finished) {
            return [];
        }

        $this->finished = true;
        $this->finishWorkLog('failed');

        $parts = [];

        if (! $this->started) {
            $this->started = true;
            $parts[] = [
                'type' => 'start',
                'messageId' => "run-{$this->runId}",
            ];
        }

        if ($this->textOpen) {
            $parts[] = [
                'type' => 'text-end',
                'id' => "t-{$this->runId}",
            ];
            $this->textOpen = false;
        }

        $parts[] = [
            'type' => 'finish',
        ];

        return $parts;
    }

    /**
     * Transient status parts (e.g. queue wait notice).
     *
     * @return list<array<string, mixed>>
     */
    public function status(string $kind): array
    {
        $text = match ($kind) {
            'queue_wait' => 'لسه مستني دوره…',
            default => $kind,
        };

        return [
            [
                'type' => 'data-status',
                'id' => 'status',
                'data' => [
                    'kind' => $kind,
                    'text' => $text,
                ],
                'transient' => true,
            ],
        ];
    }

    /**
     * Reconciled work log snapshot.
     *
     * @return array{
     *     plan: ?string,
     *     steps: list<array{
     *         agent: string,
     *         label: string,
     *         status: 'running'|'done'|'failed',
     *         summary?: string
     *     }>
     * }
     */
    public function workLog(): array
    {
        return [
            'plan' => $this->plan,
            'steps' => $this->steps,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function encodeTextDelta(array $payload): array
    {
        $parts = [];

        if (! $this->textStarted) {
            $this->textStarted = true;
            $this->textOpen = true;
            $parts[] = [
                'type' => 'text-start',
                'id' => "t-{$this->runId}",
            ];
        }

        $delta = (string) ($payload['delta'] ?? $payload['text'] ?? '');
        $parts[] = [
            'type' => 'text-delta',
            'id' => "t-{$this->runId}",
            'delta' => $delta,
        ];

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function encodeWorkLogEvent(string $type, array $payload): array
    {
        $this->updateWorkLog($type, $payload);

        return [
            [
                'type' => 'data-worklog',
                'id' => 'worklog',
                'data' => $this->workLog(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function encodeToolOutput(array $payload): array
    {
        $toolCallId = (string) ($payload['toolCallId'] ?? '');
        $toolName = (string) ($payload['toolName'] ?? '');
        $input = is_array($payload['input'] ?? null) ? $payload['input'] : [];
        $output = $payload['output'] ?? null;

        $inputPart = [
            'type' => 'tool-input-available',
            'toolCallId' => $toolCallId,
            'toolName' => $toolName,
            'input' => $input,
        ];

        if ($this->isErrorOutput($output)) {
            return [
                $inputPart,
                [
                    'type' => 'tool-output-error',
                    'toolCallId' => $toolCallId,
                    'errorText' => $this->extractErrorText($output),
                ],
            ];
        }

        return [
            $inputPart,
            [
                'type' => 'tool-output-available',
                'toolCallId' => $toolCallId,
                'output' => $output,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function encodeDone(array $payload): array
    {
        $parts = [];

        if ($this->textOpen) {
            $parts[] = [
                'type' => 'text-end',
                'id' => "t-{$this->runId}",
            ];
            $this->textOpen = false;
        }

        $this->finishWorkLog('done');
        $this->finished = true;

        $metadata = [
            'conversationId' => (string) ($payload['conversationId'] ?? $this->conversationId ?? ''),
            'messageId' => (string) ($payload['messageId'] ?? $this->messageId ?? ''),
        ];

        $parts[] = [
            'type' => 'finish',
            'messageMetadata' => $metadata,
        ];

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function encodeError(array $payload): array
    {
        $parts = [];

        if ($this->textOpen) {
            $parts[] = [
                'type' => 'text-end',
                'id' => "t-{$this->runId}",
            ];
            $this->textOpen = false;
        }

        $this->finishWorkLog('failed');
        $this->finished = true;

        $errorText = (string) ($payload['errorText'] ?? $payload['text'] ?? 'حدث خطأ أثناء معالجة الطلب.');

        $parts[] = [
            'type' => 'error',
            'errorText' => $errorText,
        ];
        $parts[] = [
            'type' => 'finish',
        ];

        return $parts;
    }

    /**
     * Fold run-log event into the work log steps/plan.
     *
     * @param  array<string, mixed>  $payload
     */
    private function updateWorkLog(string $type, array $payload): void
    {
        $text = is_string($payload['text'] ?? null) ? trim($payload['text']) : '';

        if ($type === 'plan') {
            if ($text !== '') {
                $this->plan = $text;
            }

            return;
        }

        $agent = is_string($payload['agent'] ?? null) ? $payload['agent'] : null;
        if ($agent === null || ! in_array($type, ['agent_started', 'step_started', 'step_finished', 'agent_finished'], true)) {
            return;
        }

        $index = null;
        foreach ($this->steps as $i => $step) {
            if ($step['agent'] === $agent) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            $this->steps[] = [
                'agent' => $agent,
                'label' => self::AGENT_LABELS[$agent] ?? $agent,
                'status' => 'running',
            ];
            $index = array_key_last($this->steps);
        }

        $row = $this->steps[$index];

        if ($type === 'agent_started') {
            $row['status'] = 'running';
            if ($agent !== 'assistant' && $text !== '') {
                $row['label'] = $text;
            }
        } elseif ($type === 'step_started') {
            $row['status'] = 'running';
        } elseif ($type === 'step_finished') {
            $summary = is_string($payload['summary'] ?? null)
                ? trim($payload['summary'])
                : (is_string($payload['text'] ?? null) ? trim($payload['text']) : '');

            if ($summary !== '') {
                $row['summary'] = $summary;
            }
        } else {
            $row['status'] = ($payload['failed'] ?? false) === true ? 'failed' : 'done';
        }

        $this->steps[$index] = $row;
    }

    /**
     * Terminal status update for all currently running steps.
     *
     * @param  'done'|'failed'  $status
     */
    private function finishWorkLog(string $status): void
    {
        foreach ($this->steps as $i => $step) {
            if ($step['status'] === 'running') {
                $this->steps[$i]['status'] = $status;
            }
        }
    }

    private function isErrorOutput(mixed $output): bool
    {
        if (! is_array($output)) {
            return true;
        }

        return isset($output['error']);
    }

    private function extractErrorText(mixed $output): string
    {
        if (is_array($output) && isset($output['error'])) {
            if (is_string($output['error'])) {
                return $output['error'];
            }
            if (is_array($output['error']) && isset($output['error']['message']) && is_string($output['error']['message'])) {
                return $output['error']['message'];
            }

            $encoded = json_encode($output['error'], JSON_UNESCAPED_UNICODE);

            return $encoded !== false ? $encoded : 'الأداة مقدرتش ترجّع نتيجة.';
        }

        if (is_string($output) && $output !== '') {
            return $output;
        }

        return 'الأداة مقدرتش ترجّع نتيجة.';
    }
}
