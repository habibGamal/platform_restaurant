<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Chat;

use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\Contracts\Assistant as AssistantContract;
use App\Domain\Assistant\RunLog;
use App\Domain\Assistant\Ui\RunTail;
use App\Enums\OrganizationPlan;
use App\Models\AiConversationMeta;
use App\Models\AiRunEvent;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Page\FakeAssistant;
use Tests\TestCase;

class ChatStreamTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eligibleOrg;

    private User $allowedUser;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();

        $this->eligibleOrg = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);

        $this->allowedUser = User::factory()->create([
            'organization_id' => $this->eligibleOrg->id,
        ]);
        $this->allowedUser->assignRole('org_owner');

        $this->otherUser = User::factory()->create([
            'organization_id' => $this->eligibleOrg->id,
        ]);
        $this->otherUser->assignRole('org_owner');
    }

    public function test_chat_starts_run_and_streams_encoded_parts_until_done(): void
    {
        $convId = (string) Str::uuid();
        AiConversationMeta::create([
            'conversation_id' => $convId,
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedUser->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة تجريبية',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        $fakeAssistant = new FakeAssistant([
            AssistantEvent::textDelta('إجمالي المبيعات بلغ 50,000 ج.م.'),
            AssistantEvent::done($convId),
        ]);
        $this->app->instance(AssistantContract::class, $fakeAssistant);

        $response = $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.chat'), [
                'id' => $convId,
                'messages' => [
                    ['role' => 'user', 'content' => 'ما هي مبيعات اليوم؟'],
                ],
            ]);

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Accel-Buffering', 'no');
        $response->assertHeader('x-vercel-ai-ui-message-stream', 'v1');
        $this->assertTrue($response->headers->has('x-resumable-stream-id'));

        $content = $response->streamedContent();

        $this->assertStringContainsString('text-delta', $content);
        $this->assertStringContainsString('إجمالي المبيعات بلغ 50,000 ج.م.', $content);
        $this->assertStringContainsString('"type":"finish"', $content);
        $this->assertStringContainsString("data: [DONE]\n\n", $content);

        $this->assertCount(1, $fakeAssistant->startCalls);
        $this->assertSame($convId, $fakeAssistant->startCalls[0]['conversationId']);
        $this->assertSame('ما هي مبيعات اليوم؟', $fakeAssistant->startCalls[0]['text']);
    }

    public function test_gate_error_streams_arabic_error_part(): void
    {
        $convId = (string) Str::uuid();
        AiConversationMeta::create([
            'conversation_id' => $convId,
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedUser->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة تجريبية',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        // Exhaust user's per-minute rate limit
        $rateLimitKey = "ai:rate_limit:user:{$this->allowedUser->id}";
        for ($i = 0; $i < 25; $i++) {
            RateLimiter::hit($rateLimitKey, 60);
        }

        $response = $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.chat'), [
                'id' => $convId,
                'messages' => [
                    ['role' => 'user', 'content' => 'سؤال يتجاوز الحد'],
                ],
            ]);

        $response->assertOk();
        $response->assertHeader('x-vercel-ai-ui-message-stream', 'v1');

        $content = $response->streamedContent();

        $this->assertStringContainsString('"type":"error"', $content);
        $this->assertStringContainsString('تم تجاوز الحد المسموح به من الرسائل في الدقيقة', $content);
        $this->assertStringContainsString('"type":"finish"', $content);
        $this->assertStringContainsString("data: [DONE]\n\n", $content);
    }

    public function test_second_question_while_running_is_rejected(): void
    {
        $convId = (string) Str::uuid();
        AiConversationMeta::create([
            'conversation_id' => $convId,
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedUser->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة تجريبية',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        $firstRunId = (string) Str::uuid();
        $log = new RunLog;
        $log->append($firstRunId, $this->allowedUser->id, AssistantEvent::textDelta('جاري التحليل...'), $convId);
        $log->flush($firstRunId);

        $response = $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.chat'), [
                'id' => $convId,
                'messages' => [
                    ['role' => 'user', 'content' => 'سؤال ثانٍ أثناء التشغيل'],
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJson([
            'message' => 'فيه سؤال شغال دلوقتي. استنى لما يخلص.',
        ]);

        // Finish first run, then second question should be accepted
        $log->append($firstRunId, $this->allowedUser->id, AssistantEvent::done($convId), $convId);
        $log->flush($firstRunId);

        $fakeAssistant = new FakeAssistant([
            AssistantEvent::textDelta('إجابة السؤال الثاني'),
            AssistantEvent::done($convId),
        ]);
        $this->app->instance(AssistantContract::class, $fakeAssistant);

        $secondResponse = $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.chat'), [
                'id' => $convId,
                'messages' => [
                    ['role' => 'user', 'content' => 'سؤال ثانٍ بعد الانتهاء'],
                ],
            ]);

        $secondResponse->assertOk();
    }

    public function test_second_post_during_queue_gap_with_no_rows_is_rejected_and_starts_once(): void
    {
        $convId = (string) Str::uuid();
        AiConversationMeta::create([
            'conversation_id' => $convId,
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedUser->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة تجريبية',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        // Fake assistant that does NOT write rows on start (simulating queued job not picked up yet)
        $fakeAssistant = new FakeAssistant([]);
        $this->app->instance(AssistantContract::class, $fakeAssistant);

        $firstResponse = $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.chat'), [
                'id' => $convId,
                'messages' => [
                    ['role' => 'user', 'content' => 'سؤال أول'],
                ],
            ]);

        $firstResponse->assertOk();
        $this->assertCount(1, $fakeAssistant->startCalls);

        // Ensure 0 rows exist in ai_run_events
        $this->assertSame(0, AiRunEvent::query()->where('conversation_id', $convId)->count());

        // Second POST sent immediately while first run has written NO rows yet
        $secondResponse = $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.chat'), [
                'id' => $convId,
                'messages' => [
                    ['role' => 'user', 'content' => 'سؤال ثانٍ أثناء الانتظار'],
                ],
            ]);

        $secondResponse->assertStatus(409);
        $secondResponse->assertJson([
            'message' => 'فيه سؤال شغال دلوقتي. استنى لما يخلص.',
        ]);

        // Assert start was never called a second time
        $this->assertCount(1, $fakeAssistant->startCalls);
    }

    public function test_resume_replays_same_run_without_starting_a_new_one(): void
    {
        Queue::fake();

        $convId = (string) Str::uuid();
        $runId = (string) Str::uuid();

        $log = new RunLog;
        $log->append($runId, $this->allowedUser->id, AssistantEvent::textDelta('إجابة محفوظة'), $convId);
        $log->flush($runId);
        $log->append($runId, $this->allowedUser->id, AssistantEvent::done($convId), $convId);
        $log->flush($runId);

        $response = $this->actingAs($this->allowedUser)
            ->get(route('assistant.runs.stream', ['runId' => $runId]));

        $response->assertOk();
        $response->assertHeader('x-resumable-stream-id', $runId);
        $response->assertHeader('x-vercel-ai-ui-message-stream', 'v1');

        $content = $response->streamedContent();

        $this->assertStringContainsString('text-delta', $content);
        $this->assertStringContainsString('إجابة محفوظة', $content);
        $this->assertStringContainsString('"type":"finish"', $content);
        $this->assertStringContainsString("data: [DONE]\n\n", $content);

        Queue::assertNothingPushed();
    }

    public function test_resuming_run_with_expired_marker_gives_up_immediately(): void
    {
        $convId = (string) Str::uuid();
        AiConversationMeta::create([
            'conversation_id' => $convId,
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedUser->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة تجريبية',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        $runId = (string) Str::uuid();
        $giveUpSeconds = (int) config('assistant.stream.queue_give_up_seconds', 960);
        $dispatchedAt = now()->getTimestamp() - ($giveUpSeconds + 30);

        Cache::put("assistant:running:{$convId}", [
            'runId' => $runId,
            'dispatchedAt' => $dispatchedAt,
        ], 1200);

        $response = $this->actingAs($this->allowedUser)
            ->get(route('assistant.runs.stream', ['runId' => $runId]));

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString(RunTail::QUEUE_UNAVAILABLE_AR, $content);
        $this->assertStringContainsString('"type":"finish"', $content);
        $this->assertStringContainsString("data: [DONE]\n\n", $content);

        $this->assertDatabaseHas('ai_run_events', [
            'run_id' => $runId,
            'type' => 'error',
        ]);

        $this->assertNull(Cache::get("assistant:running:{$convId}"));
    }

    public function test_cancel_sets_cancel_flag_for_own_run(): void
    {
        $runId = (string) Str::uuid();
        $log = new RunLog;
        $log->append($runId, $this->allowedUser->id, AssistantEvent::textDelta('بدء المعالجة'), null);
        $log->flush($runId);

        $response = $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.runs.cancel', ['runId' => $runId]));

        $response->assertNoContent();
        $this->assertTrue(Cache::get("ai:cancel:{$this->allowedUser->id}:{$runId}"));
    }

    public function test_resume_and_cancel_of_another_users_run_are_forbidden(): void
    {
        $otherRunId = (string) Str::uuid();
        $log = new RunLog;
        $log->append($otherRunId, $this->otherUser->id, AssistantEvent::textDelta('بيانات مستخدم آخر'), null);
        $log->flush($otherRunId);

        $this->actingAs($this->allowedUser)
            ->get(route('assistant.runs.stream', ['runId' => $otherRunId]))
            ->assertNotFound();

        $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.runs.cancel', ['runId' => $otherRunId]))
            ->assertNotFound();
    }

    public function test_chat_with_another_users_conversation_is_forbidden(): void
    {
        $otherConvId = (string) Str::uuid();
        AiConversationMeta::create([
            'conversation_id' => $otherConvId,
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->otherUser->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة مستخدم آخر',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.chat'), [
                'id' => $otherConvId,
                'messages' => [
                    ['role' => 'user', 'content' => 'محاولة اختراق'],
                ],
            ])
            ->assertForbidden();
    }

    public function test_queue_wait_notice_then_give_up(): void
    {
        $runId = (string) Str::uuid();
        $time = 1000;
        $clock = function () use (&$time): int {
            return $time;
        };
        $sleeper = function (int $us) use (&$time): void {
            $time += 70;
        };

        $tail = new RunTail(
            log: app(RunLog::class),
            clock: $clock,
            sleeper: $sleeper,
            pollMs: 10,
            maxSeconds: 2000,
            queueNoticeSeconds: 60,
            queueGiveUpSeconds: 120,
        );

        $chunks = iterator_to_array($tail->stream($runId, $this->allowedUser->id), false);
        $combined = implode('', $chunks);

        $this->assertStringContainsString('data-status', $combined);
        $this->assertStringContainsString('لسه مستني دوره…', $combined);
        $this->assertStringContainsString(RunTail::QUEUE_UNAVAILABLE_AR, $combined);
        $this->assertStringContainsString('"type":"finish"', $combined);
        $this->assertStringContainsString("data: [DONE]\n\n", $combined);

        $this->assertDatabaseHas('ai_run_events', [
            'run_id' => $runId,
            'user_id' => $this->allowedUser->id,
            'type' => 'error',
        ]);
    }

    public function test_disconnect_stops_tailing_quietly_without_cancelling(): void
    {
        $runId = (string) Str::uuid();
        $aborted = false;

        $tail = new RunTail(
            log: app(RunLog::class),
            clock: static fn (): int => 1000,
            sleeper: function () use (&$aborted): void {
                $aborted = true;
            },
            connectionAborted: function () use (&$aborted): bool {
                return $aborted;
            },
            pollMs: 10,
        );

        $chunks = iterator_to_array($tail->stream($runId, $this->allowedUser->id), false);
        $combined = implode('', $chunks);

        $this->assertStringNotContainsString('[DONE]', $combined);
        $this->assertFalse(Cache::has("ai:cancel:{$this->allowedUser->id}:{$runId}"));
    }

    public function test_run_id_owned_by_another_user_is_rejected(): void
    {
        $otherRunId = (string) Str::uuid();
        $log = new RunLog;
        $log->append($otherRunId, $this->otherUser->id, AssistantEvent::textDelta('بيانات'), null);
        $log->flush($otherRunId);

        $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.chat'), [
                'runId' => $otherRunId,
                'messages' => [
                    ['role' => 'user', 'content' => 'سؤال'],
                ],
            ])
            ->assertStatus(400);
    }

    public function test_invalid_uuid_run_id_is_rejected(): void
    {
        $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.chat'), [
                'runId' => 'not-a-uuid',
                'messages' => [
                    ['role' => 'user', 'content' => 'سؤال'],
                ],
            ])
            ->assertStatus(400);
    }
}
