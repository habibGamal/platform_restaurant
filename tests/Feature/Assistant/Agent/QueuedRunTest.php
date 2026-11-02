<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Agent;

use App\Ai\Agents\MarketingSpecialist;
use App\Ai\Agents\RestaurantAnalyst;
use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\RunLog;
use App\Enums\OrganizationPlan;
use App\Jobs\RunAssistantQuestion;
use App\Models\AiConversationMeta;
use App\Models\AiRunEvent;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\ToolCall;
use RuntimeException;
use Tests\TestCase;

final class QueuedRunTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        (new RolesAndPermissionsSeeder)->run();
        $organization = Organization::factory()->create(['plan' => OrganizationPlan::PRO, 'ai_enabled' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $user->givePermissionTo('ai.view');

        return $user;
    }

    /** @return list<string> */
    private function types(string $runId, int $userId): array
    {
        return array_column((new RunLog)->since($runId, $userId, 0), 'type');
    }

    public function test_start_resolves_the_conversation_and_dispatches_the_job(): void
    {
        Queue::fake();
        $user = $this->user();
        $runId = (string) Str::uuid();

        $conversationId = app(AssistantService::class)->start($user, Channel::Chat, null, 'راجع الأداء', $runId);

        $this->assertNotNull($conversationId);
        $this->assertTrue(AiConversationMeta::query()->where('conversation_id', $conversationId)->where('user_id', $user->id)->exists());
        Queue::assertPushed(RunAssistantQuestion::class, fn (RunAssistantQuestion $job): bool => $job->runId === $runId
            && $job->userId === $user->id
            && $job->conversationId === $conversationId
            && $job->channel === 'chat'
            && $job->text === 'راجع الأداء');
        $this->assertSame([], $this->types($runId, $user->id));
    }

    public function test_a_gate_failure_is_logged_without_dispatching(): void
    {
        Queue::fake();
        $user = $this->user();
        $user->organization->update(['ai_enabled' => false]);
        $runId = (string) Str::uuid();

        app(AssistantService::class)->start($user->refresh(), Channel::Chat, null, 'راجع الأداء', $runId);

        Queue::assertNothingPushed();
        $rows = (new RunLog)->since($runId, $user->id, 0);
        $this->assertSame(['error'], array_column($rows, 'type'));
        $this->assertSame('forbidden', $rows[0]['payload']['errorCode']);
        $this->assertTrue((new RunLog)->isFinished($runId));
    }

    public function test_running_the_job_writes_ordered_events_ending_in_done_and_links_the_message(): void
    {
        $user = $this->user();
        RestaurantAnalyst::fake([new ToolCall('kpis', 'get_workspace_context', []), 'تمت مراجعة سياق العمل.']);
        $runId = (string) Str::uuid();

        Queue::fake();
        $conversationId = app(AssistantService::class)->start($user, Channel::Chat, null, 'راجع الأداء', $runId);
        $this->assertIsString($conversationId);
        (new RunAssistantQuestion($user->id, 'chat', $conversationId, 'راجع الأداء', $runId))->handle(app(AssistantService::class), new RunLog);

        $rows = (new RunLog)->since($runId, $user->id, 0);
        $this->assertSame(range(1, count($rows)), array_column($rows, 'seq'));
        $this->assertSame(['tool_call', 'step_started', 'tool_result', 'step_finished', 'text_delta', 'done'], array_column($rows, 'type'));
        $this->assertSame('تمت مراجعة سياق العمل.', $rows[4]['payload']['text']);
        $this->assertTrue((new RunLog)->isFinished($runId));

        $messageId = $this->last($rows)['payload']['messageId'];
        $this->assertIsString($messageId);
        $this->assertSame(array_column($rows, 'seq'), array_column((new RunLog)->forMessage($messageId), 'seq'));
        $this->assertSame(count($rows), AiRunEvent::query()->where('conversation_id', $conversationId)->count());
    }

    public function test_since_returns_only_later_rows_and_nothing_for_another_user(): void
    {
        $user = $this->user();
        $other = User::factory()->create(['organization_id' => $user->organization_id]);
        $runId = (string) Str::uuid();
        $log = new RunLog;
        foreach (['a', 'b', 'c', 'd', 'e'] as $tool) {
            $log->append($runId, $user->id, AssistantEvent::toolCall($tool));
        }

        $this->assertSame([4, 5], array_column($log->since($runId, $user->id, 3), 'seq'));
        $this->assertSame(['d', 'e'], array_column(array_column($log->since($runId, $user->id, 3), 'payload'), 'toolName'));
        $this->assertSame([], $log->since($runId, $other->id, 0));
        $this->assertFalse($log->isFinished($runId));
    }

    public function test_a_poller_that_already_read_text_still_sees_every_later_delta(): void
    {
        $user = $this->user();
        $runId = (string) Str::uuid();
        $log = new RunLog;
        $chunks = [str_repeat('أ', 150), str_repeat('ب', 120), 'ج', 'د', str_repeat('ه', 210), 'و'];

        $log->append($runId, $user->id, AssistantEvent::textDelta($chunks[0]));
        $log->append($runId, $user->id, AssistantEvent::textDelta($chunks[1]));
        $firstRead = $log->since($runId, $user->id, 0);
        $this->assertCount(1, $firstRead);
        $readUpTo = $firstRead[0]['seq'];

        foreach (array_slice($chunks, 2) as $chunk) {
            $log->append($runId, $user->id, AssistantEvent::textDelta($chunk));
        }
        $log->append($runId, $user->id, AssistantEvent::done((string) Str::uuid()));

        $laterRead = $log->since($runId, $user->id, $readUpTo);
        $text = implode('', array_map(fn (array $row): string => $row['payload']['text'] ?? '', [...$firstRead, ...$laterRead]));
        $this->assertSame(implode('', $chunks), $text);
        $this->assertSame('done', $this->last($laterRead)['type']);
        $this->assertLessThan(count($chunks) + 1, AiRunEvent::query()->where('run_id', $runId)->count(), 'Small deltas are coalesced into fewer rows.');
    }

    public function test_buffered_text_is_flushed_after_a_pause(): void
    {
        $user = $this->user();
        $runId = (string) Str::uuid();
        $log = new RunLog;
        Carbon::setTestNow('2026-11-02 10:00:00.000');
        $log->append($runId, $user->id, AssistantEvent::textDelta('أ'));
        $this->assertSame([], $log->since($runId, $user->id, 0));

        Carbon::setTestNow('2026-11-02 10:00:00.300');
        $log->append($runId, $user->id, AssistantEvent::textDelta('ب'));

        $this->assertSame('أب', $log->since($runId, $user->id, 0)[0]['payload']['text']);
    }

    public function test_cancel_before_the_second_step_ends_the_log_with_cancelled(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $runId = (string) Str::uuid();
        RestaurantAnalyst::fake([function () use ($runId): ToolCall {
            app(AssistantService::class)->cancel($runId);

            return new ToolCall('ctx', 'get_workspace_context', []);
        }, 'لا يجب عرض هذه الإجابة.']);

        $this->runJob($user, $runId);

        $rows = (new RunLog)->since($runId, $user->id, 0);
        $types = array_column($rows, 'type');
        $this->assertContains('tool_result', $types);
        $this->assertNotContains('done', $types);
        $this->assertNotContains('text_delta', $types);
        $this->assertSame('error', $this->last($rows)['type']);
        $this->assertSame('cancelled', $this->last($rows)['payload']['errorCode']);
    }

    public function test_cancel_while_a_specialist_is_mid_run_ends_the_log_with_cancelled(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $runId = (string) Str::uuid();
        RestaurantAnalyst::fake([new ToolCall('delegate', 'marketing_specialist', ['task' => 'راجع الأداء']), 'لا يجب عرض هذه الإجابة.']);
        MarketingSpecialist::fake([function () use ($runId): ToolCall {
            app(AssistantService::class)->cancel($runId);

            return new ToolCall('period', 'resolve_period', []);
        }, 'لا يجب توليد هذه النتائج.']);

        $this->runJob($user, $runId);

        $rows = (new RunLog)->since($runId, $user->id, 0);
        $types = array_column($rows, 'type');
        $this->assertContains('agent_started', $types);
        $this->assertNotContains('done', $types);
        $this->assertNotContains('text_delta', $types);
        $this->assertSame('cancelled', $this->last($rows)['payload']['errorCode']);
    }

    public function test_an_uncaught_exception_appends_an_error_event(): void
    {
        $user = $this->user();
        $runId = (string) Str::uuid();

        (new RunAssistantQuestion($user->id, 'fax', (string) Str::uuid(), 'سؤال', $runId))->handle(app(AssistantService::class), new RunLog);

        $rows = (new RunLog)->since($runId, $user->id, 0);
        $this->assertSame(['error'], array_column($rows, 'type'));
        $this->assertSame('provider_error', $rows[0]['payload']['errorCode']);
        $this->assertStringNotContainsString('fax', (string) $rows[0]['payload']['text']);
    }

    public function test_an_exception_after_done_does_not_append_a_second_terminal_event(): void
    {
        $user = $this->user();
        RestaurantAnalyst::fake(['تمت المراجعة.']);
        $runId = (string) Str::uuid();

        Queue::fake();
        $conversationId = app(AssistantService::class)->start($user, Channel::Chat, null, 'راجع الأداء', $runId);
        $this->assertIsString($conversationId);

        // The stream's cleanup (touching the conversation) runs after `done` was yielded; make it throw.
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'ai_conversations_meta')) {
                throw new RuntimeException('cleanup failed');
            }
        });

        (new RunAssistantQuestion($user->id, 'chat', $conversationId, 'راجع الأداء', $runId))->handle(app(AssistantService::class), new RunLog);

        $types = $this->types($runId, $user->id);
        $this->assertSame('done', end($types));
        $this->assertNotContains('error', $types);
    }

    public function test_a_failed_job_logs_an_error_unless_the_run_already_finished(): void
    {
        $user = $this->user();
        $runId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $job = new RunAssistantQuestion($user->id, 'chat', $conversationId, 'سؤال', $runId);

        $job->failed(new RuntimeException('timeout'));
        $job->failed(new RuntimeException('timeout'));

        $this->assertSame(['error'], $this->types($runId, $user->id));
    }

    public function test_the_job_is_unique_per_run_and_never_retried(): void
    {
        Queue::fake();
        $runId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $job = new RunAssistantQuestion(1, 'chat', $conversationId, 'سؤال', $runId);

        $this->assertSame($runId, $job->uniqueId());
        $this->assertSame(960, $job->uniqueFor);
        $this->assertSame(1, $job->tries);
        $this->assertSame(900, $job->timeout);

        RunAssistantQuestion::dispatch(1, 'chat', $conversationId, 'سؤال', $runId);
        RunAssistantQuestion::dispatch(1, 'chat', $conversationId, 'سؤال', $runId);
        Queue::assertPushed(RunAssistantQuestion::class, 1);
    }

    public function test_the_database_queue_waits_longer_than_the_job_timeout_before_retrying(): void
    {
        $config = require base_path('config/queue.php');

        $this->assertGreaterThan(900, $config['connections']['database']['retry_after']);
    }

    public function test_rows_older_than_thirty_days_are_pruned(): void
    {
        $user = $this->user();
        $old = (string) Str::uuid();
        $recent = (string) Str::uuid();
        Carbon::setTestNow(now()->subDays(31));
        (new RunLog)->append($old, $user->id, AssistantEvent::toolCall('a'));
        Carbon::setTestNow();
        (new RunLog)->append($recent, $user->id, AssistantEvent::toolCall('a'));

        $this->assertSame(0, Artisan::call('model:prune', ['--model' => [AiRunEvent::class]]));

        $this->assertSame([$recent], AiRunEvent::query()->pluck('run_id')->all());
    }

    /**
     * @param  list<array{seq: int, type: string, payload: array<string, mixed>}>  $rows
     * @return array{seq: int, type: string, payload: array<string, mixed>}
     */
    private function last(array $rows): array
    {
        $this->assertNotEmpty($rows);

        return $rows[count($rows) - 1];
    }

    private function runJob(User $user, string $runId): void
    {
        Queue::fake();
        $conversationId = app(AssistantService::class)->start($user, Channel::Chat, null, 'راجع الأداء', $runId);
        $this->assertIsString($conversationId);
        (new RunAssistantQuestion($user->id, 'chat', $conversationId, 'راجع الأداء', $runId))->handle(app(AssistantService::class), new RunLog);
    }
}
