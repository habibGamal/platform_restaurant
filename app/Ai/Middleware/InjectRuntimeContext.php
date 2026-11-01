<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Domain\AiBilling\BudgetGuard;
use App\Domain\AiBilling\BudgetState;
use App\Domain\Assistant\Channel;
use App\Domain\Tenancy\AccessibleBranches;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;

final class InjectRuntimeContext
{
    public function __construct(
        private readonly User $user,
        private readonly Channel $channel,
        private readonly ?BudgetGuard $budgetGuard = null,
    ) {}

    public function handle(PendingStep $step, Closure $next): StepResult
    {
        $organization = $this->user->organization ?? ($this->user->organization_id !== null ? Organization::find($this->user->organization_id) : null);

        /** @var string $configuredTz */
        $configuredTz = config('app.timezone', 'Africa/Cairo');
        $timezone = $organization !== null ? (string) $organization->timezone : $configuredTz;

        $now = Carbon::now($timezone);
        $today = $now->toDateString();
        $time = $now->toTimeString();

        /** @var list<string> $roles */
        $roles = $this->user->getRoleNames()->values()->all();
        $roleStr = empty($roles) ? 'member' : implode(', ', $roles);

        $branchCount = AccessibleBranches::query($this->user)->count();

        $channelStr = $this->channel->value;

        $guard = $this->budgetGuard ?? resolve(BudgetGuard::class);
        $budgetState = $organization !== null ? $guard->state($organization)->value : BudgetState::OK->value;

        $contextNote = sprintf(
            '[Runtime Context: Today is %s %s (%s). User role: %s. Accessible branches count: %d. Channel: %s. Budget state: %s.]',
            $today,
            $time,
            $timezone,
            $roleStr,
            $branchCount,
            $channelStr,
            $budgetState,
        );

        $contextMessage = new UserMessage($contextNote);

        $stepWithContext = $step->withMessages([...$step->messages, $contextMessage]);

        return $next($stepWithContext);
    }
}
