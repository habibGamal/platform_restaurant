<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Agent;

use App\Ai\Agents\RestaurantAnalyst;
use App\Ai\Middleware\EnforceBudget;
use App\Ai\Middleware\InjectRuntimeContext;
use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\Channel;
use App\Enums\OrganizationPlan;
use App\Models\AiSetting;
use App\Models\AiUsageLedgerEntry;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\TextUsage;
use Tests\TestCase;

final class MiddlewareAndExecutionTest extends TestCase
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

    public function test_usage_ledger_row_written_per_step(): void
    {
        $user = $this->createEligibleUser();
        RestaurantAnalyst::fake(['رد تجريبي لحساب الاستخدام']);

        $runId = (string) Str::uuid();
        $events = iterator_to_array($this->service->stream(
            $user,
            Channel::Chat,
            null,
            'احسب لي المبيعات',
            $runId
        ));

        /** @var AssistantEvent $doneEvent */
        $doneEvent = end($events);
        $this->assertSame('done', $doneEvent->type);

        $this->assertDatabaseHas('ai_usage_ledger', [
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'conversation_id' => $doneEvent->conversationId,
            'channel' => 'chat',
            'step' => 0,
        ]);
    }

    public function test_cancel_flag_stops_the_run(): void
    {
        $user = $this->createEligibleUser();
        $runId = (string) Str::uuid();

        // Put cancel flag in cache before execution
        Cache::put("ai:cancel:{$user->id}:{$runId}", true, 600);

        RestaurantAnalyst::fake(['هذا الرد لن يُسلّم لأن التشغيل أُوقف.']);

        $events = iterator_to_array($this->service->stream(
            $user,
            Channel::Chat,
            null,
            'ابدأ ثم أوقف',
            $runId
        ));

        $this->assertNotEmpty($events);
        $lastEvent = end($events);
        $this->assertInstanceOf(AssistantEvent::class, $lastEvent);
        $this->assertSame('error', $lastEvent->type);
        $this->assertSame('cancelled', $lastEvent->errorCode);
        $this->assertStringContainsString('تم إيقاف المحادثة بواسطة المستخدم', (string) $lastEvent->text);
    }

    public function test_cancel_flag_is_namespaced_by_user(): void
    {
        $user = $this->createEligibleUser();
        $runId = (string) Str::uuid();

        // Put cancel flag for another user ID (e.g. 99999)
        Cache::put("ai:cancel:99999:{$runId}", true, 600);

        RestaurantAnalyst::fake(['الرد يكتمل بنجاح لأن الإلغاء لمستخدم آخر.']);

        $events = iterator_to_array($this->service->stream(
            $user,
            Channel::Chat,
            null,
            'تشغيل آمن',
            $runId
        ));

        $lastEvent = end($events);
        $this->assertInstanceOf(AssistantEvent::class, $lastEvent);
        $this->assertSame('done', $lastEvent->type);
    }

    public function test_cancel_method_puts_cache_for_uuid_and_ignores_non_uuid(): void
    {
        $user = $this->createEligibleUser();
        Auth::login($user);

        // Valid UUID
        $validUuid = (string) Str::uuid();
        $this->service->cancel($validUuid);
        $this->assertTrue(Cache::has("ai:cancel:{$user->id}:{$validUuid}"));

        // Non-UUID should be ignored
        $invalidId = 'invalid-run-id-123';
        $this->service->cancel($invalidId);
        $this->assertFalse(Cache::has("ai:cancel:{$user->id}:{$invalidId}"));
    }

    public function test_enforce_budget_middleware_stops_when_budget_projected_exceeds(): void
    {
        $user = $this->createEligibleUser();
        $org = $user->organization;
        $this->assertNotNull($org);

        // Budget is 100 EGP, spend is 150 EGP
        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'monthly_budget_egp' => '100.00',
            'hard_stop' => true,
        ]);
        AiUsageLedgerEntry::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'cost_egp' => '150.00',
            'created_at' => now(),
        ]);

        $middleware = new EnforceBudget($user);

        $pendingStep = new PendingStep(
            number: 1,
            isFinalStep: false,
            provider: 'openai',
            model: 'gpt-4o',
            instructions: 'test',
            messages: [],
            tools: [],
            schema: null,
            options: null,
            usage: new TextUsage(inputTokens: 500, outputTokens: 200),
        );

        $result = $middleware->handle($pendingStep, fn (PendingStep $s): StepResult => new StepResult(null));
        $this->assertTrue($result->stopped());
    }

    public function test_enforce_budget_stops_when_input_token_cap_reached(): void
    {
        $user = $this->createEligibleUser();
        config(['ai_billing.per_run_input_token_cap' => 1000]);

        $middleware = new EnforceBudget($user);

        $pendingStep = new PendingStep(
            number: 1,
            isFinalStep: false,
            provider: 'openai',
            model: 'gpt-4o',
            instructions: 'test',
            messages: [],
            tools: [],
            schema: null,
            options: null,
            usage: new TextUsage(inputTokens: 1500, outputTokens: 100),
        );

        $result = $middleware->handle($pendingStep, fn (PendingStep $s): StepResult => new StepResult(null));
        $this->assertTrue($result->stopped());
    }

    public function test_inject_runtime_context_appends_context_note_to_messages(): void
    {
        $user = $this->createEligibleUser();
        $middleware = new InjectRuntimeContext($user, Channel::Chat);

        $pendingStep = new PendingStep(
            number: 0,
            isFinalStep: false,
            provider: 'anthropic',
            model: 'claude-3-7-sonnet',
            instructions: 'static instructions',
            messages: [],
            tools: [],
            schema: null,
            options: null,
        );

        $capturedStep = null;
        $middleware->handle($pendingStep, function (PendingStep $step) use (&$capturedStep): StepResult {
            $capturedStep = $step;

            return new StepResult(null);
        });

        $this->assertNotNull($capturedStep);
        $this->assertCount(1, $capturedStep->messages);
        $contextMessage = $capturedStep->messages[0];
        $this->assertStringContainsString('Runtime Context', (string) $contextMessage->content);
        $this->assertStringContainsString('Channel: chat', (string) $contextMessage->content);
    }

    public function test_restaurant_analyst_instructions_are_static_coordinator_rules_in_arabic_and_english(): void
    {
        $user = $this->createEligibleUser();
        // Rule numbers may match a small auto-increment ID; use unique context sentinels.
        $user->forceFill(['id' => 987654321, 'name' => 'StaticPromptContextSentinel']);
        $agent = new RestaurantAnalyst($user, Channel::Chat);

        $instructions = (string) $agent->instructions();

        // Contains no dynamic dates, user ids or names
        $this->assertStringNotContainsString((string) $user->id, $instructions);
        $this->assertStringNotContainsString($user->name, $instructions);

        // Static text: identical for any user and channel, so the provider can cache it.
        $other = new User(['name' => 'AnotherSentinel']);
        $this->assertSame($instructions, (string) (new RestaurantAnalyst($other, Channel::WhatsApp, 'run'))->instructions());

        // Coordinator sections in Arabic
        $this->assertStringContainsString('التوجيه والتفويض', $instructions);
        $this->assertStringContainsString('التأصيل والصلاحيات', $instructions);
        $this->assertStringContainsString('شكل الإجابة', $instructions);
        $this->assertStringContainsString('«خطة:»', $instructions);
        $this->assertStringContainsString('«ركز على / متلمسش / كومبو مقترح / أفكار»', $instructions);
        $this->assertStringContainsString('«اقتراح:»', $instructions);
        $this->assertStringContainsString('واتساب', $instructions);
        $this->assertStringContainsString('غير موثوقة', $instructions);

        // Names only tools the coordinator actually has.
        foreach (['marketing_specialist', 'finance_specialist', 'operations_specialist', 'data_checker', 'get_workspace_context'] as $tool) {
            $this->assertStringContainsString($tool, $instructions);
        }
        foreach (['menu_engineering', 'combo_builder', 'playbook'] as $absent) {
            $this->assertStringNotContainsStringIgnoringCase($absent, $instructions);
        }

        // English rules block
        $this->assertStringContainsString('CORE RULES:', $instructions);
        $this->assertStringContainsString('never model arithmetic', $instructions);
    }

    public function test_restaurant_analyst_provider_failover_chain(): void
    {
        $user = $this->createEligibleUser();
        $org = $user->organization;
        $this->assertNotNull($org);

        // Create setting with primary anthropic and failover openai
        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'provider' => 'anthropic',
            'model' => 'claude-3-7-sonnet',
            'failover' => [
                ['provider' => 'openai', 'model' => 'gpt-4o'],
            ],
            'reasoning_level' => 'high',
        ]);

        $agent = new RestaurantAnalyst($user, Channel::Chat);

        $providerChain = $agent->provider();
        $this->assertIsArray($providerChain);
        $this->assertSame([
            'anthropic' => 'claude-3-7-sonnet',
            'openai' => 'gpt-4o',
        ], $providerChain);

        // Provider options should use 'high'
        $options = $agent->providerOptions('anthropic');
        $this->assertIsArray($options['output_config']);
        $this->assertSame('high', $options['output_config']['effort']);
    }

    public function test_restaurant_analyst_falls_back_to_config_when_no_ai_settings(): void
    {
        $user = $this->createEligibleUser();
        $agent = new RestaurantAnalyst($user, Channel::Chat);

        $defaultProvider = $agent->provider();
        $this->assertSame(config('ai.default'), $defaultProvider);
    }
}
