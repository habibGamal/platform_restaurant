<?php

declare(strict_types=1);

namespace App\Domain\AiBilling;

use App\Enums\OrganizationPlan;
use App\Models\AiSetting;
use App\Models\AiUsageLedgerEntry;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class BudgetGuard
{
    /**
     * Total spend in EGP for the organization in the month containing $at (defaults to current month).
     */
    public function monthSpendEgp(Organization $organization, ?CarbonInterface $at = null): float
    {
        $now = $at !== null ? Carbon::instance($at) : Carbon::now();
        $start = $now->copy()->startOfMonth();
        $end = $now->copy()->endOfMonth();

        return (float) AiUsageLedgerEntry::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('cost_egp');
    }

    /**
     * Total spend in EGP for a specific user in the month containing $at (defaults to current month).
     */
    public function userMonthSpendEgp(User $user, ?CarbonInterface $at = null): float
    {
        $now = $at !== null ? Carbon::instance($at) : Carbon::now();
        $start = $now->copy()->startOfMonth();
        $end = $now->copy()->endOfMonth();

        return (float) AiUsageLedgerEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('cost_egp');
    }

    /**
     * Monthly budget in EGP for the organization: from ai_settings if set, otherwise plan default from config.
     */
    public function budgetFor(Organization $organization): float
    {
        $setting = AiSetting::query()->where('organization_id', $organization->id)->first();
        if ($setting !== null && $setting->monthly_budget_egp !== null) {
            return (float) $setting->monthly_budget_egp;
        }

        $plan = $organization->getAttribute('plan');
        $planKey = $plan instanceof OrganizationPlan ? $plan->value : (string) $plan;

        return (float) config("ai_billing.default_monthly_budgets.{$planKey}", 0.0);
    }

    /**
     * Determine budget state for the organization:
     * - exhausted: spend >= 100% of budget with hard_stop enabled
     * - warning: spend / budget >= warn_at ratio (default 0.80)
     * - ok: below warn_at ratio
     */
    public function state(Organization $organization, ?CarbonInterface $at = null): BudgetState
    {
        $budget = $this->budgetFor($organization);
        $spend = $this->monthSpendEgp($organization, $at);
        $setting = AiSetting::query()->where('organization_id', $organization->id)->first();

        $warnAtRatio = $setting !== null ? (float) $setting->warn_at : 0.80;
        $hardStop = $setting !== null ? (bool) $setting->hard_stop : true;

        if ($budget <= 0.0) {
            return $hardStop ? BudgetState::EXHAUSTED : BudgetState::OK;
        }

        $ratio = $spend / $budget;

        if ($ratio >= 1.0 && $hardStop) {
            return BudgetState::EXHAUSTED;
        }

        if ($ratio >= $warnAtRatio) {
            return BudgetState::WARNING;
        }

        return BudgetState::OK;
    }

    /**
     * Assert that the user is allowed to run the assistant.
     * Throws BudgetExceededException if org budget is exhausted or per-user cap is exceeded.
     *
     * @throws BudgetExceededException
     */
    public function assertCanRun(User $user, ?CarbonInterface $at = null): void
    {
        $organization = $user->organization;
        if ($organization === null) {
            throw new BudgetExceededException(
                message: 'المستخدم غير مرتبط بأي مؤسسة لاستخدام ميزانية المساعد الذكي.',
                scope: 'organization',
            );
        }

        $state = $this->state($organization, $at);
        if ($state === BudgetState::EXHAUSTED) {
            $budget = $this->budgetFor($organization);
            $spend = $this->monthSpendEgp($organization, $at);

            throw new BudgetExceededException(
                message: 'تم استنفاد ميزانية الذكاء الاصطناعي الشهرية للمؤسسة بالكامل. يرجى مراجعة إعدادات الميزانية أو التواصل مع مالك الحساب.',
                scope: 'organization',
                budget: $budget,
                spend: $spend,
            );
        }

        $setting = AiSetting::query()->where('organization_id', $organization->id)->first();
        if ($setting !== null && $setting->per_user_monthly_egp !== null && (float) $setting->per_user_monthly_egp > 0) {
            $userCap = (float) $setting->per_user_monthly_egp;
            $userSpend = $this->userMonthSpendEgp($user, $at);

            if ($userSpend >= $userCap) {
                throw new BudgetExceededException(
                    message: 'تم استنفاد الحد الشهري المخصص لك لاستخدام المساعد الذكي. يرجى التواصل مع مسؤول المؤسسة.',
                    scope: 'user',
                    budget: $userCap,
                    spend: $userSpend,
                );
            }
        }
    }

    /**
     * Check whether adding $additionalEgp will exceed the monthly budget.
     * Used by the EnforceBudget in-run middleware.
     */
    public function projectedExceeds(Organization $organization, float $additionalEgp, ?CarbonInterface $at = null): bool
    {
        $setting = AiSetting::query()->where('organization_id', $organization->id)->first();
        $hardStop = $setting !== null ? (bool) $setting->hard_stop : true;

        if (! $hardStop) {
            return false;
        }

        $budget = $this->budgetFor($organization);
        if ($budget <= 0.0) {
            return true;
        }

        $projectedSpend = $this->monthSpendEgp($organization, $at) + $additionalEgp;

        return $projectedSpend >= $budget;
    }
}
