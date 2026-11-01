<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Domain\AiBilling\BudgetGuard;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;

final class EnforceBudget
{
    public function __construct(
        private readonly User|Organization $userOrOrg,
        private readonly ?BudgetGuard $budgetGuard = null,
    ) {}

    public function handle(PendingStep $step, Closure $next): StepResult
    {
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

        return $next($step);
    }
}
