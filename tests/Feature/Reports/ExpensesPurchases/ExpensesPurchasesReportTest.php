<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\ExpensesPurchases;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class ExpensesPurchasesReportTest extends TestCase
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

    public function test_main_kpis_match_fixture_expected_values(): void
    {
        $query = MetricsQuery::for($this->fixture->owner, $this->filters);
        $report = ExpensesPurchasesReport::for($query);
        $kpis = $report->kpis();

        // K11: Expenses total = 1700 (A) + 760 (B) = 2460.00
        $this->assertEquals(2460.00, $kpis->totalExpenses, 'Total expenses K11 mismatch');

        // K11a: Labor cost = 1000 (A) + 500 (B) = 1500.00
        $this->assertEquals(1500.00, $kpis->laborCost, 'Labor cost K11a mismatch');

        // K20: Net purchases = 450 (A: 500 - 50) + 200 (B) = 650.00
        $this->assertEquals(650.00, $kpis->netPurchases, 'Net purchases K20 mismatch');

        // K08: COGS = 5000 (A) + 1625 (B) = 6625.00
        $this->assertEquals(6625.00, $kpis->cogs, 'COGS K08 mismatch');

        // K06: Net sales after returns = 12780 (A) + 4160 (B) = 16940.00
        $this->assertEquals(16940.00, $kpis->netSalesAfterReturns, 'Sales K06 mismatch');

        // Purchases to COGS ratio: 650 / 6625 = 0.0981
        $this->assertEquals(round(650.00 / 6625.00, 4), $kpis->purchasesToCogsRatio);

        // Expenses % of sales: 2460 / 16940 = 0.1452
        $this->assertEquals(round(2460.00 / 16940.00, 4), $kpis->expensesPercentOfSales);
    }

    public function test_acceptance_criteria_category_matrix_totals_equal_k11_and_labor_equals_k11a(): void
    {
        $query = MetricsQuery::for($this->fixture->owner, $this->filters);
        $report = ExpensesPurchasesReport::for($query);
        $matrix = $report->categoryMatrix();

        $branchAId = $this->fixture->branchA->id;
        $branchBId = $this->fixture->branchB->id;

        // Acceptance criteria:
        // Category matrix totals equal K11 per branch
        $this->assertEquals(1700.00, $matrix->branchTotals[$branchAId]['amount'], 'Branch A matrix total must equal K11');
        $this->assertEquals(760.00, $matrix->branchTotals[$branchBId]['amount'], 'Branch B matrix total must equal K11');

        // Labor row equals K11a per branch
        $this->assertEquals(1000.00, $matrix->cells['labor'][$branchAId]['amount'], 'Branch A labor row must equal K11a');
        $this->assertEquals(500.00, $matrix->cells['labor'][$branchBId]['amount'], 'Branch B labor row must equal K11a');

        // Group totals
        $this->assertNotNull($matrix->grandTotal);
        $this->assertEquals(2460.00, $matrix->grandTotal['amount'], 'Matrix grand total must equal 2460.00');
        $this->assertEquals(1500.00, $matrix->categoryTotals['labor']['amount'], 'Labor category total must equal 1500.00');

        // Other / utilities
        $this->assertEquals(700.00, $matrix->cells['other'][$branchAId]['amount']);
        $this->assertEquals(260.00, $matrix->cells['other'][$branchBId]['amount']);
    }

    public function test_acceptance_criteria_budget_proration_on_day_14_of_30_day_month(): void
    {
        // Acceptance criteria: Budget proration on day 14 of a 30-day month = avg_month_rate × 14/30
        $avgMonthRate = 30000.00;
        $prorated = ExpensesPurchasesReport::calculateProratedBudget($avgMonthRate, 14, 30);

        $this->assertEquals(14000.00, $prorated, 'Budget proration on day 14 of 30-day month must equal 14000.00');

        // Test with the service budgetVsActual method
        $query = MetricsQuery::for($this->fixture->owner, $this->filters);
        $report = ExpensesPurchasesReport::for($query);
        $budgetItems = $report->budgetVsActual();

        $this->assertNotEmpty($budgetItems);

        // Salaries on Branch A
        $branchAItem = collect($budgetItems)->first(fn ($item) => $item->branchId === $this->fixture->branchA->id && $item->expenseTypeName === 'Salaries');
        $this->assertNotNull($branchAItem);
        $this->assertEquals(30000.00, $branchAItem->monthlyBudget);
        $this->assertEquals(1000.00, $branchAItem->actualSpent);
        // October has 31 days, 14 days elapsed: 30000 * 14 / 31 = 13548.39
        $this->assertEquals(round(30000.00 * 14 / 31, 2), $branchAItem->proratedBudget);
        $this->assertFalse($branchAItem->isOverBudget);
    }

    public function test_acceptance_criteria_purchases_vs_cogs_ratio_matches_fixture_values(): void
    {
        $query = MetricsQuery::for($this->fixture->owner, $this->filters);
        $report = ExpensesPurchasesReport::for($query);
        $items = $report->purchasesVsCogs();

        $branchAId = $this->fixture->branchA->id;
        $branchBId = $this->fixture->branchB->id;

        $byBranch = collect($items)->keyBy('branchId');

        // Branch A: Net Purchases = 450, COGS = 5000 -> Ratio = 450 / 5000 = 0.0900
        $itemA = $byBranch->get($branchAId);
        $this->assertNotNull($itemA);
        $this->assertEquals(450.00, $itemA->netPurchases);
        $this->assertEquals(5000.00, $itemA->cogs);
        $this->assertEquals(round(450.00 / 5000.00, 4), $itemA->ratio);
        $this->assertEquals('running_down', $itemA->status);

        // Branch B: Net Purchases = 200, COGS = 1625 -> Ratio = 200 / 1625 = 0.1231
        $itemB = $byBranch->get($branchBId);
        $this->assertNotNull($itemB);
        $this->assertEquals(200.00, $itemB->netPurchases);
        $this->assertEquals(1625.00, $itemB->cogs);
        $this->assertEquals(round(200.00 / 1625.00, 4), $itemB->ratio);
        $this->assertEquals('running_down', $itemB->status);
    }

    public function test_purchases_per_branch_and_supplier_summary(): void
    {
        $query = MetricsQuery::for($this->fixture->owner, $this->filters);
        $report = ExpensesPurchasesReport::for($query);

        // Purchases per branch
        $purchases = $report->purchasesPerBranch();
        $byBranch = collect($purchases)->keyBy('branchId');

        $pA = $byBranch->get($this->fixture->branchA->id);
        $this->assertNotNull($pA);
        $this->assertEquals(500.00, $pA->invoicesAmount);
        $this->assertEquals(50.00, $pA->returnsAmount);
        $this->assertEquals(450.00, $pA->netPurchases);
        $this->assertEquals(1, $pA->invoicesCount);
        $this->assertEquals(1, $pA->returnsCount);

        $pB = $byBranch->get($this->fixture->branchB->id);
        $this->assertNotNull($pB);
        $this->assertEquals(200.00, $pB->invoicesAmount);
        $this->assertEquals(0.00, $pB->returnsAmount);
        $this->assertEquals(200.00, $pB->netPurchases);
        $this->assertEquals(1, $pB->invoicesCount);

        // Supplier summary (Main Supplier normalized across both branches)
        $suppliers = $report->supplierSummary();
        $this->assertCount(1, $suppliers);

        $sup = $suppliers[0];
        $this->assertEquals('Main Supplier', $sup->supplierName);
        $this->assertEquals(2, $sup->branchesCount);
        $this->assertEquals(2, $sup->invoicesCount);
        $this->assertEquals(700.00, $sup->invoicesAmount);
        $this->assertEquals(50.00, $sup->returnsAmount);
        $this->assertEquals(650.00, $sup->netPurchases);
        $this->assertEquals(1.0, $sup->shareOfPurchases);
    }

    public function test_expense_list_drilldown_returns_records(): void
    {
        $query = MetricsQuery::for($this->fixture->owner, $this->filters);
        $report = ExpensesPurchasesReport::for($query);

        $list = $report->expenseList(50);
        $this->assertNotEmpty($list);

        // Branch A has 14 utility expenses + 1 salary = 15
        // Branch B has 13 utility expenses + 1 salary = 14
        // Total = 29
        $this->assertCount(29, $list);

        $first = $list[0];
        $this->assertNotEmpty($first->businessDate);
        $this->assertGreaterThan(0, $first->amount);
    }

    public function test_manager_a_cannot_see_group_totals_or_other_branches(): void
    {
        $query = MetricsQuery::for($this->fixture->managerA, $this->filters);
        $report = ExpensesPurchasesReport::for($query);

        $matrix = $report->categoryMatrix();
        $this->assertCount(1, $matrix->branches);
        $this->assertEquals($this->fixture->branchA->id, $matrix->branches[0]['id']);
        $this->assertNull($matrix->grandTotal, 'Manager A cannot see group grand total');

        $purchases = $report->purchasesPerBranch();
        $this->assertCount(1, $purchases);
        $this->assertEquals($this->fixture->branchA->id, $purchases[0]->branchId);

        $suppliers = $report->supplierSummary();
        $this->assertCount(1, $suppliers);
        $this->assertEquals(1, $suppliers[0]->branchesCount);
        $this->assertEquals(450.00, $suppliers[0]->netPurchases);
    }

    public function test_growth_and_warning_detection_with_comparison(): void
    {
        // First week: Oct 03 to Oct 09
        $w1 = new Period(
            CarbonImmutable::parse('2026-10-03', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-09', 'Africa/Cairo')
        );

        // Second week: Oct 10 to Oct 16
        $w2 = new Period(
            CarbonImmutable::parse('2026-10-10', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-16', 'Africa/Cairo')
        );

        $compFilters = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $w2,
            comparisonMode: ComparisonMode::previous,
            comparison: $w1,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $compFilters);
        $report = ExpensesPurchasesReport::for($query);

        $growthItems = $report->expensesVsSalesGrowth();
        $this->assertCount(2, $growthItems);

        foreach ($growthItems as $item) {
            $this->assertNotNull($item->comparisonExpenses);
            $this->assertNotNull($item->comparisonSales);
            $this->assertNotNull($item->expensesGrowth);
            $this->assertNotNull($item->salesGrowth);
        }
    }
}
