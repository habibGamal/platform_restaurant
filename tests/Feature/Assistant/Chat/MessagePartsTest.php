<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Chat;

use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\RunLog;
use App\Domain\Assistant\Ui\MessagePartsRepository;
use App\Models\AiMessagePart;
use App\Models\AiRunEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MessagePartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_done_event_persists_tool_outputs_and_worklog_in_order(): void
    {
        $user = User::factory()->create();
        $runLog = app(RunLog::class);
        $repo = app(MessagePartsRepository::class);

        $runId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $messageId = (string) Str::uuid();

        $runLog->append($runId, $user->id, AssistantEvent::plan('خطة العمل المقترحة'), $conversationId);
        $runLog->append($runId, $user->id, AssistantEvent::agentStarted('marketing', 'تحليل المبيعات'), $conversationId);
        $runLog->append($runId, $user->id, AssistantEvent::stepStarted('marketing', 'جلب أفضل الأصناف'), $conversationId);
        $runLog->append($runId, $user->id, AssistantEvent::toolOutput(
            'rank_items',
            'call_spec_1',
            ['preset' => 'last_30_days'],
            ['items' => ['Burger', 'Fries']],
            'marketing',
        ), $conversationId);
        $runLog->append($runId, $user->id, AssistantEvent::stepFinished('marketing', 'تم استرجاع الأصناف بنجاح'), $conversationId);
        $runLog->append($runId, $user->id, AssistantEvent::agentFinished('marketing', false), $conversationId);

        $runLog->append($runId, $user->id, AssistantEvent::toolOutput(
            'get_kpi_series',
            'call_coord_2',
            ['kpis' => ['K01']],
            ['points' => [100, 200]],
            null,
        ), $conversationId);

        $runLog->append($runId, $user->id, AssistantEvent::textDelta('إليك ملخص الأداء'), $conversationId);
        $runLog->append($runId, $user->id, AssistantEvent::done($conversationId, $messageId), $conversationId);

        /** @var Collection<int, AiMessagePart> $parts */
        $parts = AiMessagePart::query()
            ->where('message_id', $messageId)
            ->orderBy('position')
            ->get();

        $this->assertCount(3, $parts);

        // 1. First tool_output
        $part0 = $parts[0];
        $this->assertSame(0, $part0->position);
        $this->assertSame('tool', $part0->kind);
        $this->assertSame('rank_items', $part0->tool_name);
        $this->assertSame('call_spec_1', $part0->tool_call_id);
        $this->assertSame($conversationId, $part0->conversation_id);
        $this->assertSame($user->id, $part0->user_id);
        $this->assertSame('rank_items', $part0->payload['toolName']);
        $this->assertSame('call_spec_1', $part0->payload['toolCallId']);
        $this->assertSame(['preset' => 'last_30_days'], $part0->payload['input']);
        $this->assertSame(['items' => ['Burger', 'Fries']], $part0->payload['output']);
        $this->assertSame('marketing', $part0->payload['agent']);

        // 2. Second tool_output
        $part1 = $parts[1];
        $this->assertSame(1, $part1->position);
        $this->assertSame('tool', $part1->kind);
        $this->assertSame('get_kpi_series', $part1->tool_name);
        $this->assertSame('call_coord_2', $part1->tool_call_id);
        $this->assertSame($conversationId, $part1->conversation_id);
        $this->assertSame($user->id, $part1->user_id);
        $this->assertSame('get_kpi_series', $part1->payload['toolName']);
        $this->assertSame('call_coord_2', $part1->payload['toolCallId']);
        $this->assertSame(['kpis' => ['K01']], $part1->payload['input']);
        $this->assertSame(['points' => [100, 200]], $part1->payload['output']);
        $this->assertArrayNotHasKey('agent', $part1->payload);

        // 3. Folded worklog snapshot
        $part2 = $parts[2];
        $this->assertSame(2, $part2->position);
        $this->assertSame('worklog', $part2->kind);
        $this->assertNull($part2->tool_name);
        $this->assertNull($part2->tool_call_id);
        $this->assertSame($conversationId, $part2->conversation_id);
        $this->assertSame($user->id, $part2->user_id);
        $this->assertSame('خطة العمل المقترحة', $part2->payload['plan']);
        $this->assertCount(1, $part2->payload['agents']);
        $this->assertSame('marketing', $part2->payload['agents'][0]['agent']);
        $this->assertSame('done', $part2->payload['agents'][0]['status']);
        $this->assertSame(['تم استرجاع الأصناف بنجاح'], $part2->payload['agents'][0]['findings']);

        // Check forMessages returns the expected shape
        $forMessages = $repo->forMessages([$messageId]);
        $this->assertArrayHasKey($messageId, $forMessages);
        $this->assertCount(3, $forMessages[$messageId]);
        $this->assertSame('tool', $forMessages[$messageId][0]['kind']);
        $this->assertSame('rank_items', $forMessages[$messageId][0]['toolName']);
        $this->assertSame('call_spec_1', $forMessages[$messageId][0]['toolCallId']);
        $this->assertSame($part0->payload, $forMessages[$messageId][0]['payload']);

        $this->assertSame('tool', $forMessages[$messageId][1]['kind']);
        $this->assertSame('get_kpi_series', $forMessages[$messageId][1]['toolName']);
        $this->assertSame('call_coord_2', $forMessages[$messageId][1]['toolCallId']);
        $this->assertSame($part1->payload, $forMessages[$messageId][1]['payload']);

        $this->assertSame('worklog', $forMessages[$messageId][2]['kind']);
        $this->assertNull($forMessages[$messageId][2]['toolName']);
        $this->assertNull($forMessages[$messageId][2]['toolCallId']);
        $this->assertSame($part2->payload, $forMessages[$messageId][2]['payload']);
    }

    public function test_parts_survive_run_event_pruning(): void
    {
        $user = User::factory()->create();
        $runLog = app(RunLog::class);
        $repo = app(MessagePartsRepository::class);

        $runId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $messageId = (string) Str::uuid();

        $runLog->append($runId, $user->id, AssistantEvent::toolOutput(
            'rank_items',
            'call_rank_prune',
            ['preset' => 'current_month'],
            ['items' => ['Pizza']],
            'marketing',
        ), $conversationId);
        $runLog->append($runId, $user->id, AssistantEvent::done($conversationId, $messageId), $conversationId);

        $this->assertSame(2, AiMessagePart::query()->where('message_id', $messageId)->count());
        $this->assertGreaterThan(0, AiRunEvent::query()->where('run_id', $runId)->count());

        // Prune the run events
        AiRunEvent::query()->where('run_id', $runId)->delete();
        $this->assertSame(0, AiRunEvent::query()->where('run_id', $runId)->count());

        // Parts are still fully readable through the repository
        $forMessages = $repo->forMessages([$messageId]);
        $this->assertArrayHasKey($messageId, $forMessages);
        $this->assertCount(2, $forMessages[$messageId]);
        $this->assertSame('tool', $forMessages[$messageId][0]['kind']);
        $this->assertSame('rank_items', $forMessages[$messageId][0]['toolName']);
        $this->assertSame('call_rank_prune', $forMessages[$messageId][0]['toolCallId']);
        $this->assertSame('worklog', $forMessages[$messageId][1]['kind']);
    }

    public function test_run_without_message_id_persists_nothing(): void
    {
        $user = User::factory()->create();
        $runLog = app(RunLog::class);

        $runId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();

        $runLog->append($runId, $user->id, AssistantEvent::plan('خطة بلا رسالة'), $conversationId);
        $runLog->append($runId, $user->id, AssistantEvent::toolOutput(
            'rank_items',
            'call_no_msg',
            [],
            ['items' => []],
        ), $conversationId);
        // done event with null messageId
        $runLog->append($runId, $user->id, AssistantEvent::done($conversationId, null), $conversationId);

        $this->assertSame(0, AiMessagePart::query()->count());
    }

    public function test_rerunning_persist_is_idempotent(): void
    {
        $user = User::factory()->create();
        $runLog = app(RunLog::class);
        $repo = app(MessagePartsRepository::class);

        $runId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $messageId = (string) Str::uuid();

        $runLog->append($runId, $user->id, AssistantEvent::plan('خطة متكررة'), $conversationId);
        $runLog->append($runId, $user->id, AssistantEvent::toolOutput(
            'rank_items',
            'call_repeat',
            ['preset' => 'today'],
            ['items' => ['Shawarma']],
        ), $conversationId);

        // Explicitly call persistRun directly twice
        $count1 = $repo->persistRun($runId, $messageId);
        $count2 = $repo->persistRun($runId, $messageId);

        $this->assertSame(2, $count1);
        $this->assertSame(2, $count2);

        $parts = AiMessagePart::query()->where('message_id', $messageId)->orderBy('position')->get();
        $this->assertCount(2, $parts);
        $this->assertSame([0, 1], $parts->pluck('position')->all());
        $this->assertSame(['tool', 'worklog'], $parts->pluck('kind')->all());
    }

    public function test_for_messages_with_unknown_ids_returns_empty_lists(): void
    {
        $repo = app(MessagePartsRepository::class);
        $result = $repo->forMessages(['missing_msg_1', 'missing_msg_2']);

        $this->assertSame([
            'missing_msg_1' => [],
            'missing_msg_2' => [],
        ], $result);
    }
}
