<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\ProductPerformance;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Metrics\Reports\ProductPerformanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class ProductPerformanceReportTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    public function test_branch_a_kpi_summary_matches_fixture_numbers(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03'),
            to: CarbonImmutable::parse('2026-10-16'),
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
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
        $report = ProductPerformanceReport::for($query);
        $kpis = $report->kpiSummary();

        // Branch A sold 4 products: BURGER-1, BURGER-2, SIDE-1, DRINK-1
        $this->assertEquals(4, $kpis->totalProductsSold);
        // Total Qty: 56 + 28 + 56 + 28 = 168
        $this->assertEquals(168.0, $kpis->totalQtySold);
        // Total Net Sales: 5600 + 4200 + 2240 + 840 = 12880.00
        $this->assertEquals(12880.00, $kpis->totalNetSales);
        // Total Gross Profit: 3360 + 2520 + 1400 + 560 = 7840.00
        $this->assertEquals(7840.00, $kpis->totalGrossProfit);
        // Margin: 7840 / 12880 = 0.60869565... (~60.87%)
        $this->assertNotNull($kpis->marginPct);
        $this->assertEqualsWithDelta(7840.0 / 12880.0, $kpis->marginPct, 0.001);

        // 2 unsold manufactured items: BURGER-3 (Cheese Burger), DRINK-2 (Water)
        $this->assertEquals(2, $kpis->unsoldProductsCount);
    }

    public function test_acceptance_criteria_top_5_by_qty_and_profit_for_branch_a(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03'),
            to: CarbonImmutable::parse('2026-10-16'),
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
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
        $report = ProductPerformanceReport::for($query);

        // 1. Ranking by profit
        $rankingByProfit = $report->ranking(rankBy: 'gross_profit', limit: 5);

        // Top list by profit
        $topProfitRefs = array_map(fn ($r) => $r->productRef, $rankingByProfit['top']);
        $topProfitValues = array_map(fn ($r) => $r->grossProfit, $rankingByProfit['top']);

        $this->assertEquals(['BURGER-1', 'BURGER-2', 'SIDE-1', 'DRINK-1'], $topProfitRefs);
        $this->assertEquals([3360.00, 2520.00, 1400.00, 560.00], $topProfitValues);

        // Bottom list excludes never-sold items!
        $bottomProfitRefs = array_map(fn ($r) => $r->productRef, $rankingByProfit['bottom']);
        $this->assertNotContains('BURGER-3', $bottomProfitRefs);
        $this->assertNotContains('DRINK-2', $bottomProfitRefs);
        $this->assertEquals(['DRINK-1', 'SIDE-1', 'BURGER-2', 'BURGER-1'], $bottomProfitRefs);

        // Unsold items appear under "unsold" ("لم يُبع")
        $unsoldRefs = array_map(fn ($r) => $r->productRef, $rankingByProfit['unsold']);
        $this->assertContains('BURGER-3', $unsoldRefs);
        $this->assertContains('DRINK-2', $unsoldRefs);
        $this->assertCount(2, $rankingByProfit['unsold']);

        // 2. Ranking by qty
        $rankingByQty = $report->ranking(rankBy: 'qty', limit: 5);
        $topQtyRefs = array_map(fn ($r) => $r->productRef, $rankingByQty['top']);
        $topQtyValues = array_map(fn ($r) => $r->qtySold, $rankingByQty['top']);

        // BURGER-1 (56) and SIDE-1 (56) are top, BURGER-2 (28) and DRINK-1 (28) follow
        $this->assertCount(4, $topQtyRefs);
        $this->assertEquals(56.0, $topQtyValues[0]);
        $this->assertEquals(56.0, $topQtyValues[1]);
        $this->assertEquals(28.0, $topQtyValues[2]);
        $this->assertEquals(28.0, $topQtyValues[3]);
    }

    public function test_acceptance_criteria_pareto_percentages_sum_to_100(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03'),
            to: CarbonImmutable::parse('2026-10-16'),
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
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
        $report = ProductPerformanceReport::for($query);
        $pareto = $report->pareto();

        $this->assertCount(4, $pareto->items);

        // Verify Pareto sum is exactly 100.0%
        $sumShares = array_sum(array_map(fn ($item) => $item->sharePct, $pareto->items));
        $this->assertEquals(100.0, round($sumShares, 2), 'Pareto percentages must sum to 100%');

        // Last item cumulative percentage is 100.0%
        $this->assertNotEmpty($pareto->items);
        $lastItem = $pareto->items[count($pareto->items) - 1];
        $this->assertEquals(100.0, $lastItem->cumulativePct);

        // Check 80% cutoff: BURGER-1 (43.48%) + BURGER-2 (32.61%) = 76.09%, + SIDE-1 (17.39%) = 93.48% (crosses 80%)
        $this->assertEquals(3, $pareto->itemsCount80Pct);
    }

    public function test_branch_manager_without_group_permission_does_not_get_group_ranks_and_no_other_branch_names(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03'),
            to: CarbonImmutable::parse('2026-10-16'),
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null, // Manager sees only their assigned branch
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        // Manager A does not have analytics.view_group or analytics.view_other_branch_names
        $query = MetricsQuery::for($this->fixture->managerA, $filters);
        $this->assertFalse($query->canSeeOtherBranchNames());
        $this->assertFalse($query->canSeeGroup());
        $this->assertEquals([$this->fixture->branchA->id], $query->branchIds());

        $report = ProductPerformanceReport::for($query);
        $ranking = $report->ranking(rankBy: 'sales', limit: 10);

        // Negative authorization test: groupRank must be null when user lacks analytics.view_group
        $this->assertNotEmpty($ranking['top']);
        foreach ($ranking['top'] as $row) {
            $this->assertNull($row->groupRank, "Item {$row->name} must NOT expose groupRank without analytics.view_group");
        }
        foreach ($ranking['bottom'] as $row) {
            $this->assertNull($row->groupRank, "Bottom item {$row->name} must NOT expose groupRank without analytics.view_group");
        }

        // Cross-branch matrix for manager A contains ONLY Branch A, never Branch B
        $matrix = $report->crossBranchMatrix();
        $branchNamesInMatrix = array_column($matrix->branches, 'name');
        $this->assertContains($this->fixture->branchA->name, $branchNamesInMatrix);
        $this->assertNotContains($this->fixture->branchB->name, $branchNamesInMatrix);
        $this->assertCount(1, $matrix->branches);
    }

    public function test_authorized_user_with_group_permission_gets_group_ranks(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03'),
            to: CarbonImmutable::parse('2026-10-16'),
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
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

        // Owner has analytics.view_group
        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $this->assertTrue($query->canSeeGroup());

        $report = ProductPerformanceReport::for($query);
        $ranking = $report->ranking(rankBy: 'sales', limit: 10);

        // Positive authorization test: groupRank must be present and valid
        $this->assertNotEmpty($ranking['top']);
        foreach ($ranking['top'] as $row) {
            $this->assertNotNull($row->groupRank, "Item {$row->name} should have a groupRank for authorized user");
            $this->assertGreaterThan(0, $row->groupRank);
        }
    }

    public function test_category_performance_numbers(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03'),
            to: CarbonImmutable::parse('2026-10-16'),
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
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
        $report = ProductPerformanceReport::for($query);
        $categories = $report->categoryPerformance();

        $this->assertCount(2, $categories);

        $burgers = collect($categories)->firstWhere('name', 'Burgers');
        $drinksAndSides = collect($categories)->firstWhere('name', 'Drinks & Sides');

        $this->assertNotNull($burgers);
        $this->assertNotNull($drinksAndSides);

        // Burgers sales: 5600 + 4200 = 9800.00
        $this->assertEquals(9800.00, $burgers->netSales);
        $this->assertEquals(3920.00, $burgers->cogs);
        $this->assertEquals(5880.00, $burgers->grossProfit);
        $this->assertEqualsWithDelta(0.60, $burgers->marginPct, 0.001);

        // Drinks & sides sales: 2240 + 840 = 3080.00
        $this->assertEquals(3080.00, $drinksAndSides->netSales);
        $this->assertEquals(1120.00, $drinksAndSides->cogs);
        $this->assertEquals(1960.00, $drinksAndSides->grossProfit);
        $this->assertEqualsWithDelta(1960.0 / 3080.0, $drinksAndSides->marginPct, 0.001);
    }

    public function test_item_detail_summary_and_daily_rows(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03'),
            to: CarbonImmutable::parse('2026-10-16'),
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
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
        $report = ProductPerformanceReport::for($query);

        // BURGER-1 detail
        $detail = $report->itemDetail('BURGER-1');
        $this->assertNotNull($detail);

        $this->assertEquals('Single Burger', $detail->name);
        $this->assertEquals(56.0, $detail->totalQtySold);
        $this->assertEquals(5600.00, $detail->totalNetSales);
        $this->assertEquals(2240.00, $detail->totalCogs);
        $this->assertEquals(3360.00, $detail->totalGrossProfit);
        // Returned on 2026-10-06: 1.0 qty
        $this->assertEquals(1.0, $detail->totalReturnsQty);
        // Waste on 2026-10-07: 1.0 qty, 50.00 cost
        $this->assertEquals(1.0, $detail->totalWasteQty);
        $this->assertEquals(50.00, $detail->totalWasteCost);

        // 14 daily rows
        $this->assertCount(14, $detail->dailyRows);
        $day1 = $detail->dailyRows[0];
        $this->assertEquals('2026-10-03', $day1->businessDate);
        $this->assertEquals(4.0, $day1->qtySold);
        $this->assertEquals(400.00, $day1->netSales);
        $this->assertEquals(100.00, $day1->price);
        $this->assertEquals(40.00, $day1->unitCost);
    }

    public function test_comparison_period_and_growth_metrics(): void
    {
        // Current: 2026-10-10 to 2026-10-16 (7 days)
        // 7 days on Branch A = 7 * 920 = 6440.00 net sales, 7 * 12 = 84 qty
        $currentPeriod = new Period(
            from: CarbonImmutable::parse('2026-10-10'),
            to: CarbonImmutable::parse('2026-10-16'),
        );

        // Comparison: 2026-10-03 to 2026-10-06 (4 days)
        // 4 days on Branch A = 4 * 920 = 3680.00 net sales, 4 * 12 = 48 qty
        $comparisonPeriod = new Period(
            from: CarbonImmutable::parse('2026-10-03'),
            to: CarbonImmutable::parse('2026-10-06'),
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $currentPeriod,
            comparisonMode: ComparisonMode::previous,
            comparison: $comparisonPeriod,
            branchIds: [$this->fixture->branchA->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = ProductPerformanceReport::for($query);
        $kpi = $report->kpiSummary();

        $this->assertEquals(6440.00, $kpi->totalNetSales);
        $this->assertEquals(84.0, $kpi->totalQtySold);
        $this->assertEquals(3680.00, $kpi->comparisonNetSales);
        $this->assertEquals(48.0, $kpi->comparisonQtySold);

        // Growth: (6440 - 3680) / 3680 = 2760 / 3680 = 0.75 (75%)
        $this->assertNotNull($kpi->salesGrowth);
        $this->assertEqualsWithDelta(0.75, $kpi->salesGrowth, 0.001);

        // Qty growth: (84 - 48) / 48 = 36 / 48 = 0.75 (75%)
        $this->assertNotNull($kpi->qtyGrowth);
        $this->assertEqualsWithDelta(0.75, $kpi->qtyGrowth, 0.001);

        // Test ranking with growth
        $ranking = $report->ranking(rankBy: 'sales', limit: 10);
        $burger1 = collect($ranking['top'])->firstWhere('productRef', 'BURGER-1');
        $this->assertNotNull($burger1);
        // Current BURGER-1: 7 * 400 = 2800.00, qty = 28
        // Comparison BURGER-1: 4 * 400 = 1600.00, qty = 16
        $this->assertEquals(2800.00, $burger1->netSales);
        $this->assertEquals(1600.00, $burger1->comparisonSales);
        $this->assertEquals(28.0, $burger1->qtySold);
        $this->assertEquals(16.0, $burger1->comparisonQty);
        $this->assertEqualsWithDelta(0.75, $burger1->salesGrowth, 0.001);
        $this->assertEqualsWithDelta(0.75, $burger1->qtyGrowth, 0.001);
    }

    public function test_cross_branch_matrix_metrics(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03'),
            to: CarbonImmutable::parse('2026-10-16'),
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
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
        $report = ProductPerformanceReport::for($query);

        // Matrix by quantity
        $matrixQty = $report->crossBranchMatrix(metric: 'qty');
        $this->assertCount(2, $matrixQty->branches);

        $branchAId = $this->fixture->branchA->id;
        $branchBId = $this->fixture->branchB->id;

        $burger1Row = collect($matrixQty->rows)->firstWhere('productRef', 'BURGER-1');
        $this->assertNotNull($burger1Row);

        // Branch A: 14 days open, 56 sold -> 4.0/day
        $this->assertEquals(14, $burger1Row['branches'][$branchAId]['daysOpen']);
        $this->assertEquals(56.0, $burger1Row['branches'][$branchAId]['qty']);
        $this->assertEquals(4.0, $burger1Row['branches'][$branchAId]['perDay']);

        // Branch B: 13 days open, 13 sold -> 1.0/day
        $this->assertEquals(13, $burger1Row['branches'][$branchBId]['daysOpen']);
        $this->assertEquals(13.0, $burger1Row['branches'][$branchBId]['qty']);
        $this->assertEquals(1.0, $burger1Row['branches'][$branchBId]['perDay']);

        // Matrix by sales
        $matrixSales = $report->crossBranchMatrix(metric: 'sales');
        $burger1SalesRow = collect($matrixSales->rows)->firstWhere('productRef', 'BURGER-1');
        $this->assertNotNull($burger1SalesRow);

        // Branch A: 5600 sales -> 400.0/day
        $this->assertEquals(5600.0, $burger1SalesRow['branches'][$branchAId]['sales']);
        $this->assertEquals(400.0, $burger1SalesRow['branches'][$branchAId]['perDay']);

        // Branch B: 1300 sales -> 100.0/day
        $this->assertEquals(1300.0, $burger1SalesRow['branches'][$branchBId]['sales']);
        $this->assertEquals(100.0, $burger1SalesRow['branches'][$branchBId]['perDay']);
    }

    public function test_category_performance_aggregated_across_multiple_branches(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03'),
            to: CarbonImmutable::parse('2026-10-16'),
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
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
        $report = ProductPerformanceReport::for($query);

        $categories = $report->categoryPerformance();
        $this->assertCount(2, $categories);

        $burgers = collect($categories)->firstWhere('name', 'Burgers');
        $drinksAndSides = collect($categories)->firstWhere('name', 'Drinks & Sides');

        $this->assertNotNull($burgers);
        $this->assertNotNull($drinksAndSides);

        // Burgers sales: Branch A (9800.00) + Branch B (13 * 250 = 3250.00) = 13050.00
        $this->assertEquals(13050.00, $burgers->netSales);
        // Burgers COGS: Branch A (3920.00) + Branch B (13 * 100 = 1300.00) = 5220.00
        $this->assertEquals(5220.00, $burgers->cogs);
        // Burgers Gross profit: 13050 - 5220 = 7830.00
        $this->assertEquals(7830.00, $burgers->grossProfit);
        $this->assertEqualsWithDelta(7830.0 / 13050.0, $burgers->marginPct, 0.001);

        // Drinks & Sides sales: Branch A (3080.00) + Branch B (13 * 70 = 910.00) = 3990.00
        $this->assertEquals(3990.00, $drinksAndSides->netSales);

        // Scoped to Branch B only
        $categoriesB = $report->categoryPerformance($this->fixture->branchB->id);
        $burgersB = collect($categoriesB)->firstWhere('name', 'Burgers');
        $this->assertNotNull($burgersB);
        $this->assertEquals(3250.00, $burgersB->netSales);
        $this->assertEquals(1300.00, $burgersB->cogs);
    }

    public function test_ranking_filtered_by_category(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03'),
            to: CarbonImmutable::parse('2026-10-16'),
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
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
        $report = ProductPerformanceReport::for($query);

        $catBurgersUuid = "cat-burgers-{$this->fixture->branchA->id}";

        $rankingBurgers = $report->ranking(categoryUuid: $catBurgersUuid);

        // Only burgers in top
        $refs = array_map(fn ($r) => $r->productRef, $rankingBurgers['top']);
        $this->assertEquals(['BURGER-1', 'BURGER-2'], $refs);

        // Unsold in burgers: BURGER-3
        $unsoldRefs = array_map(fn ($r) => $r->productRef, $rankingBurgers['unsold']);
        $this->assertEquals(['BURGER-3'], $unsoldRefs);
    }
}
