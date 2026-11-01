<?php

declare(strict_types=1);

namespace Tests\Feature\AiBilling;

use App\Domain\AiBilling\BudgetExceededException;
use App\Domain\AiBilling\BudgetGuard;
use App\Domain\AiBilling\BudgetState;
use App\Enums\OrganizationPlan;
use App\Models\AiSetting;
use App\Models\AiUsageLedgerEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BudgetGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_for_uses_setting_when_defined_otherwise_plan_default(): void
    {
        $guard = new BudgetGuard;

        $orgWithCustomBudget = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);
        AiSetting::factory()->create([
            'organization_id' => $orgWithCustomBudget->id,
            'monthly_budget_egp' => 2500.00,
        ]);

        $this->assertSame(2500.0, $guard->budgetFor($orgWithCustomBudget));

        $orgWithPlanDefault = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);
        AiSetting::factory()->create([
            'organization_id' => $orgWithPlanDefault->id,
            'monthly_budget_egp' => null,
        ]);

        $this->assertSame(1500.0, $guard->budgetFor($orgWithPlanDefault));
    }

    public function test_budget_state_is_ok_at_zero_spend(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');

        $guard = new BudgetGuard;
        $org = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);
        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'monthly_budget_egp' => 1000.00,
            'warn_at' => 0.80,
            'hard_stop' => true,
        ]);

        $this->assertSame(BudgetState::OK, $guard->state($org));
    }

    public function test_budget_state_is_ok_below_warn_at(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');

        $guard = new BudgetGuard;
        $org = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);
        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'monthly_budget_egp' => 1000.00,
            'warn_at' => 0.80,
            'hard_stop' => true,
        ]);

        AiUsageLedgerEntry::factory()->create([
            'organization_id' => $org->id,
            'cost_egp' => 799.00,
            'created_at' => now(),
        ]);

        $this->assertSame(BudgetState::OK, $guard->state($org));
    }

    public function test_budget_state_is_warning_at_or_above_warn_at(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');

        $guard = new BudgetGuard;
        $org = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);
        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'monthly_budget_egp' => 1000.00,
            'warn_at' => 0.80,
            'hard_stop' => true,
        ]);

        AiUsageLedgerEntry::factory()->create([
            'organization_id' => $org->id,
            'cost_egp' => 800.00,
            'created_at' => now(),
        ]);

        $this->assertSame(BudgetState::WARNING, $guard->state($org));
    }

    public function test_budget_state_is_exhausted_at_100_percent_with_hard_stop(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');

        $guard = new BudgetGuard;
        $org = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);
        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'monthly_budget_egp' => 1000.00,
            'warn_at' => 0.80,
            'hard_stop' => true,
        ]);

        AiUsageLedgerEntry::factory()->create([
            'organization_id' => $org->id,
            'cost_egp' => 1000.00,
            'created_at' => now(),
        ]);

        $this->assertSame(BudgetState::EXHAUSTED, $guard->state($org));
    }

    public function test_budget_state_is_warning_at_100_percent_without_hard_stop(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');

        $guard = new BudgetGuard;
        $org = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);
        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'monthly_budget_egp' => 1000.00,
            'warn_at' => 0.80,
            'hard_stop' => false,
        ]);

        AiUsageLedgerEntry::factory()->create([
            'organization_id' => $org->id,
            'cost_egp' => 1050.00,
            'created_at' => now(),
        ]);

        $this->assertSame(BudgetState::WARNING, $guard->state($org));
    }

    public function test_assert_can_run_throws_budget_exceeded_exception_with_arabic_message_when_exhausted(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');

        $guard = new BudgetGuard;
        $org = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);
        $user = User::factory()->forOrganization($org)->create();

        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'monthly_budget_egp' => 500.00,
            'hard_stop' => true,
        ]);

        AiUsageLedgerEntry::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'cost_egp' => 500.00,
            'created_at' => now(),
        ]);

        $this->expectException(BudgetExceededException::class);
        $this->expectExceptionMessage('تم استنفاد ميزانية الذكاء الاصطناعي الشهرية للمؤسسة بالكامل. يرجى مراجعة إعدادات الميزانية أو التواصل مع مالك الحساب.');

        $guard->assertCanRun($user);
    }

    public function test_assert_can_run_enforces_per_user_cap_with_arabic_message(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');

        $guard = new BudgetGuard;
        $org = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);
        $userA = User::factory()->forOrganization($org)->create();

        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'monthly_budget_egp' => 5000.00, // Org has plenty of budget
            'per_user_monthly_egp' => 200.00, // User cap is 200 EGP
            'hard_stop' => true,
        ]);

        // User A spends 200 EGP
        AiUsageLedgerEntry::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $userA->id,
            'cost_egp' => 200.00,
            'created_at' => now(),
        ]);

        // User A reached their cap -> throws dedicated exception
        $this->expectException(BudgetExceededException::class);
        $this->expectExceptionMessage('تم استنفاد الحد الشهري المخصص لك لاستخدام المساعد الذكي. يرجى التواصل مع مسؤول المؤسسة.');

        $guard->assertCanRun($userA);
    }

    public function test_assert_can_run_succeeds_when_user_is_under_cap(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');

        $guard = new BudgetGuard;
        $org = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);
        $userB = User::factory()->forOrganization($org)->create();

        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'monthly_budget_egp' => 5000.00,
            'per_user_monthly_egp' => 200.00,
            'hard_stop' => true,
        ]);

        // User B has zero spend -> assertCanRun succeeds
        $this->expectNotToPerformAssertions();
        $guard->assertCanRun($userB);
    }

    public function test_projected_exceeds_computes_in_run_threshold(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');

        $guard = new BudgetGuard;
        $org = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);

        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'monthly_budget_egp' => 1000.00,
            'hard_stop' => true,
        ]);

        AiUsageLedgerEntry::factory()->create([
            'organization_id' => $org->id,
            'cost_egp' => 950.00,
            'created_at' => now(),
        ]);

        // Adding 40 EGP is 990 -> does not exceed 1000
        $this->assertFalse($guard->projectedExceeds($org, 40.00));

        // Adding 50 EGP is 1000 -> exceeds/hits 100%
        $this->assertTrue($guard->projectedExceeds($org, 50.00));

        // Adding 100 EGP is 1050 -> exceeds
        $this->assertTrue($guard->projectedExceeds($org, 100.00));

        // If hard_stop is disabled, projectedExceeds returns false
        AiSetting::query()->where('organization_id', $org->id)->update(['hard_stop' => false]);
        $this->assertFalse($guard->projectedExceeds($org, 100.00));
    }

    public function test_spend_is_scoped_to_current_month(): void
    {
        Carbon::setTestNow('2026-11-15 12:00:00');

        $guard = new BudgetGuard;
        $org = Organization::factory()->create(['plan' => OrganizationPlan::PRO]);
        $user = User::factory()->forOrganization($org)->create();

        // Entry from previous month (October)
        AiUsageLedgerEntry::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'cost_egp' => 300.00,
            'created_at' => Carbon::parse('2026-10-31 23:59:59'),
        ]);

        // Entry from current month (November)
        AiUsageLedgerEntry::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'cost_egp' => 150.00,
            'created_at' => Carbon::parse('2026-11-02 10:00:00'),
        ]);

        $this->assertSame(150.0, $guard->monthSpendEgp($org));
        $this->assertSame(150.0, $guard->userMonthSpendEgp($user));
    }
}
