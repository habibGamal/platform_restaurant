<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Agent;

use App\Ai\Agents\RestaurantAnalyst;
use App\Ai\Middleware\EnforceBudget;
use App\Domain\Assistant\Channel;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RunBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'ai_billing.per_run_input_token_cap' => 200000]);
    }

    public function test_agents_share_one_question_cap(): void
    {
        $runId = (string) Str::uuid();
        $first = new EnforceBudget(new User, runId: $runId);
        $second = new EnforceBudget(new User, runId: $runId);

        $this->assertFalse($this->observe($first, 130000, 'first')->stopped());
        $this->assertTrue($this->observe($second, 110000, 'second')->stopped());
        $this->assertSame(240000, Cache::get("ai:run_tokens:{$runId}"));
    }

    public function test_run_ids_have_separate_totals(): void
    {
        $first = new EnforceBudget(new User, runId: (string) Str::uuid());
        $second = new EnforceBudget(new User, runId: (string) Str::uuid());

        $this->assertFalse($this->observe($first, 150000, 'first')->stopped());
        $this->assertFalse($this->observe($second, 150000, 'second')->stopped());
    }

    public function test_cumulative_usage_is_counted_once_across_middleware_instances(): void
    {
        $runId = (string) Str::uuid();
        $first = new EnforceBudget(new User, runId: $runId);
        $recreated = new EnforceBudget(new User, runId: $runId);

        $this->observe($first, 50000, 'same-invocation');
        $this->observe($recreated, 50000, 'same-invocation');
        $this->observe($recreated, 80000, 'same-invocation');

        $this->assertSame(80000, Cache::get("ai:run_tokens:{$runId}"));
    }

    public function test_final_step_usage_counts_before_another_agent_starts(): void
    {
        config(['ai_billing.per_question_input_token_cap' => 100]);
        $runId = (string) Str::uuid();
        $first = new EnforceBudget(new User, runId: $runId);
        $second = new EnforceBudget(new User, runId: $runId);

        $result = $first->handle($this->step(0, 'first'), fn (): StepResult => $this->response(100));
        $result->response();

        $this->assertSame(100, Cache::get("ai:run_tokens:{$runId}"));
        $this->assertTrue($this->observe($second, 0, 'second')->stopped());
    }

    public function test_completed_response_and_next_pending_step_do_not_double_count(): void
    {
        $runId = (string) Str::uuid();
        $middleware = new EnforceBudget(new User, runId: $runId);
        $middleware->handle($this->step(10000, 'agent'), fn (): StepResult => $this->response(20000))->response();
        $this->observe($middleware, 30000, 'agent');

        $this->assertSame(30000, Cache::get("ai:run_tokens:{$runId}"));
    }

    public function test_provider_failover_counts_usage_when_the_invocation_id_is_reused(): void
    {
        $runId = (string) Str::uuid();
        $primary = new EnforceBudget(new User, runId: $runId);
        $fallback = new EnforceBudget(new User, runId: $runId);

        $primary->handle($this->step(0, 'same-invocation'), fn (): StepResult => $this->response(100000))->response();
        $fallbackStep = new PendingStep(
            number: 0, isFinalStep: false, provider: 'anthropic', model: 'fallback-model',
            instructions: null, messages: [], tools: [], schema: null, options: null,
            invocationId: 'same-invocation',
        );
        $fallback->handle($fallbackStep, fn (): StepResult => $this->response(100000))->response();

        $this->assertSame(200000, Cache::get("ai:run_tokens:{$runId}"));
    }

    public function test_interleaved_agents_preserve_both_completed_responses(): void
    {
        $runId = (string) Str::uuid();
        $first = new EnforceBudget(new User, runId: $runId);
        $second = new EnforceBudget(new User, runId: $runId);

        $outer = $first->handle($this->step(0, 'first'), fn (): StepResult => $this->response(17000));
        $inner = $second->handle($this->step(0, 'second'), fn (): StepResult => $this->response(23000));
        $inner->response();
        $outer->response();
        $this->observe($first, 17000, 'first');
        $this->observe($second, 23000, 'second');

        $this->assertSame(40000, Cache::get("ai:run_tokens:{$runId}"));
    }

    public function test_shared_total_expires_after_one_hour(): void
    {
        $runId = (string) Str::uuid();
        $this->observe(new EnforceBudget(new User, runId: $runId), 500, 'agent');

        $this->travel(61)->minutes();
        $this->assertNull(Cache::get("ai:run_tokens:{$runId}"));
        $this->travelBack();
    }

    public function test_concurrent_workers_do_not_lose_updates_in_a_shared_cache(): void
    {
        $runId = (string) Str::uuid();
        $cachePath = tempnam(sys_get_temp_dir(), 'run-budget-');
        $this->assertNotFalse($cachePath);
        config([
            'cache.default' => 'database',
            'cache.prefix' => 'run-budget-test-',
            'cache.stores.database.connection' => 'run_budget',
            'cache.stores.database.lock_connection' => 'run_budget',
            'database.connections.run_budget' => ['driver' => 'sqlite', 'database' => $cachePath, 'busy_timeout' => 5000, 'journal_mode' => 'WAL'],
        ]);
        Schema::connection('run_budget')->create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });
        Schema::connection('run_budget')->create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        Cache::purge('database');
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'cache.default' => 'database', 'cache.prefix' => 'run-budget-test-',
    'cache.stores.database.connection' => 'run_budget', 'cache.stores.database.lock_connection' => 'run_budget',
    'database.connections.run_budget' => ['driver' => 'sqlite', 'database' => $argv[2], 'busy_timeout' => 5000, 'journal_mode' => 'WAL'],
]);
$middleware = new App\Ai\Middleware\EnforceBudget(new App\Models\User, runId: $argv[3]);
for ($tokens = 1; $tokens <= 30; $tokens++) {
    $step = new Laravel\Ai\PendingStep(1, false, 'openai', 'test-model', null, [], [], null, null,
        usage: new Laravel\Ai\Responses\Data\TextUsage(inputTokens: $tokens), invocationId: $argv[4]);
    $middleware->handle($step, fn () => new Laravel\Ai\Gateway\StepResult(null));
}
PHP;
        $processes = [];

        try {
            for ($i = 0; $i < 4; $i++) {
                $process = new Process([PHP_BINARY, '-r', $worker, base_path(), $cachePath, $runId, "worker-{$i}"]);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            }

            $this->assertSame(120, Cache::get("ai:run_tokens:{$runId}"));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            Cache::purge('database');
            DB::purge('run_budget');
            unlink($cachePath);
        }
    }

    public function test_missing_run_id_keeps_the_existing_per_agent_cap(): void
    {
        config(['ai_billing.per_run_input_token_cap' => 100]);
        $this->assertTrue($this->observe(new EnforceBudget(new User), 100, 'agent')->stopped());
    }

    public function test_coordinator_passes_its_run_id_to_the_budget_middleware(): void
    {
        config(['ai_billing.per_question_input_token_cap' => 100]);
        $runId = (string) Str::uuid();
        Cache::put("ai:run_tokens:{$runId}", 100, 3600);
        $agent = new RestaurantAnalyst(new User, Channel::Chat, $runId);
        $middleware = array_values(array_filter($agent->middleware(), fn (object $item): bool => $item instanceof EnforceBudget))[0];

        $this->assertTrue($this->observe($middleware, 0, 'agent')->stopped());
    }

    private function observe(EnforceBudget $middleware, int $tokens, string $invocationId): StepResult
    {
        return $middleware->handle($this->step($tokens, $invocationId), fn (): StepResult => $this->response(0));
    }

    private function step(int $tokens, string $invocationId): PendingStep
    {
        return new PendingStep(
            number: 1, isFinalStep: false, provider: 'openai', model: 'test-model',
            instructions: null, messages: [], tools: [], schema: null, options: null,
            usage: new TextUsage(inputTokens: $tokens), invocationId: $invocationId,
        );
    }

    private function response(int $tokens): StepResult
    {
        return new StepResult(new StepResponse('', [], FinishReason::Stop, new TextUsage(inputTokens: $tokens), new Meta));
    }
}
