<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use App\Models\Branch;
use App\Models\BranchSet;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class MetricsQueryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Restaurant $rest1;

    private Restaurant $rest2;

    private Branch $branch1;

    private Branch $branch2;

    private Branch $branch3;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->rest1 = Restaurant::factory()->create(['organization_id' => $this->org->id]);
        $this->rest2 = Restaurant::factory()->create(['organization_id' => $this->org->id]);

        $this->branch1 = Branch::factory()->active()->create([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest1->id,
        ]);
        $this->branch2 = Branch::factory()->active()->create([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest1->id,
        ]);
        $this->branch3 = Branch::factory()->active()->create([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest2->id,
        ]);

        $this->owner = User::factory()->forOrganization($this->org)->create();
        $this->owner->assignRole('org_owner');

        $this->manager = User::factory()->forOrganization($this->org)->create();
        $this->manager->assignRole('branch_manager');
        $this->manager->branches()->sync([$this->branch1->id]);
    }

    public function test_branch_resolution_order_default_to_all_accessible(): void
    {
        $filters = new ReportFilters(
            preset: PeriodPreset::today,
            period: new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-07')),
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);
        $this->assertEqualsCanonicalizing([$this->branch1->id, $this->branch2->id, $this->branch3->id], $query->branchIds());

        // Manager only sees assigned branch1
        $managerQuery = MetricsQuery::for($this->manager, $filters);
        $this->assertSame([$this->branch1->id], $managerQuery->branchIds());
    }

    public function test_branch_resolution_order_restaurant_branches(): void
    {
        $filters = new ReportFilters(
            preset: PeriodPreset::today,
            period: new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-07')),
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null,
            branchSetId: null,
            restaurantId: $this->rest1->id,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);
        $this->assertEqualsCanonicalizing([$this->branch1->id, $this->branch2->id], $query->branchIds());
    }

    public function test_branch_resolution_order_branch_set(): void
    {
        $set = BranchSet::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => null, // org-wide
        ]);
        $set->branches()->sync([$this->branch2->id, $this->branch3->id]);

        $filters = new ReportFilters(
            preset: PeriodPreset::today,
            period: new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-07')),
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null,
            branchSetId: $set->id,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);
        $this->assertEqualsCanonicalizing([$this->branch2->id, $this->branch3->id], $query->branchIds());
    }

    public function test_explicit_forbidden_branch_throws_authorization_exception(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherBranch = Branch::factory()->create(['organization_id' => $otherOrg->id]);

        $filters = new ReportFilters(
            preset: PeriodPreset::today,
            period: new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-07')),
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$otherBranch->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $this->expectException(AuthorizationException::class);
        MetricsQuery::for($this->owner, $filters)->branchIds();
    }

    public function test_manager_accessing_unassigned_branch_throws_authorization_exception(): void
    {
        $filters = new ReportFilters(
            preset: PeriodPreset::today,
            period: new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-07')),
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$this->branch2->id], // not assigned to manager
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $this->expectException(AuthorizationException::class);
        MetricsQuery::for($this->manager, $filters)->branchIds();
    }

    public function test_permissions_can_see_group_and_branch_names(): void
    {
        $filters = new ReportFilters(
            preset: PeriodPreset::today,
            period: new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-07')),
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $ownerQuery = MetricsQuery::for($this->owner, $filters);
        $this->assertTrue($ownerQuery->canSeeGroup());
        $this->assertTrue($ownerQuery->canSeeOtherBranchNames());

        $managerQuery = MetricsQuery::for($this->manager, $filters);
        $this->assertFalse($managerQuery->canSeeGroup());
        $this->assertFalse($managerQuery->canSeeOtherBranchNames());
    }

    public function test_like_for_like_filters_branches(): void
    {
        $currentPeriod = new Period(CarbonImmutable::parse('2026-10-08'), CarbonImmutable::parse('2026-10-14'));
        $comparisonPeriod = new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-07'));

        // Branch 1 has data in both periods
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest1->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-05',
            'weekday' => 2,
            'orders_count' => 10,
            'net_sales_after_returns' => 1000.0,
        ]);
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest1->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-10',
            'weekday' => 0,
            'orders_count' => 12,
            'net_sales_after_returns' => 1200.0,
        ]);

        // Branch 2 has data ONLY in the current period
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest1->id,
            'branch_id' => $this->branch2->id,
            'business_date' => '2026-10-10',
            'weekday' => 0,
            'orders_count' => 8,
            'net_sales_after_returns' => 800.0,
        ]);

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $currentPeriod,
            comparisonMode: ComparisonMode::previous,
            comparison: $comparisonPeriod,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: true,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);
        // Branch 1 is kept, Branch 2 and 3 are dropped
        $this->assertSame([$this->branch1->id], $query->branchIds());
    }

    public function test_totals_and_comparison_totals(): void
    {
        $currentPeriod = new Period(CarbonImmutable::parse('2026-10-08'), CarbonImmutable::parse('2026-10-14'));
        $comparisonPeriod = new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-07'));

        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest1->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-09',
            'weekday' => 6,
            'orders_count' => 15,
            'net_sales' => 1600.0,
            'net_sales_after_returns' => 1500.0,
            'cogs' => 500.0,
            'gross_profit' => 1000.0,
            'net_profit' => 800.0,
        ]);

        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest1->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-03',
            'weekday' => 0,
            'orders_count' => 10,
            'net_sales' => 1100.0,
            'net_sales_after_returns' => 1000.0,
            'cogs' => 300.0,
            'gross_profit' => 700.0,
            'net_profit' => 500.0,
        ]);

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $currentPeriod,
            comparisonMode: ComparisonMode::previous,
            comparison: $comparisonPeriod,
            branchIds: [$this->branch1->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);

        $currentTotals = $query->totals();
        $this->assertSame(15, $currentTotals->orders_count);
        $this->assertSame(1500.0, $currentTotals->net_sales_after_returns);
        $this->assertSame(800.0, $currentTotals->net_profit);

        $compTotals = $query->comparisonTotals();
        $this->assertNotNull($compTotals);
        $this->assertSame(10, $compTotals->orders_count);
        $this->assertSame(1000.0, $compTotals->net_sales_after_returns);
    }

    public function test_by_branch_includes_all_selected_branches_with_zero(): void
    {
        $period = new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-07'));

        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest1->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-02',
            'weekday' => 6,
            'orders_count' => 20,
            'net_sales_after_returns' => 2000.0,
        ]);

        $filters = new ReportFilters(
            preset: PeriodPreset::this_week,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$this->branch1->id, $this->branch2->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);
        $byBranch = $query->byBranch();

        $this->assertArrayHasKey($this->branch1->id, $byBranch);
        $this->assertArrayHasKey($this->branch2->id, $byBranch);

        $this->assertSame(20, $byBranch[$this->branch1->id]->orders_count);
        $this->assertSame(2000.0, $byBranch[$this->branch1->id]->net_sales_after_returns);

        // Branch 2 had no rows -> zero()
        $this->assertSame(0, $byBranch[$this->branch2->id]->orders_count);
        $this->assertSame(0.0, $byBranch[$this->branch2->id]->net_sales_after_returns);
    }

    public function test_series_orders_buckets_and_includes_empty_buckets(): void
    {
        // 3 days: 2026-10-01, 2026-10-02, 2026-10-03
        $period = new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-03'));

        // Seed data only on 2026-10-02
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest1->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-02',
            'weekday' => 6,
            'orders_count' => 14,
            'net_sales_after_returns' => 1400.0,
        ]);

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$this->branch1->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);
        $series = $query->series(Granularity::day);

        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], array_keys($series));
        $this->assertSame(0, $series['2026-10-01']->orders_count);
        $this->assertSame(14, $series['2026-10-02']->orders_count);
        $this->assertSame(0, $series['2026-10-03']->orders_count);

        // Per-branch series
        $perBranchSeries = $query->series(Granularity::day, perBranch: true);
        $this->assertArrayHasKey($this->branch1->id, $perBranchSeries);
        $this->assertSame(14, $perBranchSeries[$this->branch1->id]['2026-10-02']->orders_count);
    }

    public function test_scoped_rejects_non_agg_tables(): void
    {
        $filters = new ReportFilters(
            preset: PeriodPreset::today,
            period: new Period(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-07')),
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);

        $this->expectException(InvalidArgumentException::class);
        $query->scoped('branches');
    }

    public function test_hourly_totals(): void
    {
        $period = new Period(CarbonImmutable::parse('2026-10-15'), CarbonImmutable::parse('2026-10-15'), '14:00');

        DB::table('agg_branch_hourly')->insert([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-15',
            'hour' => 12,
            'weekday' => 5,
            'orders_count' => 10,
            'net_sales' => 1000.0,
        ]);
        DB::table('agg_branch_hourly')->insert([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-15',
            'hour' => 14,
            'weekday' => 5,
            'orders_count' => 5,
            'net_sales' => 500.0,
        ]);
        DB::table('agg_branch_hourly')->insert([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-10-15',
            'hour' => 18, // should be excluded by untilTime = '14:00'
            'weekday' => 5,
            'orders_count' => 20,
            'net_sales' => 2000.0,
        ]);

        $filters = new ReportFilters(
            preset: PeriodPreset::today,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$this->branch1->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);
        $hourly = $query->hourlyTotals($period, '14:00');

        $this->assertSame(15, $hourly['orders']);
        $this->assertSame(1500.0, $hourly['net_sales']);
    }
}
