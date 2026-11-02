<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Evals;

use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\AssistantReply;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\Contracts\Assistant;
use App\Domain\Assistant\Evals\EvalRunner;
use App\Domain\Assistant\NumericGroundingChecker;
use App\Models\User;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\Request;
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
}
