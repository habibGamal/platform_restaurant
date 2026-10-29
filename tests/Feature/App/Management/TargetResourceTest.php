<?php

namespace Tests\Feature\App\Management;

use App\Filament\App\Resources\TargetResource\Pages\CreateTarget;
use App\Filament\App\Resources\TargetResource\Pages\ListTargets;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Target;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class TargetResourceTest extends ManagementTestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $ownerA;

    private User $analystA;

    private User $managerA;

    private Branch $branchA1;

    private Branch $branchA2;

    private Branch $branchB1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->ownerA = User::factory()->forOrganization($this->orgA)->create();
        $this->ownerA->assignRole('org_owner');

        $this->analystA = User::factory()->forOrganization($this->orgA)->create();
        $this->analystA->assignRole('org_analyst');

        $this->managerA = User::factory()->forOrganization($this->orgA)->create();
        $this->managerA->assignRole('branch_manager');

        $restA = Restaurant::factory()->create(['organization_id' => $this->orgA->id]);
        $restB = Restaurant::factory()->create(['organization_id' => $this->orgB->id]);

        $this->branchA1 = Branch::factory()->create([
            'organization_id' => $this->orgA->id,
            'restaurant_id' => $restA->id,
            'name' => 'فرع المعادي',
        ]);

        $this->branchA2 = Branch::factory()->create([
            'organization_id' => $this->orgA->id,
            'restaurant_id' => $restA->id,
            'name' => 'فرع التجمع',
        ]);

        $this->branchB1 = Branch::factory()->create([
            'organization_id' => $this->orgB->id,
            'restaurant_id' => $restB->id,
            'name' => 'فرع الإسكندرية',
        ]);

        $this->managerA->branches()->attach([$this->branchA1->id]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_target_list_scoped_to_organization_and_accessible_branches(): void
    {
        $currentMonth = Carbon::now('Africa/Cairo')->startOfMonth()->format('Y-m-d');

        $targetA1 = Target::create([
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->branchA1->id,
            'metric' => 'net_sales',
            'period_month' => $currentMonth,
            'value' => 500000,
            'distribution' => 'weekday_weights',
        ]);

        $targetA2 = Target::create([
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->branchA2->id,
            'metric' => 'net_sales',
            'period_month' => $currentMonth,
            'value' => 300000,
            'distribution' => 'weekday_weights',
        ]);

        $targetB1 = Target::create([
            'organization_id' => $this->orgB->id,
            'branch_id' => $this->branchB1->id,
            'metric' => 'net_sales',
            'period_month' => $currentMonth,
            'value' => 400000,
            'distribution' => 'weekday_weights',
        ]);

        // Manager sees target for branchA1 only
        $this->actingAs($this->managerA);
        Livewire::test(ListTargets::class)
            ->assertCanSeeTableRecords([$targetA1])
            ->assertCanNotSeeTableRecords([$targetA2, $targetB1]);

        // Analyst sees all orgA targets, never orgB
        $this->actingAs($this->analystA);
        Livewire::test(ListTargets::class)
            ->assertCanSeeTableRecords([$targetA1, $targetA2])
            ->assertCanNotSeeTableRecords([$targetB1]);
    }

    public function test_analyst_and_manager_cannot_create_targets(): void
    {
        $this->actingAs($this->analystA);
        $this->assertFalse($this->analystA->can('create', Target::class));

        $this->actingAs($this->managerA);
        $this->assertFalse($this->managerA->can('create', Target::class));
    }

    public function test_target_uniqueness_validation(): void
    {
        $this->actingAs($this->ownerA);
        $month = Carbon::now()->addMonths(2)->startOfMonth()->format('Y-m-d');

        Target::create([
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->branchA1->id,
            'metric' => 'net_sales',
            'period_month' => $month,
            'value' => 100000,
            'distribution' => 'weekday_weights',
        ]);

        Livewire::test(CreateTarget::class)
            ->fillForm([
                'branch_id' => $this->branchA1->id,
                'metric' => 'net_sales',
                'period_month' => $month,
                'value' => 120000,
                'distribution' => 'weekday_weights',
            ])
            ->call('create')
            ->assertHasFormErrors(['period_month']);
    }

    public function test_percent_to_ratio_conversion_for_food_cost_pct(): void
    {
        $this->actingAs($this->ownerA);
        $month = Carbon::now()->addMonths(3)->startOfMonth()->format('Y-m-d');

        Livewire::test(CreateTarget::class)
            ->fillForm([
                'branch_id' => $this->branchA1->id,
                'metric' => 'food_cost_pct',
                'period_month' => $month,
                'value' => 28.5,
                'distribution' => 'weekday_weights',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $target = Target::where('branch_id', $this->branchA1->id)
            ->where('metric', 'food_cost_pct')
            ->where('period_month', $month)
            ->first();

        $this->assertNotNull($target);
        // 28.5% stored as ratio 0.2850
        $this->assertEqualsWithDelta(0.2850, (float) $target->value, 0.0001);
    }

    public function test_copy_previous_month_action(): void
    {
        $this->actingAs($this->ownerA);

        $prevMonth = '2026-05-01';
        $targetMonth = '2026-06-01';

        Target::create([
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->branchA1->id,
            'metric' => 'net_sales',
            'period_month' => $prevMonth,
            'value' => 250000,
            'distribution' => 'weekday_weights',
        ]);

        Target::create([
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->branchA2->id,
            'metric' => 'net_profit',
            'period_month' => $prevMonth,
            'value' => 80000,
            'distribution' => 'flat',
        ]);

        Livewire::test(ListTargets::class)
            ->callAction('copyPreviousMonth', [
                'target_month' => $targetMonth,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('targets', [
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->branchA1->id,
            'metric' => 'net_sales',
            'period_month' => $targetMonth,
            'value' => 250000,
        ]);

        $this->assertDatabaseHas('targets', [
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->branchA2->id,
            'metric' => 'net_profit',
            'period_month' => $targetMonth,
            'value' => 80000,
        ]);
    }

    public function test_copy_last_year_plus_percent_action(): void
    {
        $this->actingAs($this->ownerA);

        $targetMonth = '2026-10-01';
        $lastYearDate = '2025-10-15';

        // Seed agg_branch_daily for branchA1 (actual net_sales 250,000)
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->orgA->id,
            'restaurant_id' => $this->branchA1->restaurant_id,
            'branch_id' => $this->branchA1->id,
            'business_date' => $lastYearDate,
            'weekday' => 1,
            'net_sales_after_returns' => 250000,
            'net_profit' => 50000,
            'orders_count' => 1000,
        ]);

        // BranchA2 has no history (should be skipped)

        Livewire::test(ListTargets::class)
            ->callAction('copyLastYearWithPercent', [
                'target_month' => $targetMonth,
                'metric' => 'net_sales',
                'percent' => 10,
            ])
            ->assertHasNoActionErrors();

        // 250,000 * 1.10 = 275,000
        $this->assertDatabaseHas('targets', [
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->branchA1->id,
            'metric' => 'net_sales',
            'period_month' => $targetMonth,
            'value' => 275000,
        ]);

        // branchA2 skipped
        $this->assertDatabaseMissing('targets', [
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->branchA2->id,
            'metric' => 'net_sales',
            'period_month' => $targetMonth,
        ]);
    }

    public function test_past_months_read_only_rule(): void
    {
        $pastMonth = Carbon::now('Africa/Cairo')->subMonths(3)->startOfMonth()->format('Y-m-d');

        $pastTarget = Target::create([
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->branchA1->id,
            'metric' => 'net_sales',
            'period_month' => $pastMonth,
            'value' => 150000,
            'distribution' => 'weekday_weights',
        ]);

        // Analyst cannot edit or delete past target
        $this->actingAs($this->analystA);
        $this->assertFalse($this->analystA->can('update', $pastTarget));
        $this->assertFalse($this->analystA->can('delete', $pastTarget));

        // Org owner CAN edit or delete past target
        $this->actingAs($this->ownerA);
        $this->assertTrue($this->ownerA->can('update', $pastTarget));
        $this->assertTrue($this->ownerA->can('delete', $pastTarget));
    }
}
