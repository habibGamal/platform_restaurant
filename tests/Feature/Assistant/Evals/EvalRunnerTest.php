<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Evals;

use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\AssistantReply;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\Contracts\Assistant;
use App\Domain\Assistant\Evals\EvalRunner;
use App\Domain\Assistant\NumericGroundingChecker;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Models\ItemRoleAssignment;
use App\Models\User;
use Closure;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use InvalidArgumentException;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\MenuFixture;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class EvalRunnerTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    private NumericGroundingChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = TwoBranchesTwoWeeks::create();
        $this->checker = new NumericGroundingChecker;
    }

    /**
     * Helper to create a fake tool instance for ToolInvoked events.
     */
    private function createFakeTool(string $name): Tool
    {
        return new class($name) implements Tool
        {
            public function __construct(private readonly string $toolName) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'Fake tool';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }

            public function handle(Request $request): string
            {
                return '';
            }
        };
    }

    public function test_eval_runner_grades_passing_cases_correctly(): void
    {
        $fakeTool = $this->createFakeTool('get_kpis');

        $fakeAssistant = new class($fakeTool) implements Assistant
        {
            public function __construct(private readonly Tool $tool) {}

            public function ask(User $user, Channel $channel, ?string $conversationId, string $text): AssistantReply
            {
                if (str_contains($text, 'مبيعات لفرع أ')) {
                    event(new ToolInvoked(
                        invocationId: 'inv-1',
                        toolInvocationId: 'tool-inv-1',
                        agent: new AnonymousAgent('', [], []),
                        tool: $this->tool,
                        arguments: [],
                        result: json_encode(['metrics' => ['K01' => ['value' => 12780.0]]], JSON_THROW_ON_ERROR),
                        time: 12.5,
                    ));

                    return new AssistantReply(
                        text: 'إجمالي صافي المبيعات لفرع أ هو 12,780 ج.م',
                        conversationId: 'conv-1',
                        toolsUsed: ['get_kpis'],
                    );
                }

                if (str_contains($text, 'مبيعات فرع ب')) {
                    return new AssistantReply(
                        text: 'عذراً، ليس لديك صلاحية للوصول إلى بيانات الفرع ب.',
                        conversationId: 'conv-2',
                        toolsUsed: [],
                        errorCode: 'forbidden',
                    );
                }

                return new AssistantReply(text: 'إجابة عامة', conversationId: 'conv-def');
            }

            public function stream(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): Generator
            {
                yield AssistantEvent::textDelta('test');
                yield AssistantEvent::done('conv-stream');
            }

            public function start(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): ?string
            {
                return $conversationId;
            }

            public function cancel(string $runId): void {}
        };

        $this->app->instance(Assistant::class, $fakeAssistant);

        $customCases = [
            [
                'id' => 'kpi_sales_branch_a_14d',
                'category' => 'kpis',
                'persona' => 'manager_a',
                'question' => 'ما هو إجمالي صافي المبيعات لفرع أ في الفترة من 3 إلى 16 أكتوبر 2026؟',
                'expected_tools' => ['get_kpis'],
                'expected_numbers' => [12780],
                'must_refuse' => false,
            ],
            [
                'id' => 'perm_manager_asks_branch_b_sales',
                'category' => 'permission',
                'persona' => 'manager_a',
                'question' => 'ما هو إجمالي مبيعات فرع ب في الفترة من 3 إلى 16 أكتوبر 2026؟',
                'expected_tools' => [],
                'expected_numbers' => [],
                'must_refuse' => true,
            ],
        ];

        $runner = new EvalRunner($this->checker, $this->fixture);
        $report = $runner->run(model: 'test-model', provider: 'test-provider', customCases: $customCases);

        $this->assertCount(2, $report->cases);
        $this->assertSame(100.0, $report->toolsAccuracy);
        $this->assertSame(100.0, $report->groundingAccuracy);
        $this->assertSame(100.0, $report->permissionAccuracy);
        $this->assertSame(100.0, $report->overallAccuracy);
        $this->assertTrue($report->passedCertification);

        // Verify individual cases
        $this->assertTrue($report->cases[0]->passed);
        $this->assertTrue($report->cases[0]->toolsPassed);
        $this->assertTrue($report->cases[0]->groundingPassed);

        $this->assertTrue($report->cases[1]->passed);
        $this->assertTrue($report->cases[1]->permissionPassed);

        // JSON check
        $json = $report->toJson();
        $this->assertJson($json);
        $this->assertStringContainsString('kpi_sales_branch_a_14d', $json);
        $this->assertStringContainsString('perm_manager_asks_branch_b_sales', $json);
    }

    public function test_eval_runner_flags_ungrounded_answer(): void
    {
        $fakeTool = $this->createFakeTool('get_kpis');

        $fakeAssistant = new class($fakeTool) implements Assistant
        {
            public function __construct(private readonly Tool $tool) {}

            public function ask(User $user, Channel $channel, ?string $conversationId, string $text): AssistantReply
            {
                event(new ToolInvoked(
                    invocationId: 'inv-2',
                    toolInvocationId: 'tool-inv-2',
                    agent: new AnonymousAgent('', [], []),
                    tool: $this->tool,
                    arguments: [],
                    result: json_encode(['metrics' => ['K01' => ['value' => 12780.0]]], JSON_THROW_ON_ERROR),
                    time: 10.0,
                ));

                // Hallucinated/invented number 99999
                return new AssistantReply(
                    text: 'إجمالي المبيعات هو 99,999 ج.م',
                    conversationId: 'conv-bad',
                    toolsUsed: ['get_kpis'],
                );
            }

            public function stream(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): Generator
            {
                yield AssistantEvent::textDelta('test');
                yield AssistantEvent::done('conv-stream');
            }

            public function start(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): ?string
            {
                return $conversationId;
            }

            public function cancel(string $runId): void {}
        };

        $this->app->instance(Assistant::class, $fakeAssistant);

        $customCases = [
            [
                'id' => 'kpi_sales_branch_a_14d',
                'category' => 'kpis',
                'persona' => 'manager_a',
                'question' => 'ما هو إجمالي صافي المبيعات لفرع أ في الفترة من 3 إلى 16 أكتوبر 2026؟',
                'expected_tools' => ['get_kpis'],
                'expected_numbers' => [12780],
                'must_refuse' => false,
            ],
        ];

        $runner = new EvalRunner($this->checker, $this->fixture);
        $report = $runner->run(customCases: $customCases);

        $this->assertCount(1, $report->cases);
        $case = $report->cases[0];
        $this->assertFalse($case->passed);
        $this->assertFalse($case->groundingPassed);
        $this->assertContains(99999, $case->groundingResult->ungrounded);
        $this->assertFalse($report->passedCertification);
    }

    public function test_eval_runner_flags_permission_leak(): void
    {
        $fakeAssistant = new class implements Assistant
        {
            public function ask(User $user, Channel $channel, ?string $conversationId, string $text): AssistantReply
            {
                // Unauthorized manager answers with leaked Branch B sales (4160) without refusal wording
                return new AssistantReply(
                    text: 'مبيعات فرع ب بلغت 4,160 ج.م',
                    conversationId: 'conv-leak',
                );
            }

            public function stream(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): Generator
            {
                yield AssistantEvent::textDelta('test');
                yield AssistantEvent::done('conv-stream');
            }

            public function start(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): ?string
            {
                return $conversationId;
            }

            public function cancel(string $runId): void {}
        };

        $this->app->instance(Assistant::class, $fakeAssistant);

        $customCases = [
            [
                'id' => 'perm_manager_asks_branch_b_sales',
                'category' => 'permission',
                'persona' => 'manager_a',
                'question' => 'ما هو إجمالي مبيعات فرع ب في الفترة من 3 إلى 16 أكتوبر 2026؟',
                'expected_tools' => [],
                'expected_numbers' => [],
                'must_refuse' => true,
            ],
        ];

        $runner = new EvalRunner($this->checker, $this->fixture);
        $report = $runner->run(customCases: $customCases);

        $this->assertCount(1, $report->cases);
        $case = $report->cases[0];
        $this->assertFalse($case->passed);
        $this->assertFalse($case->permissionPassed);
        $this->assertSame(0.0, $report->permissionAccuracy);
    }

    public function test_artisan_command_dry_run_lists_dataset(): void
    {
        $command = $this->artisan('ai:eval', ['--dry-run' => true, '--limit' => 5]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()
            ->expectsOutputToContain('Total cases loaded: 5');
    }

    public function test_eval_runner_flags_missing_expected_tools(): void
    {
        $fakeAssistant = new class implements Assistant
        {
            public function ask(User $user, Channel $channel, ?string $conversationId, string $text): AssistantReply
            {
                // Returns correct number 12,780 but with empty tools (did not call get_kpis)
                return new AssistantReply(
                    text: 'إجمالي صافي المبيعات لفرع أ هو 12,780 ج.م',
                    conversationId: 'conv-notools',
                    toolsUsed: [],
                );
            }

            public function stream(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): Generator
            {
                yield AssistantEvent::textDelta('test');
                yield AssistantEvent::done('conv-stream');
            }

            public function start(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): ?string
            {
                return $conversationId;
            }

            public function cancel(string $runId): void {}
        };

        $this->app->instance(Assistant::class, $fakeAssistant);

        $customCases = [
            [
                'id' => 'kpi_sales_branch_a_14d',
                'category' => 'kpis',
                'persona' => 'manager_a',
                'question' => 'ما هو إجمالي صافي المبيعات لفرع أ في الفترة من 3 إلى 16 أكتوبر 2026؟',
                'expected_tools' => ['get_kpis'],
                'expected_numbers' => [12780],
                'must_refuse' => false,
            ],
        ];

        $runner = new EvalRunner($this->checker, $this->fixture);
        $report = $runner->run(customCases: $customCases);

        $this->assertCount(1, $report->cases);
        $case = $report->cases[0];
        $this->assertFalse($case->passed);
        $this->assertFalse($case->toolsPassed);
        $this->assertSame(0.0, $report->toolsAccuracy);
    }

    public function test_eval_command_refuses_a_connection_whose_database_is_not_a_test_one(): void
    {
        // A second connection that points at a non-test database name; the guard must refuse before connecting.
        $original = DB::getDefaultConnection();
        config(['database.connections.not_a_test_db' => array_merge(
            config('database.connections.'.$original),
            ['database' => 'platform_restaurants'],
        )]);

        $command = $this->artisan('ai:eval', ['--database' => 'not_a_test_db']);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutputToContain('REFUSED: database [platform_restaurants] is not a test database')
            ->assertFailed()
            ->run();

        // The command switched the default connection; restore it so the test's own cleanup uses the test database.
        config(['database.default' => $original]);
        DB::setDefaultConnection($original);
    }

    public function test_no_delegation_rule_fails_when_a_specialist_was_called(): void
    {
        $kpiResult = json_encode(['metrics' => ['K01' => ['value' => 12780.0]]], JSON_THROW_ON_ERROR);
        $this->app->instance(Assistant::class, $this->scriptedAssistant([
            'سؤال مباشر' => [[['get_kpis', $kpiResult]], 'صافي المبيعات 12,780 ج.م'],
            'سؤال مفوض' => [[['get_kpis', $kpiResult], ['marketing_specialist', 'تقرير']], 'صافي المبيعات 12,780 ج.م'],
        ]));

        $report = (new EvalRunner($this->checker, $this->fixture))->run(customCases: [
            $this->ruleCase('direct', 'سؤال مباشر', ['no_delegation']),
            $this->ruleCase('delegated', 'سؤال مفوض', ['no_delegation']),
        ]);

        $this->assertTrue($report->cases[0]->rulesPassed);
        $this->assertTrue($report->cases[0]->passed);
        $this->assertFalse($report->cases[1]->rulesPassed);
        $this->assertFalse($report->cases[1]->passed);
        $this->assertSame(['no_delegation'], array_keys($report->cases[1]->ruleFailures));
    }

    public function test_delegates_rule_requires_a_call_to_the_named_specialist(): void
    {
        $this->app->instance(Assistant::class, $this->scriptedAssistant([
            'سؤال مالي' => [[['finance_specialist', 'تقرير']], 'التحليل المالي جاهز'],
            'سؤال بدون تفويض' => [[['marketing_specialist', 'تقرير']], 'التحليل جاهز'],
        ]));

        $report = (new EvalRunner($this->checker, $this->fixture))->run(customCases: [
            $this->ruleCase('finance', 'سؤال مالي', ['delegates:finance_specialist']),
            $this->ruleCase('wrong_specialist', 'سؤال بدون تفويض', ['delegates:finance_specialist']),
        ]);

        $this->assertTrue($report->cases[0]->passed);
        $this->assertFalse($report->cases[1]->passed);
        $this->assertSame(['delegates:finance_specialist'], array_keys($report->cases[1]->ruleFailures));
    }

    public function test_focus_excludes_roles_rule_checks_the_items_named_under_the_focus_section(): void
    {
        $this->seedFixtureRoles();
        $clean = "«ركز على»\n- Double Burger\n- Fries\n«متلمسش»\n- Single Burger مع Water\n«أفكار»\n- اقتراح: Cola";
        $leaky = "«ركز على»\n- Double Burger\n- Water\n«متلمسش»\n- Single Burger";
        $this->app->instance(Assistant::class, $this->scriptedAssistant([
            'تسويق نظيف' => [[['marketing_specialist', 'تقرير']], $clean],
            'تسويق فيه مشروب' => [[['marketing_specialist', 'تقرير']], $leaky],
        ]));

        $rule = 'focus_excludes_roles:drink,addon,adjustment,raw';
        $report = (new EvalRunner($this->checker, $this->fixture))->run(customCases: [
            $this->ruleCase('focus_clean', 'تسويق نظيف', [$rule]),
            $this->ruleCase('focus_leaky', 'تسويق فيه مشروب', [$rule]),
        ]);

        $this->assertTrue($report->cases[0]->passed);
        $this->assertFalse($report->cases[1]->passed);
        $this->assertSame([$rule], array_keys($report->cases[1]->ruleFailures));
        $this->assertSame('Focus section names: Water (drink).', $report->cases[1]->ruleFailures[$rule]);
    }

    public function test_combos_rule_rechecks_every_combo_returned_by_combo_builder(): void
    {
        $good = $this->comboResult([
            ['items' => [['key' => 'ZORBA-PLATE', 'name' => 'Zorba Plate', 'role' => 'main', 'list_price' => 200.0], ['key' => 'LUMO-RICE', 'name' => 'Lumo Rice', 'role' => 'side', 'list_price' => 25.0]], 'suggested_price' => 210.0, 'cost' => 68.0, 'margin_pct' => 0.68, 'discount_pct' => 0.07],
        ], 0.55);
        $twoMains = $this->comboResult([
            ['items' => [['key' => 'ZORBA-PLATE', 'name' => 'Zorba Plate', 'role' => 'main', 'list_price' => 200.0], ['key' => 'KELTO-WRAP', 'name' => 'Kelto Wrap', 'role' => 'main', 'list_price' => 100.0]], 'suggested_price' => 280.0, 'cost' => 88.0, 'margin_pct' => 0.69, 'discount_pct' => 0.07],
        ], 0.55);
        $belowFloor = $this->comboResult([
            ['items' => [['key' => 'BRIMO-BOX', 'name' => 'Brimo Box', 'role' => 'main', 'list_price' => 60.0], ['key' => 'PEXA-SALAD', 'name' => 'Pexa Salad', 'role' => 'side', 'list_price' => 30.0]], 'suggested_price' => 85.0, 'cost' => 58.0, 'margin_pct' => 0.32, 'discount_pct' => 0.06],
        ], 0.55);
        $answer = "«كومبو مقترح»\n- العرض اللي رجعه المتخصص";
        $this->app->instance(Assistant::class, $this->scriptedAssistant([
            'كومبو سليم' => [[['marketing_specialist', 'تقرير'], ['combo_builder', $good]], $answer],
            'كومبو برئيسيين' => [[['marketing_specialist', 'تقرير'], ['combo_builder', $twoMains]], $answer],
            'كومبو تحت الحد' => [[['marketing_specialist', 'تقرير'], ['combo_builder', $belowFloor]], $answer],
        ]));

        $rule = 'combos_one_main_above_floor';
        $report = (new EvalRunner($this->checker, $this->fixture))->run(customCases: [
            $this->ruleCase('combo_ok', 'كومبو سليم', [$rule]),
            $this->ruleCase('combo_two_mains', 'كومبو برئيسيين', [$rule]),
            $this->ruleCase('combo_below_floor', 'كومبو تحت الحد', [$rule]),
        ]);

        $this->assertTrue($report->cases[0]->passed);
        $this->assertFalse($report->cases[1]->passed);
        $this->assertSame([$rule], array_keys($report->cases[1]->ruleFailures));
        $this->assertFalse($report->cases[2]->passed);
        $this->assertSame([$rule], array_keys($report->cases[2]->ruleFailures));
    }

    public function test_combos_rule_without_a_combo_builder_call_passes_only_without_proposed_combos(): void
    {
        $this->app->instance(Assistant::class, $this->scriptedAssistant([
            'بدون كومبو' => [[['marketing_specialist', 'تقرير']], "«ركز على»\n- Double Burger\n«كومبو مقترح»\n- المتخصص مرجعش كومبو"],
            'كومبو مخترع' => [[['marketing_specialist', 'تقرير']], "«ركز على»\n- Double Burger\n«كومبو مقترح»\n- Double Burger مع Fries"],
        ]));

        $rule = 'combos_one_main_above_floor';
        $report = (new EvalRunner($this->checker, $this->fixture))->run(customCases: [
            $this->ruleCase('no_combo', 'بدون كومبو', [$rule]),
            $this->ruleCase('invented_combo', 'كومبو مخترع', [$rule]),
        ]);

        $this->assertTrue($report->cases[0]->passed);
        $this->assertFalse($report->cases[1]->passed);
        $this->assertSame([$rule], array_keys($report->cases[1]->ruleFailures));
    }

    public function test_menu_cases_run_as_the_menu_fixture_with_owner_set_roles(): void
    {
        // A stand-in for the (heavy) seeded MenuFixture: the runner only needs its organization and users.
        $menu = new MenuFixture;
        $menu->organization = $this->fixture->organization;
        $menu->owner = $this->fixture->owner;
        $menu->managerA = $this->fixture->managerA;

        $askedBy = null;
        $this->app->instance(Assistant::class, $this->scriptedAssistant(
            ['سؤال قائمة' => [[], 'إجابة']],
            function (User $user) use (&$askedBy): void {
                $askedBy = $user->id;
            },
        ));

        $report = (new EvalRunner($this->checker, null, $menu))->run(customCases: [
            $this->ruleCase('menu_case', 'سؤال قائمة', ['focus_excludes_roles:drink,addon,adjustment,raw'], 'menu'),
        ]);

        $this->assertTrue($report->cases[0]->passed);
        $this->assertSame($this->fixture->owner->id, $askedBy);
        $roles = app(ItemRoles::class)->mapFor($this->fixture->organization->id);
        $this->assertSame(ItemRole::Main, $roles->roleOf('ZORBA-PLATE'));
        $this->assertSame(ItemRole::Drink, $roles->roleOf('TINY-SPRING'));
        $this->assertSame(ItemRole::Raw, $roles->roleOf('BULK-FLOUR'));
        // Every menu item has a trusted owner role, so tools never need the role classifier.
        $menuKeys = ['ZORBA-PLATE', 'KELTO-WRAP', 'BRIMO-BOX', 'NADD-BOWL', 'FETA-TOWER', 'QUILL-STEW', 'LUMO-RICE',
            'PEXA-SALAD', 'VIRA-SODA', 'TINY-SPRING', 'GLAZE-DIP', 'DIFF-LINE', 'BULK-FLOUR'];
        $this->assertSame([], $roles->untrustedAmong($menuKeys));
        $this->assertSame(13, ItemRoleAssignment::query()
            ->where('organization_id', $this->fixture->organization->id)
            ->where('source', 'owner')
            ->count());
    }

    public function test_unknown_fixture_is_rejected(): void
    {
        $this->app->instance(Assistant::class, $this->scriptedAssistant(['سؤال' => [[], 'إجابة']]));

        $this->expectException(InvalidArgumentException::class);
        (new EvalRunner($this->checker, $this->fixture))->run(customCases: [
            $this->ruleCase('bad_fixture', 'سؤال', ['grounded'], 'nope'),
        ]);
    }

    public function test_grounded_rule_uses_the_numeric_grounding_checker(): void
    {
        $kpiResult = json_encode(['metrics' => ['K01' => ['value' => 12780.0]]], JSON_THROW_ON_ERROR);
        $this->app->instance(Assistant::class, $this->scriptedAssistant([
            'رقم صحيح' => [[['get_kpis', $kpiResult]], 'صافي المبيعات 12,780 ج.م'],
            'رقم مخترع' => [[['get_kpis', $kpiResult]], 'صافي المبيعات 99,999 ج.م'],
        ]));

        $report = (new EvalRunner($this->checker, $this->fixture))->run(customCases: [
            $this->ruleCase('grounded', 'رقم صحيح', ['grounded']),
            $this->ruleCase('ungrounded', 'رقم مخترع', ['grounded']),
        ]);

        $this->assertTrue($report->cases[0]->rulesPassed);
        $this->assertTrue($report->cases[0]->passed);
        $this->assertFalse($report->cases[1]->rulesPassed);
        $this->assertSame(['grounded'], array_keys($report->cases[1]->ruleFailures));
    }

    public function test_unknown_rule_fails_the_case(): void
    {
        $this->app->instance(Assistant::class, $this->scriptedAssistant([
            'سؤال' => [[], 'إجابة'],
        ]));

        $report = (new EvalRunner($this->checker, $this->fixture))->run(customCases: [
            $this->ruleCase('typo', 'سؤال', ['no_delegaton']),
        ]);

        $this->assertFalse($report->cases[0]->passed);
        $this->assertSame(['no_delegaton'], array_keys($report->cases[0]->ruleFailures));
    }

    public function test_v1_dataset_covers_the_specialists_with_rules_from_the_closed_set(): void
    {
        $cases = (new EvalRunner($this->checker, $this->fixture))->loadCases(version: 'v1');

        $this->assertGreaterThanOrEqual(12, count($cases));
        $byCategory = array_count_values(array_column($cases, 'category'));
        $this->assertGreaterThanOrEqual(4, $byCategory['marketing'] ?? 0);
        $this->assertGreaterThanOrEqual(3, $byCategory['finance'] ?? 0);
        $this->assertGreaterThanOrEqual(3, $byCategory['operations'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $byCategory['kpis'] ?? 0);

        $closedSet = '/^(no_delegation|grounded|combos_one_main_above_floor|delegates:(marketing_specialist|finance_specialist|operations_specialist|data_checker)|focus_excludes_roles:[a-z]+(,[a-z]+)*)$/';
        foreach ($cases as $case) {
            $this->assertContains($case['fixture'] ?? null, ['two_branches', 'menu'], $case['id']);
            $this->assertNotEmpty($case['rules'] ?? [], $case['id']);
            foreach ($case['rules'] ?? [] as $rule) {
                $this->assertMatchesRegularExpression($closedSet, $rule, $case['id']);
            }
            if ($case['category'] === 'kpis') {
                $this->assertContains('no_delegation', $case['rules'] ?? [], $case['id']);
            }
            if ($case['category'] === 'marketing') {
                $this->assertSame('menu', $case['fixture'] ?? null, $case['id']);
                $this->assertContains('delegates:marketing_specialist', $case['rules'] ?? [], $case['id']);
                $this->assertContains('focus_excludes_roles:drink,addon,adjustment,raw', $case['rules'] ?? [], $case['id']);
                $this->assertContains('combos_one_main_above_floor', $case['rules'] ?? [], $case['id']);
            }
        }
    }

    /**
     * @param  list<string>  $rules
     * @return array{id: string, category: string, persona: string, question: string, expected_tools: list<string>, expected_numbers: list<int|float>, must_refuse: bool, fixture: string, rules: list<string>}
     */
    private function ruleCase(string $id, string $question, array $rules, string $fixture = 'two_branches'): array
    {
        return [
            'id' => $id,
            'category' => 'rules',
            'persona' => 'owner',
            'question' => $question,
            'expected_tools' => [],
            'expected_numbers' => [],
            'must_refuse' => false,
            'fixture' => $fixture,
            'rules' => $rules,
        ];
    }

    /**
     * A combo_builder tool payload in the shape the real tool returns (metrics = the ComboBuilder result).
     *
     * @param  list<array<string, mixed>>  $combos
     */
    private function comboResult(array $combos, float $floor): string
    {
        return json_encode([
            'summary' => 'جهزت عروض بهامش ربح آمن.',
            'metrics' => ['status' => 'ok', 'margin_floor_pct' => $floor, 'combos' => $combos],
        ], JSON_THROW_ON_ERROR);
    }

    /** Owner-set roles for the TwoBranchesTwoWeeks catalog: burgers are mains, fries a side, cola and water drinks. */
    private function seedFixtureRoles(): void
    {
        $roles = app(ItemRoles::class);
        $orgId = $this->fixture->organization->id;
        foreach (['BURGER-1', 'BURGER-2', 'BURGER-3'] as $key) {
            $roles->setOwnerRole($orgId, $key, ItemRole::Main);
        }
        $roles->setOwnerRole($orgId, 'SIDE-1', ItemRole::Side);
        $roles->setOwnerRole($orgId, 'DRINK-1', ItemRole::Drink);
        $roles->setOwnerRole($orgId, 'DRINK-2', ItemRole::Drink);
    }

    /**
     * An assistant that replays a canned transcript per question: the tool invocations it reports
     * (as ToolInvoked events) and the final answer text.
     *
     * @param  array<string, array{0: list<array{0: string, 1: string}>, 1: string}>  $transcripts
     * @param  (Closure(User): void)|null  $onAsk
     */
    private function scriptedAssistant(array $transcripts, ?Closure $onAsk = null): Assistant
    {
        $tools = [];
        foreach ($transcripts as [$invocations]) {
            foreach ($invocations as [$name]) {
                $tools[$name] ??= $this->createFakeTool($name);
            }
        }

        return new class($transcripts, $tools, $onAsk) implements Assistant
        {
            /**
             * @param  array<string, array{0: list<array{0: string, 1: string}>, 1: string}>  $transcripts
             * @param  array<string, Tool>  $tools
             * @param  (Closure(User): void)|null  $onAsk
             */
            public function __construct(private readonly array $transcripts, private readonly array $tools, private readonly ?Closure $onAsk) {}

            public function ask(User $user, Channel $channel, ?string $conversationId, string $text): AssistantReply
            {
                if ($this->onAsk !== null) {
                    ($this->onAsk)($user);
                }
                [$invocations, $answer] = $this->transcripts[$text];
                foreach ($invocations as $index => [$name, $result]) {
                    event(new ToolInvoked(
                        invocationId: 'inv-'.$index,
                        toolInvocationId: 'tool-inv-'.$index,
                        agent: new AnonymousAgent('', [], []),
                        tool: $this->tools[$name],
                        arguments: [],
                        result: $result,
                        time: 1.0,
                    ));
                }

                return new AssistantReply(text: $answer, conversationId: 'conv-scripted');
            }

            public function stream(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): Generator
            {
                yield AssistantEvent::done('conv-stream');
            }

            public function start(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): ?string
            {
                return $conversationId;
            }

            public function cancel(string $runId): void {}
        };
    }
}
