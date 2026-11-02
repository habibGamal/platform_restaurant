<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Domain\AiBilling\BudgetGuard;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;

final class EnforceBudget
{
    private ?string $fallbackInvocationId = null;

    public function __construct(
        private readonly User|Organization $userOrOrg,
        private readonly ?BudgetGuard $budgetGuard = null,
        private readonly ?string $runId = null,
    ) {}

    public function handle(PendingStep $step, Closure $next): StepResult
    {
        if ($this->runId !== null) {
            $total = $this->recordInputTokens($step, $step->usage->inputTokens);
            $questionCap = (int) config('ai_billing.per_question_input_token_cap', 240000);
            if ($questionCap > 0 && $total >= $questionCap) {
                return StepResult::stop();
            }
        }

        $organization = $this->userOrOrg instanceof User
            ? ($this->userOrOrg->organization ?? ($this->userOrOrg->organization_id !== null ? Organization::find($this->userOrOrg->organization_id) : null))
            : $this->userOrOrg;

        // Check per-run input token cap
        $tokenCap = (int) config('ai_billing.per_run_input_token_cap', 120000);
        if ($tokenCap > 0 && $step->usage->inputTokens >= $tokenCap) {
            return StepResult::stop();
        }

        // Check if organization monthly budget is projected to exceed
        if ($organization !== null) {
            $guard = $this->budgetGuard ?? resolve(BudgetGuard::class);
            if ($guard->projectedExceeds($organization, 0.0)) {
                return StepResult::stop();
            }
        }

        $result = $next($step);

        if ($this->runId !== null) {
            $result->then(function (StepResponse $response) use ($step): void {
                $this->recordInputTokens($step, $step->usage->inputTokens + $response->usage->inputTokens);
            });
        }

        return $result;
    }

    private function recordInputTokens(PendingStep $step, int $cumulativeTokens): int
    {
        $key = "ai:run_tokens:{$this->runId}";
        $invocationId = $step->invocationId ?? ($this->fallbackInvocationId ??= (string) Str::uuid());
        // Failover reuses invocationId but starts a new cumulative total. The SDK's
        // provider=>model failover map visits each provider at most once.
        $checkpointKey = "{$key}:invocation:{$invocationId}:provider:{$step->provider}";

        // PendingStep usage is cumulative for one invocation. Serialize the checkpoint
        // and shared total together so concurrent specialists cannot lose updates.
        return Cache::lock("{$key}:lock", 10)->block(5, function () use ($key, $checkpointKey, $cumulativeTokens): int {
            $previous = (int) Cache::get($checkpointKey, 0);
            $delta = max(0, $cumulativeTokens - $previous);
            $total = (int) Cache::get($key, 0) + $delta;

            Cache::put($key, $total, 3600);
            Cache::put($checkpointKey, max($previous, $cumulativeTokens), 3600);

            return $total;
        });
    }
}
