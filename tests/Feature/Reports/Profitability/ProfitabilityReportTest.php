<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Profitability;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Metrics\Reports\ProfitabilityReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class ProfitabilityReportTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    private Period $twoWeeksPeriod;

    private ReportFilters $filters;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 12:00:00', 'Africa/Cairo'));
        $this->fixture = TwoBranchesTwoWeeks::create();

        $this->twoWeeksPeriod = new Period(
            CarbonImmutable::parse('2026-10-03', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-16', 'Africa/Cairo')
        );

        $this->filters = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $this->twoWeeksPeriod,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );
    }

    public function test_fixture_pl_matrix_values_match_expected_for_a_b_and_total(): void
    {
        $query = MetricsQuery::for($this->fixture->owner, $this->filters);
        $report = ProfitabilityReport::for($query, laborBasis: 'paid');
        $data = $report->data();

        $this->assertCount(2, $data->branches);
        $this->assertNotNull($data->group);

        // Branch A
        $branchA = $data->branches[$this->fixture->branchA->id];
        $this->assertEquals(12780.00, $branchA->sales, 'Branch A sales mismatch');
        $this->assertEquals(5000.00, $branchA->cogs, 'Branch A COGS mismatch');
        $this->assertEquals(7780.00, $branchA->grossProfit, 'Branch A gross profit mismatch');
        $this->assertEquals(round(7780.00 / 12780.00, 4), round($branchA->grossMargin ?? 0.0, 4));
        $this->assertEquals(1000.00, $branchA->laborCost, 'Branch A labor mismatch');
        $this->assertEquals(0.00, $branchA->rentCost, 'Branch A rent mismatch');
        $this->assertEquals(700.00, $branchA->otherExpenses, 'Branch A other expenses mismatch');
        $this->assertEquals(1700.00, $branchA->totalExpenses, 'Branch A total expenses mismatch');
        $this->assertEquals(50.00, $branchA->wasteCost, 'Branch A waste mismatch');
        $this->assertEquals(80.00, $branchA->inventoryVarianceCost, 'Branch A inventory variance mismatch');
        $this->assertEquals(5950.00, $branchA->netProfit, 'Branch A net profit mismatch');
        $this->assertEquals(round(5950.00 / 12780.00, 4), round($branchA->netMargin ?? 0.0, 4));

        // Acceptance criterion: row sum K09 − K11 − K12 − K13 = K14
        $this->assertEquals(
            $branchA->netProfit,
            round($branchA->grossProfit - $branchA->totalExpenses - $branchA->wasteCost - $branchA->inventoryVarianceCost, 2)
        );

        // Branch B
        $branchB = $data->branches[$this->fixture->branchB->id];
        $this->assertEquals(4160.00, $branchB->sales, 'Branch B sales mismatch');
        $this->assertEquals(1625.00, $branchB->cogs, 'Branch B COGS mismatch');
        $this->assertEquals(2535.00, $branchB->grossProfit, 'Branch B gross profit mismatch');
        $this->assertEquals(round(2535.00 / 4160.00, 4), round($branchB->grossMargin ?? 0.0, 4));
        $this->assertEquals(500.00, $branchB->laborCost, 'Branch B labor mismatch');
        $this->assertEquals(0.00, $branchB->rentCost, 'Branch B rent mismatch');
        $this->assertEquals(260.00, $branchB->otherExpenses, 'Branch B other expenses mismatch');
        $this->assertEquals(760.00, $branchB->totalExpenses, 'Branch B total expenses mismatch');
        $this->assertEquals(30.00, $branchB->wasteCost, 'Branch B waste mismatch');
        $this->assertEquals(0.00, $branchB->inventoryVarianceCost, 'Branch B inventory variance mismatch');
        $this->assertEquals(1745.00, $branchB->netProfit, 'Branch B net profit mismatch');
        $this->assertEquals(round(1745.00 / 4160.00, 4), round($branchB->netMargin ?? 0.0, 4));

        // Acceptance criterion: row sum K09 − K11 − K12 − K13 = K14
        $this->assertEquals(
            $branchB->netProfit,
            round($branchB->grossProfit - $branchB->totalExpenses - $branchB->wasteCost - $branchB->inventoryVarianceCost, 2)
        );

        // Group Total
        $group = $data->group;
        $this->assertEquals(16940.00, $group->sales, 'Group sales mismatch');
        $this->assertEquals(6625.00, $group->cogs, 'Group COGS mismatch');
        $this->assertEquals(10315.00, $group->grossProfit, 'Group gross profit mismatch');
        $this->assertEquals(round(10315.00 / 16940.00, 4), round($group->grossMargin ?? 0.0, 4));
        $this->assertEquals(1500.00, $group->laborCost, 'Group labor mismatch');
        $this->assertEquals(0.00, $group->rentCost, 'Group rent mismatch');
        $this->assertEquals(960.00, $group->otherExpenses, 'Group other expenses mismatch');
        $this->assertEquals(2460.00, $group->totalExpenses, 'Group total expenses mismatch');
        $this->assertEquals(80.00, $group->wasteCost, 'Group waste mismatch');
        $this->assertEquals(80.00, $group->inventoryVarianceCost, 'Group inventory variance mismatch');
        $this->assertEquals(7695.00, $group->netProfit, 'Group net profit mismatch');
        $this->assertEquals(round(7695.00 / 16940.00, 4), round($group->netMargin ?? 0.0, 4));

        // Group row sum K09 - K11 - K12 - K13 = K14
        $this->assertEquals(
            $group->netProfit,
            round($group->grossProfit - $group->totalExpenses - $group->wasteCost - $group->inventoryVarianceCost, 2)
        );
    }

    public function test_labor_basis_toggle_changes_only_labor_derived_rows(): void
    {
        $query = MetricsQuery::for($this->fixture->owner, $this->filters);

        $paidReport = ProfitabilityReport::for($query, laborBasis: 'paid');
        $paidData = $paidReport->data();

        $allocatedReport = ProfitabilityReport::for($query, laborBasis: 'allocated');
        $allocatedData = $allocatedReport->data();

        $paidA = $paidData->branches[$this->fixture->branchA->id];
        $allocA = $allocatedData->branches[$this->fixture->branchA->id];

        // Non-labor derived rows MUST be identical
        $this->assertEquals($paidA->sales, $allocA->sales);
        $this->assertEquals($paidA->cogs, $allocA->cogs);
        $this->assertEquals($paidA->grossProfit, $allocA->grossProfit);
        $this->assertEquals($paidA->grossMargin, $allocA->grossMargin);
        $this->assertEquals($paidA->otherExpenses, $allocA->otherExpenses);
        $this->assertEquals($paidA->wasteCost, $allocA->wasteCost);
        $this->assertEquals($paidA->inventoryVarianceCost, $allocA->inventoryVarianceCost);

        // Labor-derived rows MUST change
        // In October (31 days), 14 days overlap, avg_month_rate = 30000 -> 30000 * 14 / 31 = 13548.39
        $expectedAllocLabor = round(30000.00 * (14 / 31), 2);
        $this->assertEquals($expectedAllocLabor, $allocA->laborCost);
        $this->assertNotEquals($paidA->laborCost, $allocA->laborCost);
        $this->assertNotEquals($paidA->totalExpenses, $allocA->totalExpenses);
        $this->assertNotEquals($paidA->netProfit, $allocA->netProfit);
        $this->assertNotEquals($paidA->netMargin, $allocA->netMargin);

        // Row sum must still hold under allocated basis
        $this->assertEquals(
            $allocA->netProfit,
            round($allocA->grossProfit - $allocA->totalExpenses - $allocA->wasteCost - $allocA->inventoryVarianceCost, 2)
        );
    }

    public function test_margin_leakage_segments_plus_net_margin_equal_100_percent_of_sales(): void
    {
        $query = MetricsQuery::for($this->fixture->owner, $this->filters);
        $report = ProfitabilityReport::for($query, laborBasis: 'paid');
        $data = $report->data();

        foreach ($data->branches as $branch) {
            $sum = (
                $branch->cogs
                + $branch->laborCost
                + $branch->rentCost
                + $branch->otherExpenses
                + $branch->wasteCost
                + $branch->inventoryVarianceCost
                + $branch->netProfit
            );

            $this->assertEquals(
                $branch->sales,
                round($sum, 2),
                "Sum of leakage components for {$branch->branchName} does not equal sales"
            );

            $sumRatios = (
                ($branch->cogsPct ?? 0.0)
                + ($branch->laborPct ?? 0.0)
                + ($branch->rentPct ?? 0.0)
                + ($branch->otherExpensesPct ?? 0.0)
                + ($branch->wastePct ?? 0.0)
                + ($branch->inventoryVariancePct ?? 0.0)
                + ($branch->netMargin ?? 0.0)
            );

            $this->assertEqualsWithDelta(
                1.0,
                $sumRatios,
                0.0001,
                "Sum of leakage percentages for {$branch->branchName} does not equal 1.0 (100%)"
            );
        }
    }

    public function test_exclude_inventory_variance_toggle(): void
    {
        $query = MetricsQuery::for($this->fixture->owner, $this->filters);

        $normalData = ProfitabilityReport::for($query, excludeInventoryVariance: false)->data();
        $excludedData = ProfitabilityReport::for($query, excludeInventoryVariance: true)->data();

        $normalA = $normalData->branches[$this->fixture->branchA->id];
        $excludedA = $excludedData->branches[$this->fixture->branchA->id];

        $this->assertEquals(80.00, $normalA->inventoryVarianceCost);
        $this->assertEquals(0.00, $excludedA->inventoryVarianceCost);

        // Net profit increases by the 80.00 excluded shortage
        $this->assertEquals(5950.00, $normalA->netProfit);
        $this->assertEquals(6030.00, $excludedA->netProfit);

        // Row sum still holds
        $this->assertEquals(
            $excludedA->netProfit,
            round($excludedA->grossProfit - $excludedA->totalExpenses - $excludedA->wasteCost - $excludedA->inventoryVarianceCost, 2)
        );
    }

    public function test_stocktaking_notes_flagged(): void
    {
        $query = MetricsQuery::for($this->fixture->owner, $this->filters);
        $data = ProfitabilityReport::for($query)->data();

        $this->assertNotEmpty($data->stocktakingNotes);
        $this->assertStringContainsString('2026-10-08', $data->stocktakingNotes[0]);
    }

    public function test_comparison_mode_computes_growth_and_deltas(): void
    {
        // 7 days current (Oct 10..16), 7 days comparison (Oct 03..09)
        $currentPeriod = new Period(
            CarbonImmutable::parse('2026-10-10', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-16', 'Africa/Cairo')
        );
        $compPeriod = new Period(
            CarbonImmutable::parse('2026-10-03', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-09', 'Africa/Cairo')
        );

        $filtersWithComp = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $currentPeriod,
            comparisonMode: ComparisonMode::previous,
            comparison: $compPeriod,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filtersWithComp);
        $data = ProfitabilityReport::for($query)->data();

        $branchA = $data->branches[$this->fixture->branchA->id];
        $this->assertArrayHasKey('sales', $branchA->comparisons);
        $this->assertArrayHasKey('netProfit', $branchA->comparisons);

        // Current Branch A 7 days (10..16): 7 * 920 = 6440
        $this->assertEquals(6440.00, $branchA->sales);
        // Comparison Branch A 7 days (03..09): 6 * 920 + 820 = 6340
        $this->assertEquals(6340.00, $branchA->comparisons['sales']->comparison);
        $this->assertEquals(100.00, $branchA->comparisons['sales']->delta);
        $this->assertNotNull($branchA->comparisons['sales']->growth);
    }

    public function test_manager_a_cannot_see_group_or_branch_b(): void
    {
        $query = MetricsQuery::for($this->fixture->managerA, $this->filters);
        $data = ProfitabilityReport::for($query)->data();

        // Manager A can see own branch only
        $this->assertCount(1, $data->branches);
        $this->assertArrayHasKey($this->fixture->branchA->id, $data->branches);
        $this->assertArrayNotHasKey($this->fixture->branchB->id, $data->branches);

        // Group is strictly null
        $this->assertNull($data->group);

        // Ladder data has only Branch A
        $ladder = ProfitabilityReport::for($query)->marginLadderData();
        $this->assertEquals(['Branch A'], $ladder['labels']);
        $this->assertNull($ladder['groupMargin']);

        // Leakage data has only Branch A
        $leakage = ProfitabilityReport::for($query)->marginLeakageData();
        $this->assertEquals(['Branch A'], $leakage['labels']);
    }
}
