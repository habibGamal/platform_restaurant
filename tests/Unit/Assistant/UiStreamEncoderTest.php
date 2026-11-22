<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Domain\Assistant\Ui\UiStreamEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UiStreamEncoderTest extends TestCase
{
    /**
     * @param  array{seq: int, type: string, payload: array<string, mixed>}  $row
     * @param  list<array<string, mixed>>  $expectedParts
     */
    #[DataProvider('mappingRowsProvider')]
    public function test_mapping_table_rows(array $row, array $expectedParts, bool $startStreamFirst = false): void
    {
        $encoder = new UiStreamEncoder('run-123');

        if ($startStreamFirst) {
            // Prime the encoder with an initial event to simulate an already-started stream.
            $encoder->encode(['seq' => 0, 'type' => 'plan', 'payload' => ['text' => 'خطة أولية']]);
        }

        $parts = $encoder->encode($row);

        $this->assertSame($expectedParts, $parts);
    }

    /**
     * @return array<string, array{
     *     row: array{seq: int, type: string, payload: array<string, mixed>},
     *     expectedParts: list<array<string, mixed>>,
     *     startStreamFirst?: bool
     * }>
     */
    public static function mappingRowsProvider(): array
    {
        return [
            'first event' => [
                'row' => [
                    'seq' => 1,
                    'type' => 'text_delta',
                    'payload' => ['text' => 'مرحبا'],
                ],
                'expectedParts' => [
                    ['type' => 'start', 'messageId' => 'run-run-123'],
                    ['type' => 'text-start', 'id' => 't-run-123'],
                    ['type' => 'text-delta', 'id' => 't-run-123', 'delta' => 'مرحبا'],
                ],
                'startStreamFirst' => false,
            ],
            'text_delta subsequent' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'text_delta',
                    'payload' => ['text' => ' بكم'],
                ],
                'expectedParts' => [
                    ['type' => 'text-start', 'id' => 't-run-123'],
                    ['type' => 'text-delta', 'id' => 't-run-123', 'delta' => ' بكم'],
                ],
                'startStreamFirst' => true,
            ],
            'plan' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'plan',
                    'payload' => ['text' => 'تحليل مبيعات الأسبوع'],
                ],
                'expectedParts' => [
                    [
                        'type' => 'data-worklog',
                        'id' => 'worklog',
                        'data' => [
                            'plan' => 'تحليل مبيعات الأسبوع',
                            'steps' => [],
                        ],
                    ],
                ],
                'startStreamFirst' => true,
            ],
            'agent_started' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'agent_started',
                    'payload' => ['agent' => 'marketing', 'text' => 'التسويق والعروض'],
                ],
                'expectedParts' => [
                    [
                        'type' => 'data-worklog',
                        'id' => 'worklog',
                        'data' => [
                            'plan' => 'خطة أولية',
                            'steps' => [
                                [
                                    'agent' => 'marketing',
                                    'label' => 'التسويق والعروض',
                                    'status' => 'running',
                                ],
                            ],
                        ],
                    ],
                ],
                'startStreamFirst' => true,
            ],
            'step_started' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'step_started',
                    'payload' => ['agent' => 'finance', 'text' => 'حساب التكاليف…'],
                ],
                'expectedParts' => [
                    [
                        'type' => 'data-worklog',
                        'id' => 'worklog',
                        'data' => [
                            'plan' => 'خطة أولية',
                            'steps' => [
                                [
                                    'agent' => 'finance',
                                    'label' => 'المالية',
                                    'status' => 'running',
                                ],
                            ],
                        ],
                    ],
                ],
                'startStreamFirst' => true,
            ],
            'step_finished' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'step_finished',
                    'payload' => ['agent' => 'operations', 'text' => 'تم فحص أداء الفروع'],
                ],
                'expectedParts' => [
                    [
                        'type' => 'data-worklog',
                        'id' => 'worklog',
                        'data' => [
                            'plan' => 'خطة أولية',
                            'steps' => [
                                [
                                    'agent' => 'operations',
                                    'label' => 'التشغيل',
                                    'status' => 'running',
                                    'summary' => 'تم فحص أداء الفروع',
                                ],
                            ],
                        ],
                    ],
                ],
                'startStreamFirst' => true,
            ],
            'agent_finished' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'agent_finished',
                    'payload' => ['agent' => 'data_checker', 'failed' => false],
                ],
                'expectedParts' => [
                    [
                        'type' => 'data-worklog',
                        'id' => 'worklog',
                        'data' => [
                            'plan' => 'خطة أولية',
                            'steps' => [
                                [
                                    'agent' => 'data_checker',
                                    'label' => 'مدقق البيانات',
                                    'status' => 'done',
                                ],
                            ],
                        ],
                    ],
                ],
                'startStreamFirst' => true,
            ],
            'agent_finished failed' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'agent_finished',
                    'payload' => ['agent' => 'marketing', 'failed' => true],
                ],
                'expectedParts' => [
                    [
                        'type' => 'data-worklog',
                        'id' => 'worklog',
                        'data' => [
                            'plan' => 'خطة أولية',
                            'steps' => [
                                [
                                    'agent' => 'marketing',
                                    'label' => 'التسويق',
                                    'status' => 'failed',
                                ],
                            ],
                        ],
                    ],
                ],
                'startStreamFirst' => true,
            ],
            'tool_call' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'tool_call',
                    'payload' => ['toolName' => 'rank_items'],
                ],
                'expectedParts' => [
                    [
                        'type' => 'data-worklog',
                        'id' => 'worklog',
                        'data' => [
                            'plan' => 'خطة أولية',
                            'steps' => [],
                        ],
                    ],
                ],
                'startStreamFirst' => true,
            ],
            'tool_result' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'tool_result',
                    'payload' => ['toolName' => 'rank_items'],
                ],
                'expectedParts' => [
                    [
                        'type' => 'data-worklog',
                        'id' => 'worklog',
                        'data' => [
                            'plan' => 'خطة أولية',
                            'steps' => [],
                        ],
                    ],
                ],
                'startStreamFirst' => true,
            ],
            'tool_output' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'tool_output',
                    'payload' => [
                        'toolName' => 'rank_items',
                        'toolCallId' => 'call-456',
                        'input' => ['period' => '30d'],
                        'output' => ['items' => ['Burger', 'Fries']],
                    ],
                ],
                'expectedParts' => [
                    [
                        'type' => 'tool-input-available',
                        'toolCallId' => 'call-456',
                        'toolName' => 'rank_items',
                        'input' => ['period' => '30d'],
                    ],
                    [
                        'type' => 'tool-output-available',
                        'toolCallId' => 'call-456',
                        'output' => ['items' => ['Burger', 'Fries']],
                    ],
                ],
                'startStreamFirst' => true,
            ],
            'tool_output with output.error' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'tool_output',
                    'payload' => [
                        'toolName' => 'rank_items',
                        'toolCallId' => 'call-456',
                        'input' => ['period' => '30d'],
                        'output' => ['error' => 'Database timeout occurred'],
                    ],
                ],
                'expectedParts' => [
                    [
                        'type' => 'tool-input-available',
                        'toolCallId' => 'call-456',
                        'toolName' => 'rank_items',
                        'input' => ['period' => '30d'],
                    ],
                    [
                        'type' => 'tool-output-error',
                        'toolCallId' => 'call-456',
                        'errorText' => 'Database timeout occurred',
                    ],
                ],
                'startStreamFirst' => true,
            ],
            'done without text open' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'done',
                    'payload' => [
                        'conversationId' => 'conv-abc',
                        'messageId' => 'msg-xyz',
                    ],
                ],
                'expectedParts' => [
                    [
                        'type' => 'finish',
                        'messageMetadata' => [
                            'conversationId' => 'conv-abc',
                            'messageId' => 'msg-xyz',
                        ],
                    ],
                ],
                'startStreamFirst' => true,
            ],
            'error' => [
                'row' => [
                    'seq' => 2,
                    'type' => 'error',
                    'payload' => [
                        'text' => 'تم تجاوز حد الاستخدام المسموح به.',
                        'errorCode' => 'rate_limited',
                    ],
                ],
                'expectedParts' => [
                    [
                        'type' => 'error',
                        'errorText' => 'تم تجاوز حد الاستخدام المسموح به.',
                    ],
                    [
                        'type' => 'finish',
                    ],
                ],
                'startStreamFirst' => true,
            ],
        ];
    }

    public function test_status_queue_wait(): void
    {
        $encoder = new UiStreamEncoder('run-123');

        $parts = $encoder->status('queue_wait');

        $this->assertSame([
            [
                'type' => 'data-status',
                'id' => 'status',
                'data' => [
                    'kind' => 'queue_wait',
                    'text' => 'لسه مستني دوره…',
                ],
                'transient' => true,
            ],
        ], $parts);
    }

    public function test_malformed_tool_output_is_passed_through_as_error_part(): void
    {
        $encoder = new UiStreamEncoder('run-123');
        $encoder->encode(['seq' => 1, 'type' => 'plan', 'payload' => ['text' => 'خطة']]);

        // Case 1: output contains an error string in array
        $parts1 = $encoder->encode([
            'seq' => 2,
            'type' => 'tool_output',
            'payload' => [
                'toolName' => 'get_combo_candidates',
                'toolCallId' => 'call-1',
                'input' => [],
                'output' => ['error' => 'تعذر حساب الكومبو'],
            ],
        ]);

        $this->assertSame([
            [
                'type' => 'tool-input-available',
                'toolCallId' => 'call-1',
                'toolName' => 'get_combo_candidates',
                'input' => [],
            ],
            [
                'type' => 'tool-output-error',
                'toolCallId' => 'call-1',
                'errorText' => 'تعذر حساب الكومبو',
            ],
        ], $parts1);

        // Case 2: output is a raw string instead of a decoded JSON envelope
        $parts2 = $encoder->encode([
            'seq' => 3,
            'type' => 'tool_output',
            'payload' => [
                'toolName' => 'get_combo_candidates',
                'toolCallId' => 'call-2',
                'input' => [],
                'output' => 'Malformed string error payload',
            ],
        ]);

        $this->assertSame([
            [
                'type' => 'tool-input-available',
                'toolCallId' => 'call-2',
                'toolName' => 'get_combo_candidates',
                'input' => [],
            ],
            [
                'type' => 'tool-output-error',
                'toolCallId' => 'call-2',
                'errorText' => 'Malformed string error payload',
            ],
        ], $parts2);

        // Case 3: output is null or unexpected structure
        $parts3 = $encoder->encode([
            'seq' => 4,
            'type' => 'tool_output',
            'payload' => [
                'toolName' => 'get_combo_candidates',
                'toolCallId' => 'call-3',
                'input' => [],
                'output' => null,
            ],
        ]);

        $this->assertSame([
            [
                'type' => 'tool-input-available',
                'toolCallId' => 'call-3',
                'toolName' => 'get_combo_candidates',
                'input' => [],
            ],
            [
                'type' => 'tool-output-error',
                'toolCallId' => 'call-3',
                'errorText' => 'الأداة مقدرتش ترجّع نتيجة.',
            ],
        ], $parts3);
    }

    public function test_text_is_closed_before_finish(): void
    {
        // 1. Text closed on done
        $encoder1 = new UiStreamEncoder('run-1');
        $encoder1->encode(['seq' => 1, 'type' => 'text_delta', 'payload' => ['text' => 'مرحبا']]);
        $doneParts = $encoder1->encode([
            'seq' => 2,
            'type' => 'done',
            'payload' => ['conversationId' => 'c1', 'messageId' => 'm1'],
        ]);

        $this->assertSame([
            ['type' => 'text-end', 'id' => 't-run-1'],
            [
                'type' => 'finish',
                'messageMetadata' => ['conversationId' => 'c1', 'messageId' => 'm1'],
            ],
        ], $doneParts);

        // 2. Text closed on direct finish() call
        $encoder2 = new UiStreamEncoder('run-2');
        $encoder2->encode(['seq' => 1, 'type' => 'text_delta', 'payload' => ['text' => 'أهلا']]);
        $finishParts = $encoder2->finish();

        $this->assertSame([
            ['type' => 'text-end', 'id' => 't-run-2'],
            ['type' => 'finish'],
        ], $finishParts);

        // 3. Text closed on error event
        $encoder3 = new UiStreamEncoder('run-3');
        $encoder3->encode(['seq' => 1, 'type' => 'text_delta', 'payload' => ['text' => 'بداية نص']]);
        $errorParts = $encoder3->encode([
            'seq' => 2,
            'type' => 'error',
            'payload' => ['text' => 'انقطع الاتصال'],
        ]);

        $this->assertSame([
            ['type' => 'text-end', 'id' => 't-run-3'],
            ['type' => 'error', 'errorText' => 'انقطع الاتصال'],
            ['type' => 'finish'],
        ], $errorParts);
    }

    public function test_worklog_part_keeps_stable_id_across_updates(): void
    {
        $encoder = new UiStreamEncoder('run-stable');

        // Initial agent started
        $p1 = $encoder->encode([
            'seq' => 1,
            'type' => 'agent_started',
            'payload' => ['agent' => 'marketing', 'text' => 'التسويق'],
        ]);
        // Strip start part for the first event
        $this->assertSame(['type' => 'start', 'messageId' => 'run-run-stable'], $p1[0]);
        $this->assertSame('data-worklog', $p1[1]['type']);
        $this->assertSame('worklog', $p1[1]['id']);
        $this->assertCount(1, $p1[1]['data']['steps']);
        $this->assertSame('running', $p1[1]['data']['steps'][0]['status']);

        // Step started
        $p2 = $encoder->encode([
            'seq' => 2,
            'type' => 'step_started',
            'payload' => ['agent' => 'marketing', 'text' => 'فحص أفضل الأصناف'],
        ]);
        $this->assertCount(1, $p2);
        $this->assertSame('worklog', $p2[0]['id']);
        $this->assertSame('running', $p2[0]['data']['steps'][0]['status']);

        // Step finished with summary
        $p3 = $encoder->encode([
            'seq' => 3,
            'type' => 'step_finished',
            'payload' => ['agent' => 'marketing', 'text' => 'تم استخراج 5 أصناف'],
        ]);
        $this->assertCount(1, $p3);
        $this->assertSame('worklog', $p3[0]['id']);
        $this->assertSame('تم استخراج 5 أصناف', $p3[0]['data']['steps'][0]['summary']);

        // Another agent starts
        $p4 = $encoder->encode([
            'seq' => 4,
            'type' => 'agent_started',
            'payload' => ['agent' => 'finance'],
        ]);
        $this->assertCount(1, $p4);
        $this->assertSame('worklog', $p4[0]['id']);
        $this->assertCount(2, $p4[0]['data']['steps']);
        $this->assertSame('المالية', $p4[0]['data']['steps'][1]['label']);

        // Marketing finishes
        $p5 = $encoder->encode([
            'seq' => 5,
            'type' => 'agent_finished',
            'payload' => ['agent' => 'marketing', 'failed' => false],
        ]);
        $this->assertCount(1, $p5);
        $this->assertSame('worklog', $p5[0]['id']);
        $this->assertSame('done', $p5[0]['data']['steps'][0]['status']);
        $this->assertSame('تم استخراج 5 أصناف', $p5[0]['data']['steps'][0]['summary']);
        $this->assertSame('running', $p5[0]['data']['steps'][1]['status']);
    }

    public function test_done_closes_all_running_agent_steps_in_worklog(): void
    {
        $encoder = new UiStreamEncoder('run-1');
        $encoder->encode(['seq' => 1, 'type' => 'agent_started', 'payload' => ['agent' => 'finance']]);
        $encoder->encode(['seq' => 2, 'type' => 'done', 'payload' => ['conversationId' => 'c1', 'messageId' => 'm1']]);

        // When encoded done, finish part is produced.
        // If we inspect the final work log snapshot:
        $this->assertSame('done', $encoder->workLog()['steps'][0]['status']);
    }

    public function test_error_marks_running_agent_steps_as_failed_in_worklog(): void
    {
        $encoder = new UiStreamEncoder('run-1');
        $encoder->encode(['seq' => 1, 'type' => 'agent_started', 'payload' => ['agent' => 'finance']]);
        $encoder->encode(['seq' => 2, 'type' => 'error', 'payload' => ['text' => 'فشل']]);

        $this->assertSame('failed', $encoder->workLog()['steps'][0]['status']);
    }

    public function test_finish_is_idempotent(): void
    {
        $encoder = new UiStreamEncoder('run-idemp');
        $p1 = $encoder->finish();
        $this->assertNotEmpty($p1);

        $p2 = $encoder->finish();
        $this->assertSame([], $p2);
    }

    public function test_subsequent_text_delta_emits_only_text_delta(): void
    {
        $encoder = new UiStreamEncoder('run-sub');
        $first = $encoder->encode(['seq' => 1, 'type' => 'text_delta', 'payload' => ['text' => 'أول']]);
        $this->assertCount(3, $first); // start, text-start, text-delta

        $second = $encoder->encode(['seq' => 2, 'type' => 'text_delta', 'payload' => ['text' => ' ثاني']]);
        $this->assertSame([
            [
                'type' => 'text-delta',
                'id' => 't-run-sub',
                'delta' => ' ثاني',
            ],
        ], $second);
    }

    public function test_coordinator_assistant_label_is_preserved(): void
    {
        $encoder = new UiStreamEncoder('run-coord');
        $encoder->encode([
            'seq' => 1,
            'type' => 'agent_started',
            'payload' => ['agent' => 'assistant', 'text' => 'تسمية مخصصة'],
        ]);

        $this->assertSame('المساعد', $encoder->workLog()['steps'][0]['label']);
    }

    public function test_unknown_event_type_returns_empty_and_does_not_start_stream(): void
    {
        $encoder = new UiStreamEncoder('run-unk');
        $parts = $encoder->encode([
            'seq' => 1,
            'type' => 'some_custom_event',
            'payload' => ['data' => 123],
        ]);

        $this->assertSame([], $parts);

        // Next valid event should start the stream with 'start'
        $next = $encoder->encode([
            'seq' => 2,
            'type' => 'plan',
            'payload' => ['text' => 'خطة'],
        ]);

        $this->assertSame('start', $next[0]['type']);
    }
}
