<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Agent;

use App\Ai\Agents\RestaurantAnalyst;
use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\AssistantReply;
use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\Channel;
use App\Enums\OrganizationPlan;
use App\Models\AiConversationMeta;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\TestCase;

final class AssistantServiceStreamTest extends TestCase
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

    public function test_stream_ends_with_done_carrying_conversation_id(): void
    {
        $user = $this->createEligibleUser();
        RestaurantAnalyst::fake(['مرحباً بك في لوحة تحليلات المطاعم.']);

        $runId = (string) Str::uuid();
        $events = iterator_to_array($this->service->stream(
            $user,
            Channel::Chat,
            null,
            'ما هي مبيعات اليوم؟',
            $runId
        ));

        $this->assertNotEmpty($events);

        // Verify there is at least one text_delta
        $deltas = array_filter($events, fn (AssistantEvent $e): bool => $e->type === 'text_delta');
        $this->assertNotEmpty($deltas);

        // Verify the last event is done
        /** @var AssistantEvent $lastEvent */
        $lastEvent = end($events);
        $this->assertSame('done', $lastEvent->type);
        $this->assertNotNull($lastEvent->conversationId);
        $this->assertTrue(Str::isUuid($lastEvent->conversationId));
    }

    public function test_stream_maps_tool_call_and_tool_result_events(): void
    {
        $user = $this->createEligibleUser();

        // Fake response that performs a tool call first, then answers
        RestaurantAnalyst::fake([
            new ToolCall('call_1', 'get_workspace_context', []),
            'تم فحص سياق العمل بنجاح.',
        ]);

        $runId = (string) Str::uuid();
        $events = iterator_to_array($this->service->stream(
            $user,
            Channel::Chat,
            null,
            'ما هو سياق الفروع لدي؟',
            $runId
        ));

        $types = array_map(fn (AssistantEvent $e): string => $e->type, $events);

        $this->assertContains('tool_call', $types);
        $this->assertContains('tool_result', $types);
        $this->assertContains('text_delta', $types);
        $lastEvent = end($events);
        $this->assertInstanceOf(AssistantEvent::class, $lastEvent);
        $this->assertSame('done', $lastEvent->type);

        $toolCallEvent = collect($events)->first(fn (AssistantEvent $e): bool => $e->type === 'tool_call');
        $this->assertInstanceOf(AssistantEvent::class, $toolCallEvent);
        $this->assertSame('get_workspace_context', $toolCallEvent->toolName);
    }

    public function test_stream_handles_provider_refusal_as_provider_error(): void
    {
        $user = $this->createEligibleUser();

        // Force a refusal response
        RestaurantAnalyst::fake([
            fn () => throw new class('Refusal from upstream provider') extends \Exception implements FailoverableException {},
        ]);

        $runId = (string) Str::uuid();
        $events = iterator_to_array($this->service->stream(
            $user,
            Channel::Chat,
            null,
            'سؤال مرفوض',
            $runId
        ));

        $lastEvent = end($events);
        $this->assertInstanceOf(AssistantEvent::class, $lastEvent);
        $this->assertSame('error', $lastEvent->type);
        $this->assertSame('provider_error', $lastEvent->errorCode);
        $this->assertStringContainsString('مزود خدمة', (string) $lastEvent->text);
    }

    public function test_last_activity_at_is_updated_after_each_run(): void
    {
        $user = $this->createEligibleUser();
        RestaurantAnalyst::fake(['تحليل المبيعات ممتاز.']);

        $conversationId = (string) Str::uuid();
        $initialTime = Carbon::now()->subHours(2);

        AiConversationMeta::create([
            'conversation_id' => $conversationId,
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'channel' => Channel::Chat,
            'pinned' => false,
            'last_activity_at' => $initialTime,
        ]);

        // Run stream
        iterator_to_array($this->service->stream(
            $user,
            Channel::Chat,
            $conversationId,
            'سؤال تجريبي',
            (string) Str::uuid()
        ));

        $meta = AiConversationMeta::where('conversation_id', $conversationId)->first();
        $this->assertNotNull($meta);
        $this->assertTrue($meta->last_activity_at->gt($initialTime));
    }

    public function test_ask_returns_assistant_reply(): void
    {
        $user = $this->createEligibleUser();
        RestaurantAnalyst::fake(['إجابة غير متدفقة عبر واتساب.']);

        $reply = $this->service->ask($user, Channel::WhatsApp, null, 'موجز المبيعات');

        $this->assertInstanceOf(AssistantReply::class, $reply);
        $this->assertFalse($reply->failed());
        $this->assertStringContainsString('إجابة غير متدفقة', $reply->text);
        $this->assertNotNull($reply->conversationId);
    }
}
