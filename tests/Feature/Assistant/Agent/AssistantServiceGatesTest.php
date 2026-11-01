<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Agent;

use App\Ai\Agents\RestaurantAnalyst;
use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\Channel;
use App\Enums\OrganizationPlan;
use App\Models\AiConversationMeta;
use App\Models\AiSetting;
use App\Models\AiUsageLedgerEntry;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AssistantServiceGatesTest extends TestCase
{
    use RefreshDatabase;

    private AssistantService $service;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->service = resolve(AssistantService::class);
    }

    private function createEligibleUser(): User
    {
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
        ]);
        $user->givePermissionTo('ai.view');

        return $user;
    }

    public function test_ai_access_denial_emits_forbidden_error_in_stream_and_ask(): void
    {
        // User without ai.view permission
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);
        $unauthorizedUser = User::factory()->create([
            'organization_id' => $org->id,
        ]);

        // Test stream
        $events = iterator_to_array($this->service->stream(
            $unauthorizedUser,
            Channel::Chat,
            null,
            'مرحبا',
            (string) Str::uuid()
        ));

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]->type);
        $this->assertSame('forbidden', $events[0]->errorCode);
        $this->assertStringContainsString('صلاحية', (string) $events[0]->text);

        // Test ask
        $reply = $this->service->ask($unauthorizedUser, Channel::Chat, null, 'مرحبا');
        $this->assertTrue($reply->failed());
        $this->assertSame('forbidden', $reply->errorCode);
        $this->assertStringContainsString('صلاحية', $reply->text);
    }

    public function test_budget_exhausted_emits_budget_exceeded_error(): void
    {
        $user = $this->createEligibleUser();
        $org = $user->organization;
        $this->assertNotNull($org);

        // Create setting with budget 500 EGP and hard stop
        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'monthly_budget_egp' => '500.00',
            'hard_stop' => true,
        ]);

        // Record usage of 600 EGP to exceed the budget
        AiUsageLedgerEntry::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'cost_egp' => '600.00',
            'created_at' => now(),
        ]);

        // Test stream
        $events = iterator_to_array($this->service->stream(
            $user,
            Channel::Chat,
            null,
            'حلل المبيعات',
            (string) Str::uuid()
        ));

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]->type);
        $this->assertSame('budget_exceeded', $events[0]->errorCode);
        $this->assertStringContainsString('ميزانية', (string) $events[0]->text);

        // Test ask
        $reply = $this->service->ask($user, Channel::Chat, null, 'حلل المبيعات');
        $this->assertTrue($reply->failed());
        $this->assertSame('budget_exceeded', $reply->errorCode);
        $this->assertStringContainsString('ميزانية', $reply->text);
    }

    public function test_rate_limit_exceeded_emits_rate_limited_error(): void
    {
        $user = $this->createEligibleUser();
        $rateLimitKey = "ai:rate_limit:user:{$user->id}";

        // Hit limit up to max
        $max = (int) config('ai_billing.rate_limits.messages_per_minute_per_user', 20);
        for ($i = 0; $i < $max; $i++) {
            RateLimiter::hit($rateLimitKey, 60);
        }

        // Test stream
        $events = iterator_to_array($this->service->stream(
            $user,
            Channel::Chat,
            null,
            'رسالة بعد الحد',
            (string) Str::uuid()
        ));

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]->type);
        $this->assertSame('rate_limited', $events[0]->errorCode);
        $this->assertStringContainsString('تجاوز الحد', (string) $events[0]->text);

        // Test ask
        $reply = $this->service->ask($user, Channel::Chat, null, 'رسالة بعد الحد');
        $this->assertTrue($reply->failed());
        $this->assertSame('rate_limited', $reply->errorCode);
    }

    public function test_conversation_ownership_refusal_emits_forbidden(): void
    {
        $userA = $this->createEligibleUser();
        $userB = $this->createEligibleUser();

        // Register conversation for user B
        $otherConversationId = (string) Str::uuid7();
        AiConversationMeta::create([
            'conversation_id' => $otherConversationId,
            'organization_id' => $userB->organization_id,
            'user_id' => $userB->id,
            'channel' => Channel::Chat,
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        // User A attempts to access User B's conversation
        $events = iterator_to_array($this->service->stream(
            $userA,
            Channel::Chat,
            $otherConversationId,
            'سرقة بيانات',
            (string) Str::uuid()
        ));

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]->type);
        $this->assertSame('forbidden', $events[0]->errorCode);
        $this->assertSame($otherConversationId, $events[0]->conversationId);

        // Test ask
        $reply = $this->service->ask($userA, Channel::Chat, $otherConversationId, 'سرقة بيانات');
        $this->assertTrue($reply->failed());
        $this->assertSame('forbidden', $reply->errorCode);
    }

    public function test_new_conversation_registered_in_ai_conversations_meta(): void
    {
        $user = $this->createEligibleUser();
        RestaurantAnalyst::fake(['إجابة تجريبية']);

        $events = iterator_to_array($this->service->stream(
            $user,
            Channel::Chat,
            null,
            'ابدأ محادثة جديدة',
            (string) Str::uuid()
        ));

        /** @var AssistantEvent $doneEvent */
        $doneEvent = end($events);
        $this->assertSame('done', $doneEvent->type);
        $this->assertNotNull($doneEvent->conversationId);

        $this->assertDatabaseHas('ai_conversations_meta', [
            'conversation_id' => $doneEvent->conversationId,
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'channel' => 'chat',
            'title' => 'ابدأ محادثة جديدة',
        ]);

        // The SDK's own conversation row must exist under the same id, owned by the user,
        // and the turn's messages must be stored against it.
        $this->assertDatabaseHas('agent_conversations', [
            'id' => $doneEvent->conversationId,
            'participant_id' => $user->id,
        ]);
        $this->assertSame(1, DB::table('agent_conversations')->where('id', $doneEvent->conversationId)->count());
        $this->assertGreaterThan(0, DB::table('agent_conversation_messages')->where('conversation_id', $doneEvent->conversationId)->count());
    }
}
