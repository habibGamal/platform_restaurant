<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Chat;

use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\AssistantStreamEvents;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\RunLog;
use App\Models\AiRunEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\TestCase;

final class ToolOutputCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowlisted_tool_result_from_specialist_is_logged_as_tool_output(): void
    {
        $mapping = new AssistantStreamEvents([], Channel::Chat);

        $callId = 'call_rank_123';
        $input = ['preset' => 'last_month', 'branch_ids' => [1, 2]];
        $envelope = [
            'summary' => 'أفضل الأصناف من حيث الربحية',
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
            'metrics' => [
                'ranked_items' => [
                    ['name' => 'Burger', 'margin' => 120.5],
                ],
            ],
        ];

        $toolCallEvent = new ToolCallEvent('ev_1', new ToolCall($callId, 'rank_items', $input), time());
        $toolResultEvent = new ToolResultEvent(
            'ev_2',
            new ToolResult($callId, 'rank_items', $input, (string) json_encode($envelope)),
            true,
            null,
            time(),
        );

        $callMapped = $mapping->map($toolCallEvent, 'marketing_specialist');
        $resultMapped = $mapping->map($toolResultEvent, 'marketing_specialist');

        $allEvents = array_merge($callMapped, $resultMapped);
        $outputEvents = array_values(array_filter($allEvents, fn (AssistantEvent $e): bool => $e->type === 'tool_output'));

        $this->assertCount(1, $outputEvents);
        $event = $outputEvents[0];
        $this->assertSame('rank_items', $event->toolName);
        $this->assertSame($callId, $event->toolCallId);
        $this->assertSame($input, $event->input);
        $this->assertSame($envelope, $event->output);
        $this->assertSame('marketing', $event->agent);
    }

    public function test_non_allowlisted_tool_result_is_not_logged(): void
    {
        $mapping = new AssistantStreamEvents([], Channel::Chat);

        $callId = 'call_kpi_123';
        $input = ['kpis' => ['K01']];
        $resultData = ['summary' => 'مؤشرات', 'kpis' => ['K01' => 50000]];

        $toolCallEvent = new ToolCallEvent('ev_1', new ToolCall($callId, 'get_kpis', $input), time());
        $toolResultEvent = new ToolResultEvent(
            'ev_2',
            new ToolResult($callId, 'get_kpis', $input, (string) json_encode($resultData)),
            true,
            null,
            time(),
        );

        $callMapped = $mapping->map($toolCallEvent);
        $resultMapped = $mapping->map($toolResultEvent);

        $allEvents = array_merge($callMapped, $resultMapped);
        $outputEvents = array_values(array_filter($allEvents, fn (AssistantEvent $e): bool => $e->type === 'tool_output'));

        $this->assertEmpty($outputEvents);
    }

    public function test_coordinator_level_allowlisted_tool_is_logged(): void
    {
        $mapping = new AssistantStreamEvents([], Channel::Chat);

        $callId = 'call_series_999';
        $input = ['kpis' => ['K01', 'K06'], 'per_branch' => false];
        $envelope = [
            'summary' => 'سلسلة المؤشرات',
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
            'metrics' => [
                'series' => [
                    ['date' => '2026-10-01', 'K01' => 1500],
                ],
            ],
        ];

        $toolCallEvent = new ToolCallEvent('ev_1', new ToolCall($callId, 'get_kpi_series', $input), time());
        $toolResultEvent = new ToolResultEvent(
            'ev_2',
            new ToolResult($callId, 'get_kpi_series', $input, (string) json_encode($envelope)),
            true,
            null,
            time(),
        );

        $callMapped = $mapping->map($toolCallEvent);
        $resultMapped = $mapping->map($toolResultEvent);

        $allEvents = array_merge($callMapped, $resultMapped);
        $outputEvents = array_values(array_filter($allEvents, fn (AssistantEvent $e): bool => $e->type === 'tool_output'));

        $this->assertCount(1, $outputEvents);
        $event = $outputEvents[0];
        $this->assertSame('get_kpi_series', $event->toolName);
        $this->assertSame($callId, $event->toolCallId);
        $this->assertSame($input, $event->input);
        $this->assertSame($envelope, $event->output);
        $this->assertNull($event->agent);
    }

    public function test_whatsapp_channel_never_emits_tool_output(): void
    {
        $mapping = new AssistantStreamEvents([], Channel::WhatsApp);

        $callId = 'call_wa_1';
        $input = ['preset' => 'last_month'];
        $envelope = [
            'summary' => 'أفضل الأصناف',
            'items' => [],
        ];

        $toolCallEvent = new ToolCallEvent('ev_1', new ToolCall($callId, 'rank_items', $input), time());
        $toolResultEvent = new ToolResultEvent(
            'ev_2',
            new ToolResult($callId, 'rank_items', $input, (string) json_encode($envelope)),
            true,
            null,
            time(),
        );

        $callMapped = $mapping->map($toolCallEvent, 'marketing_specialist');
        $resultMapped = $mapping->map($toolResultEvent, 'marketing_specialist');

        $allEvents = array_merge($callMapped, $resultMapped);
        $outputEvents = array_values(array_filter($allEvents, fn (AssistantEvent $e): bool => $e->type === 'tool_output'));

        $this->assertEmpty($outputEvents);
    }

    public function test_preliminary_tool_result_is_skipped(): void
    {
        $mapping = new AssistantStreamEvents([], Channel::Chat);

        $callId = 'call_prelim_1';
        $input = ['preset' => 'last_month'];
        $envelope = ['summary' => 'قيد المعالجة'];

        $toolCallEvent = new ToolCallEvent('ev_1', new ToolCall($callId, 'rank_items', $input), time());
        $toolResultEvent = new ToolResultEvent(
            'ev_2',
            new ToolResult($callId, 'rank_items', $input, (string) json_encode($envelope)),
            true,
            null,
            time(),
            preliminary: true,
        );

        $callMapped = $mapping->map($toolCallEvent, 'marketing_specialist');
        $resultMapped = $mapping->map($toolResultEvent, 'marketing_specialist');

        $allEvents = array_merge($callMapped, $resultMapped);
        $outputEvents = array_values(array_filter($allEvents, fn (AssistantEvent $e): bool => $e->type === 'tool_output'));

        $this->assertEmpty($outputEvents);
    }

    public function test_failed_tool_result_logs_error_output(): void
    {
        $mapping = new AssistantStreamEvents([], Channel::Chat);

        $callId = 'call_fail_1';
        $input = ['preset' => 'last_month'];

        $toolCallEvent = new ToolCallEvent('ev_1', new ToolCall($callId, 'rank_items', $input), time());
        $toolResultEvent = new ToolResultEvent(
            'ev_2',
            new ToolResult($callId, 'rank_items', $input, 'Database connection timed out'),
            false,
            'Database connection timed out',
            time(),
        );

        $callMapped = $mapping->map($toolCallEvent, 'marketing_specialist');
        $resultMapped = $mapping->map($toolResultEvent, 'marketing_specialist');

        $allEvents = array_merge($callMapped, $resultMapped);
        $outputEvents = array_values(array_filter($allEvents, fn (AssistantEvent $e): bool => $e->type === 'tool_output'));

        $this->assertCount(1, $outputEvents);
        $event = $outputEvents[0];
        $this->assertSame('rank_items', $event->toolName);
        $this->assertSame($callId, $event->toolCallId);
        $this->assertSame($input, $event->input);
        $this->assertSame(['error' => 'Database connection timed out'], $event->output);
        $this->assertSame('marketing', $event->agent);
    }

    public function test_failed_tool_result_without_message_uses_arabic_fallback(): void
    {
        $mapping = new AssistantStreamEvents([], Channel::Chat);

        $callId = 'call_fail_empty';
        $input = ['preset' => 'last_month'];

        $toolCallEvent = new ToolCallEvent('ev_1', new ToolCall($callId, 'rank_items', $input), time());
        $toolResultEvent = new ToolResultEvent(
            'ev_2',
            new ToolResult($callId, 'rank_items', $input, ''),
            false,
            null,
            time(),
        );

        $callMapped = $mapping->map($toolCallEvent, 'marketing_specialist');
        $resultMapped = $mapping->map($toolResultEvent, 'marketing_specialist');

        $allEvents = array_merge($callMapped, $resultMapped);
        $outputEvents = array_values(array_filter($allEvents, fn (AssistantEvent $e): bool => $e->type === 'tool_output'));

        $this->assertCount(1, $outputEvents);
        $this->assertSame(['error' => 'الأداة مقدرتش ترجّع نتيجة.'], $outputEvents[0]->output);
    }

    public function test_non_json_tool_result_is_logged_as_raw(): void
    {
        $mapping = new AssistantStreamEvents([], Channel::Chat);

        $callId = 'call_raw_1';
        $input = ['item_id' => 42];

        $toolCallEvent = new ToolCallEvent('ev_1', new ToolCall($callId, 'get_item_card', $input), time());
        $toolResultEvent = new ToolResultEvent(
            'ev_2',
            new ToolResult($callId, 'get_item_card', $input, 'plain text result without json'),
            true,
            null,
            time(),
        );

        $callMapped = $mapping->map($toolCallEvent, 'marketing_specialist');
        $resultMapped = $mapping->map($toolResultEvent, 'marketing_specialist');

        $allEvents = array_merge($callMapped, $resultMapped);
        $outputEvents = array_values(array_filter($allEvents, fn (AssistantEvent $e): bool => $e->type === 'tool_output'));

        $this->assertCount(1, $outputEvents);
        $event = $outputEvents[0];
        $this->assertSame('get_item_card', $event->toolName);
        $this->assertSame($callId, $event->toolCallId);
        $this->assertSame($input, $event->input);
        $this->assertSame(['raw' => 'plain text result without json'], $event->output);
        $this->assertSame('marketing', $event->agent);
    }

    public function test_run_log_payload_includes_tool_output_fields_and_omits_null_agent(): void
    {
        $user = User::factory()->create();
        $runLog = app(RunLog::class);
        $runId = (string) Str::uuid();

        // 1. Coordinator level toolOutput (agent = null)
        $coordEvent = AssistantEvent::toolOutput(
            'get_kpi_series',
            'call_series_coord',
            ['kpis' => ['K01']],
            ['summary' => 'kpi summary'],
            null,
        );
        $runLog->append($runId, $user->id, $coordEvent);

        $row1 = AiRunEvent::query()->where('run_id', $runId)->where('seq', 1)->firstOrFail();
        $this->assertSame('tool_output', $row1->type);
        $this->assertSame('get_kpi_series', $row1->payload['toolName']);
        $this->assertSame('call_series_coord', $row1->payload['toolCallId']);
        $this->assertSame(['kpis' => ['K01']], $row1->payload['input']);
        $this->assertSame(['summary' => 'kpi summary'], $row1->payload['output']);
        $this->assertArrayNotHasKey('agent', $row1->payload);

        // 2. Specialist toolOutput (agent = 'marketing')
        $specialistEvent = AssistantEvent::toolOutput(
            'rank_items',
            'call_rank_spec',
            ['preset' => 'last_month'],
            ['summary' => 'rank summary'],
            'marketing',
        );
        $runLog->append($runId, $user->id, $specialistEvent);

        $row2 = AiRunEvent::query()->where('run_id', $runId)->where('seq', 2)->firstOrFail();
        $this->assertSame('tool_output', $row2->type);
        $this->assertSame('rank_items', $row2->payload['toolName']);
        $this->assertSame('call_rank_spec', $row2->payload['toolCallId']);
        $this->assertSame(['preset' => 'last_month'], $row2->payload['input']);
        $this->assertSame(['summary' => 'rank summary'], $row2->payload['output']);
        $this->assertSame('marketing', $row2->payload['agent']);
    }
}
