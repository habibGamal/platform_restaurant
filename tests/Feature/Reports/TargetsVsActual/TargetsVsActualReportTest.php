<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\TargetsVsActual;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\PeriodResolver;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Metrics\Reports\TargetsVsActualReport;
use App\Domain\Targets\TargetAchievement;
use App\Models\Target;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class TargetsVsActualReportTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    private CarbonImmutable $frozenNow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenNow = CarbonImmutable::parse('2026-10-17 12:00:00', 'Africa/Cairo');
        CarbonImmutable::setTestNow($this->frozenNow);

        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_target_to_date_on_day_14_with_weekday_weights_and_projection_acceptance_criteria(): void
    {
        // 1. Seed 4 weeks (28 days) of history prior to October 2026 (2026-08-01 to 2026-08-28)
        // Saturday 2026-08-01 to Friday 2026-08-28
        // Using distinct weekday weights:
        // Sat: 2000, Sun: 1000, Mon: 1000, Tue: 1000, Wed: 1000, Thu: 2000, Fri: 3000
        $historyStart = CarbonImmutable::parse('2026-08-01');
        $historyRows = [];
        $salesPattern = [
            0 => 2000.0, // Sat
            1 => 1000.0, // Sun
            2 => 1000.0, // Mon
            3 => 1000.0, // Tue
            4 => 1000.0, // Wed
            5 => 2000.0, // Thu
            6 => 3000.0, // Fri
        ];

        for ($i = 0; $i < 28; $i++) {
            $d = $historyStart->addDays($i);
            $w = (int) $d->dayOfWeek; // Carbon dayOfWeek: 0=Sun..6=Sat, but let's use Weekday enum / formula
            // In Weekday enum: 0=Sat, 1=Sun, 2=Mon, 3=Tue, 4=Wed, 5=Thu, 6=Fri
            $egyptianWeekday = ($d->dayOfWeek + 1) % 7;
            $sales = $salesPattern[$egyptianWeekday];

            $historyRows[] = [
                'organization_id' => $this->fixture->organization->id,
                'restaurant_id' => $this->fixture->restaurant->id,
                'branch_id' => $this->fixture->branchA->id,
                'business_date' => $d->toDateString(),
                'weekday' => $egyptianWeekday,
                'orders_count' => 10,
                'net_sales' => $sales,
                'returns_amount' => 0.0,
                'net_sales_after_returns' => $sales,
                'gross_sales' => $sales,
                'cogs' => $sales * 0.35,
                'gross_profit' => $sales * 0.65,
                'expenses_total' => 0.0,
                'net_profit' => $sales * 0.5,
                'is_day_closed' => true,
                'is_reconciled' => true,
            ];
        }
        DB::table('agg_branch_daily')->insert($historyRows);

        // 2. Create Target of 300,000 for Branch A with weekday_weights
        $targetA = Target::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'metric' => 'net_sales',
            'period_month' => '2026-10-01',
            'value' => 300000.00,
            'distribution' => 'weekday_weights',
        ]);

        // TargetObserver automatically distributes and persists to target_daily
        $day14 = CarbonImmutable::parse('2026-10-14', 'Africa/Cairo');

        $period = PeriodResolver::resolve(PeriodPreset::this_month, $this->frozenNow);
        $filters = new ReportFilters(
            preset: PeriodPreset::this_month,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$this->fixture->branchA->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = TargetsVsActualReport::for($query, $day14);
        $data = $report->data();

        // Target status for Branch A
        $statuses = TargetAchievement::for($query, 'net_sales', $day14);
        $this->assertCount(1, $statuses);
        $statusA = $statuses[0];

        // Sum of persisted daily targets up to Oct 14
        $expectedTargetToDate = (float) DB::table('target_daily')
            ->where('target_id', $targetA->id)
            ->where('date', '<=', '2026-10-14')
            ->sum('value');

        $this->assertGreaterThan(0.0, $expectedTargetToDate);
        $this->assertEqualsWithDelta($expectedTargetToDate, $statusA->targetToDate, 0.01);

        // Verify actual to date on day 14: 11 days * 920 + 1 day (Oct 6) * 820 = 10,940.00
        $this->assertEquals(10940.00, $statusA->actualToDate);

        // Verify projection formula: actual_to_date + remainingTargetSum * (actual_to_date / target_to_date)
        $remainingTargetSum = (float) DB::table('target_daily')
            ->where('target_id', $targetA->id)
            ->where('date', '>', '2026-10-14')
            ->sum('value');

        $paceFactor = $statusA->actualToDate / $statusA->targetToDate;
        $expectedProjected = $statusA->actualToDate + ($remainingTargetSum * $paceFactor);
        $this->assertEqualsWithDelta($expectedProjected, $statusA->projected, 0.01);

        // Acceptance criteria: Required run-rate = (target − actual) ÷ remaining days
        // In October (31 days), remaining days from Oct 14 to Oct 31 = 17 days
        $expectedRequiredRunRate = (300000.00 - 10940.00) / 17;
        $this->assertEqualsWithDelta($expectedRequiredRunRate, $statusA->requiredDailyAverage, 0.01);
    }

    public function test_branch_with_no_target_excluded_from_group_achievement_and_note_says_so(): void
    {
        // Branch A has target 300,000; Branch B has NO target
        Target::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'metric' => 'net_sales',
            'period_month' => '2026-10-01',
            'value' => 300000.00,
            'distribution' => 'flat',
        ]);

        $day14 = CarbonImmutable::parse('2026-10-14', 'Africa/Cairo');
        $period = PeriodResolver::resolve(PeriodPreset::this_month, $this->frozenNow);

        $filters = new ReportFilters(
            preset: PeriodPreset::this_month,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$this->fixture->branchA->id, $this->fixture->branchB->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = TargetsVsActualReport::for($query, $day14);
        $data = $report->data();

        $overviewSales = $data->overview['net_sales'];

        // Group target equals Branch A's target (300,000), excluding Branch B
        $this->assertEquals(300000.00, $overviewSales->targetMonth);

        // Group actual equals Branch A's actual (10,940.00), excluding Branch B
        $this->assertEquals(10940.00, $overviewSales->actualToDate);

        // 1 branch has target, 2 total branches
        $this->assertEquals(1, $overviewSales->branchesWithTarget);
        $this->assertEquals(2, $overviewSales->branchesTotal);

        // Note explicitly says 1 branch was excluded
        $this->assertNotNull($overviewSales->note);
        $this->assertStringContainsString('تم استثناء 1 فرع ليس له مستهدف محدد', $overviewSales->note);
    }

    public function test_food_cost_pct_ceiling_target_within_and_above_behavior(): void
    {
        // Branch A: target ceiling of 45% (0.45)
        // Up to day 14: actual COGS = 4320.00 - 40.00 (recovered) = 4280.00, actual sales = 10940.00 => Food Cost % = 39.12% <= 45% (within)
        Target::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'metric' => 'food_cost_pct',
            'period_month' => '2026-10-01',
            'value' => 0.45,
            'distribution' => 'flat',
        ]);

        $day14 = CarbonImmutable::parse('2026-10-14', 'Africa/Cairo');
        $period = PeriodResolver::resolve(PeriodPreset::this_month, $this->frozenNow);

        $filters = new ReportFilters(
            preset: PeriodPreset::this_month,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$this->fixture->branchA->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = TargetsVsActualReport::for($query, $day14);
        $data = $report->data();

        $fcMetric = $data->overview['food_cost_pct'];
        $this->assertEquals(0.45, $fcMetric->targetMonth);
        $this->assertEquals(round(4280.00 / 10940.00, 4), round($fcMetric->actualToDate, 4));
        $this->assertEquals('within', $fcMetric->status);

        // Branch table row
        $fcRow = collect($data->branchRows)
            ->first(fn ($r) => $r->branchId === $this->fixture->branchA->id && $r->metric === 'food_cost_pct');

        $this->assertNotNull($fcRow);
        $this->assertEquals('within', $fcRow->status);
    }

    public function test_burn_up_series_and_history_heatmap_generation(): void
    {
        Target::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'metric' => 'net_sales',
            'period_month' => '2026-10-01',
            'value' => 31000.00,
            'distribution' => 'flat', // 1,000 / day in 31 days
        ]);

        $day14 = CarbonImmutable::parse('2026-10-14', 'Africa/Cairo');
        $period = PeriodResolver::resolve(PeriodPreset::this_month, $this->frozenNow);

        $filters = new ReportFilters(
            preset: PeriodPreset::this_month,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: [$this->fixture->branchA->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = TargetsVsActualReport::for($query, $day14);
        $data = $report->data();

        // 1. Burn-up series
        $this->assertArrayHasKey('group', $data->burnUpSeries);
        $this->assertArrayHasKey('branch_'.$this->fixture->branchA->id, $data->burnUpSeries);

        $series = $data->burnUpSeries['branch_'.$this->fixture->branchA->id];
        $this->assertCount(31, $series->labels);
        $this->assertCount(31, $series->target);
        $this->assertCount(31, $series->actual);
        $this->assertCount(31, $series->projected);

        // Day 14 (index 13) has actual, Day 15 (index 14) actual is null
        $this->assertNotNull($series->actual[13]);
        $this->assertNull($series->actual[14]);

        // Projection is null before Day 14, equals actual on Day 14, and non-null after
        $this->assertNull($series->projected[12]);
        $this->assertEquals($series->actual[13], $series->projected[13]);
        $this->assertNotNull($series->projected[14]);

        // 2. History rows (last 12 months)
        $this->assertCount(12, $data->historyMonths);
        $this->assertNotEmpty($data->historyRows);

        $historyBranchA = collect($data->historyRows)
            ->first(fn ($r) => $r->branchId === $this->fixture->branchA->id);

        $this->assertNotNull($historyBranchA);
        $octCell = $historyBranchA->cells['2026-10'];
        $this->assertEquals(31000.00, $octCell->target);
        $this->assertNotNull($octCell->achievement);
    }
}
