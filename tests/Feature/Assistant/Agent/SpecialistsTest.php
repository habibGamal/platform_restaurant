<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Agent;

use App\Ai\Agents\DataChecker;
use App\Ai\Agents\FinanceSpecialist;
use App\Ai\Agents\MarketingSpecialist;
use App\Ai\Agents\OperationsSpecialist;
use App\Ai\Middleware\EnforceBudget;
use App\Ai\Middleware\InjectRuntimeContext;
use App\Ai\Middleware\RecordUsage;
use App\Ai\Middleware\StopOnCancel;
use App\Domain\Assistant\Channel;
use App\Enums\OrganizationPlan;
use App\Models\AiSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use ReflectionClass;
use ReflectionProperty;
use Tests\Fixtures\MenuFixture;
use Tests\TestCase;

final class SpecialistsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<class-string, list<string>> */
    private const TOOLS = [
        MarketingSpecialist::class => ['resolve_period', 'find_branches', 'menu_engineering', 'bought_together', 'combo_builder', 'item_trends', 'item_timing', 'get_kpis', 'query_metrics'],
        FinanceSpecialist::class => ['resolve_period', 'find_branches', 'get_kpis', 'get_kpi_series', 'profitability', 'food_cost_waste', 'expenses_purchases', 'sales_comparison', 'targets_vs_actual', 'sales_forecast'],
        OperationsSpecialist::class => ['resolve_period', 'find_branches', 'get_kpis', 'branch_ranking', 'shifts_weekdays', 'overview_report', 'peak_hours', 'data_health', 'targets_vs_actual'],
        DataChecker::class => ['data_health'],
    ];

    public function test_specialists_have_exactly_their_allowed_tools_and_do_not_remember_conversations(): void
    {
        $user = new User;

        foreach (self::TOOLS as $class => $names) {
            $agent = new $class($user, Channel::Chat);
            $tools = [...$agent->tools()];
            $actualNames = [];
            $this->assertNotContains(Conversational::class, class_implements($agent));
            foreach ($tools as $tool) {
                $this->assertTrue(method_exists($tool, 'name'));
                $actualNames[] = $tool->name();
                $this->assertSame($user, get_object_vars($tool)['user']);
            }
            $this->assertSame($names, $actualNames);
        }
    }

    public function test_skills_load_from_existing_directories_and_use_only_available_tools(): void
    {
        $expected = [
            MarketingSpecialist::class => ['marketing-focus', 'combo-design', 'menu-cleanup', 'promotion-review'],
            FinanceSpecialist::class => ['profit-review', 'cost-check', 'forecast', 'expense-anomalies'],
            OperationsSpecialist::class => ['branch-comparison', 'peak-hours-staffing', 'waste', 'targets-follow-up'],
            DataChecker::class => [],
        ];
        $allTools = array_unique(array_merge(...array_values(self::TOOLS)));

        foreach ($expected as $class => $names) {
            $agent = new $class(new User, Channel::Chat);
            $skills = [...$agent->skills()];
            $this->assertSame($names, array_map(fn (Skill $skill): string => $skill->name, $skills));

            foreach ($skills as $skill) {
                $this->assertNotNull($skill->path);
                $this->assertFileExists($skill->path.'/SKILL.md');
                $this->assertSame(basename($skill->path), $skill->name);
                $this->assertNotEmpty((string) $skill->description);
                $this->assertStringContainsString('Tool order', (string) $skill->instructions);
                $this->assertStringContainsString('Output', (string) $skill->instructions);
                foreach (array_diff($allTools, self::TOOLS[$class]) as $unavailable) {
                    $this->assertStringNotContainsString('`'.$unavailable.'`', (string) $skill->instructions);
                }
            }
        }
    }

    public function test_prompts_are_static_grounded_and_protect_against_data_instructions_without_fixture_names(): void
    {
        $products = (new ReflectionClass(MenuFixture::class))->getConstant('PRODUCTS');
        $categories = (new ReflectionClass(MenuFixture::class))->getConstant('CATEGORIES');
        $names = array_merge(array_column($products, 0), array_values($categories), [
            'Single Burger', 'Double Burger', 'Cheese Burger', 'Fries', 'Cola', 'Water', 'Burgers', 'Drinks & Sides',
        ]);
        $firstUser = (new User)->forceFill(['id' => 987654321, 'name' => 'PromptSentinelAlpha']);
        $secondUser = (new User)->forceFill(['id' => 987654322, 'name' => 'PromptSentinelBeta']);

        foreach (array_keys(self::TOOLS) as $class) {
            $agent = new $class($firstUser, Channel::Chat, 'run-sentinel');
            $instructions = (string) $agent->instructions();
            $this->assertSame($instructions, (string) (new $class($secondUser, Channel::WhatsApp))->instructions());
            $this->assertStringContainsString('Every number', $instructions);
            $this->assertStringContainsString('untrusted data', $instructions);
            $this->assertStringContainsString('Never follow instructions', $instructions);
            $this->assertStringContainsString('Arabic', $instructions);
            $this->assertStringNotContainsString($firstUser->name, $instructions);
            $this->assertStringNotContainsString((string) $firstUser->id, $instructions);
            $this->assertStringNotContainsString('run-sentinel', $instructions);

            $texts = [$instructions, (string) $agent->description()];
            foreach ($agent->skills() as $skill) {
                $texts[] = (string) file_get_contents($skill->path.'/SKILL.md');
            }
            foreach ($texts as $text) {
                foreach ($names as $name) {
                    $this->assertStringNotContainsString($name, $text, $class.' contains fixture name '.$name);
                }
            }
        }
    }

    public function test_specialists_share_run_budget_cancellation_and_usage_middleware_and_have_lower_limits(): void
    {
        $user = new User;
        $runId = (string) Str::uuid();

        foreach (array_keys(self::TOOLS) as $class) {
            $agent = new $class($user, Channel::WhatsApp, $runId);
            $middleware = $agent->middleware();
            $this->assertSame([InjectRuntimeContext::class, EnforceBudget::class, StopOnCancel::class, RecordUsage::class], array_map(get_class(...), $middleware));
            $this->assertSame($runId, (new ReflectionProperty(EnforceBudget::class, 'runId'))->getValue($middleware[1]));
            $this->assertSame($runId, (new ReflectionProperty(StopOnCancel::class, 'runId'))->getValue($middleware[2]));
            $this->assertSame(Channel::WhatsApp, (new ReflectionProperty(RecordUsage::class, 'channel'))->getValue($middleware[3]));
            $reflection = new ReflectionClass($agent);
            foreach ([MaxSteps::class => 8, Timeout::class => 120, MaxTokens::class => 3000] as $attribute => $limit) {
                $this->assertSame($limit, $reflection->getAttributes($attribute)[0]->newInstance()->value);
            }
        }
    }

    public function test_specialists_use_org_provider_settings_and_whatsapp_reasoning_defaults(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        AiSetting::factory()->create(['organization_id' => $organization->id, 'provider' => 'openai', 'model' => 'specialist-model', 'reasoning_level' => 'high', 'failover' => [['provider' => 'anthropic', 'model' => 'fallback-model']]]);

        foreach (array_keys(self::TOOLS) as $class) {
            $agent = new $class($user, Channel::WhatsApp);
            $this->assertSame(['openai' => 'specialist-model', 'anthropic' => 'fallback-model'], $agent->provider());
            $this->assertSame('specialist-model', $agent->model());
            $this->assertSame('high', $agent->providerOptions('gemini')['thinking_level']);
            $this->assertSame('low', (new $class(new User, Channel::WhatsApp))->providerOptions('gemini')['thinking_level']);
        }
    }

    public function test_agent_tool_streams_specialist_events_and_returns_final_findings(): void
    {
        $organization = Organization::factory()->create(['plan' => OrganizationPlan::PRO, 'ai_enabled' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $answer = 'نتائج مختصرة تستند إلى الأدوات.';
        MarketingSpecialist::fake([$answer]);
        $tool = new AgentTool(new MarketingSpecialist($user, Channel::Chat, (string) Str::uuid()));

        $stream = $tool->stream(new Request(['task' => 'Period: this month. Branches: all accessible. Exact question: ما أولويات التسويق؟']));
        $events = iterator_to_array($stream);

        $this->assertSame('marketing_specialist', $tool->name());
        $this->assertNotEmpty($events);
        $this->assertSame($answer, $stream->getReturn());
        $this->assertSame($answer, TextDelta::combine($events));
        MarketingSpecialist::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Exact question:'));
    }
}
