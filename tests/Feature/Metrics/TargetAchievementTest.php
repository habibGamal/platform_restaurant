<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Targets\TargetAchievement;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Target;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TargetAchievementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Restaurant $rest;

    private Branch $branch1;

    private Branch $branch2;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->rest = Restaurant::factory()->create(['organization_id' => $this->org->id]);
        $this->branch1 = Branch::factory()->active()->create([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'opened_on' => '2026-01-01',
        ]);
        $this->branch2 = Branch::factory()->active()->create([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'opened_on' => '2026-01-01',
        ]);

        $this->owner = User::factory()->forOrganization($this->org)->create();
        $this->owner->assignRole('org_owner');
    }

    public function test_pace_projection_and_required_daily_run_rate(): void
    {
        // Month: November 2026 (30 days). Target: 300,000 flat (10,000 / day).
        $target = Target::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch1->id,
            'metric' => 'net_sales',
            'period_month' => '2026-11-01',
            'value' => 300000.00,
            'distribution' => 'flat',
        ]);

        // Nov 10 is day 10. Elapsed: 10 days, Remaining: 20 days.
        $today = CarbonImmutable::parse('2026-11-10');

        // Actual sales to date = 120,000 (pace is 120,000 / 100,000 = 1.20)
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-11-05',
            'weekday' => 5,
            'net_sales_after_returns' => 120000.0,
        ]);

        $period = new Period(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-10'));
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
        $statuses = TargetAchievement::for($query, 'net_sales', $today);

        $this->assertCount(1, $statuses);
        $status = $statuses[0];

        $this->assertSame(300000.00, $status->targetMonth);
        $this->assertSame(100000.00, $status->targetToDate);
        $this->assertSame(120000.00, $status->actualToDate);

        // Achievement to date = 120000 / 100000 = 1.20
        $this->assertSame(1.20, $status->achievementToDate);

        // Gap = targetToDate - actualToDate = 100000 - 120000 = -20000.0 (surplus)
        $this->assertSame(-20000.00, $status->gap);

        // Pace = 1.20. Remaining target = 200,000. Projected = 120,000 + 200,000 * 1.20 = 360,000
        $this->assertSame(360000.00, $status->projected);
        $this->assertSame(1.20, $status->projectedAchievement);

        // Required daily = (300,000 - 120,000) / 20 = 9,000.00
        $this->assertSame(9000.00, $status->requiredDailyAverage);

        // Current daily average = 120,000 / 10 = 12,000.00
        $this->assertSame(12000.00, $status->currentDailyAverage);

        // Status is on_track (>= 1.0)
        $this->assertSame('on_track', $status->status);
    }

    public function test_status_thresholds(): void
    {
        // Target: 300,000. Nov 10 -> targetToDate = 100,000.
        Target::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch1->id,
            'metric' => 'net_sales',
            'period_month' => '2026-11-01',
            'value' => 300000.00,
            'distribution' => 'flat',
        ]);

        $today = CarbonImmutable::parse('2026-11-10');
        $period = new Period(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-10'));
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

        // Scenario 1: Actual = 95,000 -> 95% -> at_risk
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-11-05',
            'weekday' => 5,
            'net_sales_after_returns' => 95000.0,
        ]);
        $statusAtRisk = TargetAchievement::for($query, 'net_sales', $today)[0];
        $this->assertSame('at_risk', $statusAtRisk->status);

        // Scenario 2: Actual = 80,000 -> 80% -> behind
        DB::table('agg_branch_daily')->where('branch_id', $this->branch1->id)->update([
            'net_sales_after_returns' => 80000.0,
        ]);
        $statusBehind = TargetAchievement::for($query, 'net_sales', $today)[0];
        $this->assertSame('behind', $statusBehind->status);
    }

    public function test_no_target_branch(): void
    {
        $today = CarbonImmutable::parse('2026-11-10');
        $period = new Period(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-10'));

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$this->branch2->id], // no target created for branch 2
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->owner, $filters);
        $status = TargetAchievement::for($query, 'net_sales', $today)[0];

        $this->assertSame('no_target', $status->status);
        $this->assertNull($status->targetMonth);
        $this->assertNull($status->targetToDate);
        $this->assertNull($status->achievementToDate);
        $this->assertNull($status->gap);
        $this->assertNull($status->projected);
    }

    public function test_food_cost_ceiling_within_and_above(): void
    {
        // Target: 30% food cost ceiling (0.30)
        Target::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch1->id,
            'metric' => 'food_cost_pct',
            'period_month' => '2026-11-01',
            'value' => 0.30,
            'distribution' => 'flat',
        ]);

        $today = CarbonImmutable::parse('2026-11-10');
        $period = new Period(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-10'));
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

        // Case A: COGS = 2800, Net sales after returns = 10000 -> 28% <= 30% -> 'within'
        DB::table('agg_branch_daily')->insert([
            'organization_id' => $this->org->id,
            'restaurant_id' => $this->rest->id,
            'branch_id' => $this->branch1->id,
            'business_date' => '2026-11-05',
            'weekday' => 5,
            'cogs' => 2800.0,
            'returned_cogs_recovered' => 0.0,
            'net_sales_after_returns' => 10000.0,
        ]);
        $statusWithin = TargetAchievement::for($query, 'food_cost_pct', $today)[0];
        $this->assertSame('within', $statusWithin->status);
        $this->assertEqualsWithDelta(0.28, $statusWithin->actualToDate, 0.001);

        // Case B: COGS = 3500, Net sales after returns = 10000 -> 35% > 30% -> 'above'
        DB::table('agg_branch_daily')->where('branch_id', $this->branch1->id)->update([
            'cogs' => 3500.0,
        ]);
        $statusAbove = TargetAchievement::for($query, 'food_cost_pct', $today)[0];
        $this->assertSame('above', $statusAbove->status);
        $this->assertEqualsWithDelta(0.35, $statusAbove->actualToDate, 0.001);
    }
}
