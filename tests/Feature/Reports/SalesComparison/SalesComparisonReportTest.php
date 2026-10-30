<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\SalesComparison;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\PeriodResolver;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Metrics\Reports\SalesComparisonReport;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class SalesComparisonReportTest extends TestCase
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

    public function test_matches_expected_numbers_and_acceptance_criteria_for_last_week(): void
    {
        $period = PeriodResolver::resolve(PeriodPreset::last_week, $this->frozenNow);
        $comparison = PeriodResolver::comparison($period, ComparisonMode::same_weekday_last_week, PeriodPreset::last_week);

        $filters = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::same_weekday_last_week,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = SalesComparisonReport::for($query);
        $data = $report->data();

        // 1. Matrix assertions
        $this->assertCount(3, $data->matrix); // Branch A, Branch B, Group Average

        $matrixByBranch = [];
        foreach ($data->matrix as $row) {
            $key = $row->isGroupAverage ? 'group' : (string) $row->branchId;
            $matrixByBranch[$key] = $row;
        }

        // Branch A
        $rowA = $matrixByBranch[(string) $this->fixture->branchA->id];
        $this->assertEquals('Branch A', $rowA->branchName);
        $this->assertEquals(6440.00, $rowA->sales);
        $this->assertEquals(6340.00, $rowA->salesComparison);
        $this->assertEquals(42, $rowA->orders);
        $this->assertEquals(42, $rowA->ordersComparison);
        $this->assertEquals(153.33, round($rowA->avgTicket, 2));
        $this->assertEquals(153.33, round($rowA->avgTicketComparison, 2));
        $this->assertEquals(7, $rowA->daysWithData);
        $this->assertEquals(7, $rowA->daysWithDataComparison);
        $this->assertEquals(0.0158, round($rowA->salesGrowth, 4));

        // Branch B
        $rowB = $matrixByBranch[(string) $this->fixture->branchB->id];
        $this->assertEquals('Branch B', $rowB->branchName);
        $this->assertEquals(1920.00, $rowB->sales);
        $this->assertEquals(2240.00, $rowB->salesComparison);
        $this->assertEquals(12, $rowB->orders);
        $this->assertEquals(14, $rowB->ordersComparison);
        $this->assertEquals(160.00, round($rowB->avgTicket, 2));
        $this->assertEquals(160.00, round($rowB->avgTicketComparison, 2));
        $this->assertEquals(6, $rowB->daysWithData); // Missing day on 2026-10-10
        $this->assertEquals(7, $rowB->daysWithDataComparison);
        $this->assertEquals(-0.1429, round($rowB->salesGrowth, 4));

        // Group Average
        $groupRow = $matrixByBranch['group'];
        $this->assertTrue($groupRow->isGroupAverage);
        $this->assertEquals('متوسط المجموعة', $groupRow->branchName);
        $this->assertEquals(4180.00, $groupRow->sales); // (6440 + 1920) / 2
        $this->assertEquals(4290.00, $groupRow->salesComparison); // (6340 + 2240) / 2
        $this->assertEquals(27, $groupRow->orders); // (42 + 12) / 2
        $this->assertEquals(28, $groupRow->ordersComparison); // (42 + 14) / 2

        // 2. Growth Decomposition exact check (Acceptance criterion: orders effect + ticket effect = ΔK04 exactly)
        $this->assertCount(2, $data->growthDecomposition);
        $decompByBranch = [];
        foreach ($data->growthDecomposition as $row) {
            $decompByBranch[$row->branchId] = $row;
        }

        $decompA = $decompByBranch[$this->fixture->branchA->id];
        $this->assertEquals(0.00, $decompA->deltaNetSales);
        $this->assertEquals(0.00, round($decompA->ordersEffect + $decompA->ticketEffect, 2));
        $this->assertEquals($decompA->deltaNetSales, round($decompA->ordersEffect + $decompA->ticketEffect, 2));

        $decompB = $decompByBranch[$this->fixture->branchB->id];
        $this->assertEquals(-320.00, $decompB->deltaNetSales);
        $this->assertEquals(-320.00, $decompB->ordersEffect);
        $this->assertEquals(0.00, $decompB->ticketEffect);
        $this->assertEquals($decompB->deltaNetSales, round($decompB->ordersEffect + $decompB->ticketEffect, 2));
    }

    public function test_channel_filter_changes_all_figures_consistently(): void
    {
        $period = PeriodResolver::resolve(PeriodPreset::last_week, $this->frozenNow);
        $comparison = PeriodResolver::comparison($period, ComparisonMode::same_weekday_last_week, PeriodPreset::last_week);

        // A. Filter by takeaway
        $filtersTakeaway = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::same_weekday_last_week,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: 'takeaway',
            granularity: Granularity::day,
        );

        $reportTakeaway = SalesComparisonReport::for(MetricsQuery::for($this->fixture->owner, $filtersTakeaway));
        $dataTakeaway = $reportTakeaway->data();

        $matrixTakeaway = collect($dataTakeaway->matrix)->keyBy(fn ($r) => $r->isGroupAverage ? 'group' : (string) $r->branchId);
        $rowATakeaway = $matrixTakeaway[(string) $this->fixture->branchA->id];
        $rowBTakeaway = $matrixTakeaway[(string) $this->fixture->branchB->id];

        $this->assertEquals(14, $rowATakeaway->orders);
        $this->assertEquals(1960.00, $rowATakeaway->sales);
        $this->assertEquals(140.00, $rowATakeaway->avgTicket);

        $this->assertEquals(0, $rowBTakeaway->orders);
        $this->assertEquals(0.00, $rowBTakeaway->sales);
        $this->assertNull($rowBTakeaway->avgTicket);

        // B. Filter by dine_in
        $filtersDineIn = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::same_weekday_last_week,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: 'dine_in',
            granularity: Granularity::day,
        );

        $reportDineIn = SalesComparisonReport::for(MetricsQuery::for($this->fixture->owner, $filtersDineIn));
        $dataDineIn = $reportDineIn->data();

        $matrixDineIn = collect($dataDineIn->matrix)->keyBy(fn ($r) => $r->isGroupAverage ? 'group' : (string) $r->branchId);
        $rowADineIn = $matrixDineIn[(string) $this->fixture->branchA->id];
        $rowBDineIn = $matrixDineIn[(string) $this->fixture->branchB->id];

        $this->assertEquals(28, $rowADineIn->orders);
        $this->assertEquals(4480.00, $rowADineIn->sales);
        $this->assertEquals(160.00, $rowADineIn->avgTicket);

        $this->assertEquals(12, $rowBDineIn->orders);
        $this->assertEquals(1920.00, $rowBDineIn->sales);
        $this->assertEquals(160.00, $rowBDineIn->avgTicket);

        // C. Filter by delivery (no delivery orders exist)
        $filtersDelivery = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::same_weekday_last_week,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: 'delivery',
            granularity: Granularity::day,
        );

        $reportDelivery = SalesComparisonReport::for(MetricsQuery::for($this->fixture->owner, $filtersDelivery));
        $dataDelivery = $reportDelivery->data();

        $matrixDelivery = collect($dataDelivery->matrix)->keyBy(fn ($r) => $r->isGroupAverage ? 'group' : (string) $r->branchId);
        $rowADelivery = $matrixDelivery[(string) $this->fixture->branchA->id];
        $rowBDelivery = $matrixDelivery[(string) $this->fixture->branchB->id];

        $this->assertEquals(0, $rowADelivery->orders);
        $this->assertEquals(0.00, $rowADelivery->sales);
        $this->assertNull($rowADelivery->avgTicket);

        $this->assertEquals(0, $rowBDelivery->orders);
        $this->assertEquals(0.00, $rowBDelivery->sales);
        $this->assertNull($rowBDelivery->avgTicket);
    }

    public function test_branch_manager_suppresses_group_average_in_two_branch_org_to_prevent_inference(): void
    {
        $period = PeriodResolver::resolve(PeriodPreset::last_week, $this->frozenNow);
        $comparison = PeriodResolver::comparison($period, ComparisonMode::same_weekday_last_week, PeriodPreset::last_week);

        $filters = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::same_weekday_last_week,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->managerA, $filters);
        $report = SalesComparisonReport::for($query);
        $data = $report->data();

        // In a 2-branch organization, Manager A has access to Branch A and only 1 other branch exists (Branch B).
        // Showing group average would allow Manager A to solve: Sales_B = 2 * Avg - Sales_A.
        // Therefore, group average MUST be suppressed as it cannot be considered an anonymized benchmark.
        $this->assertCount(1, $data->matrix);
        $branchNames = array_map(fn ($r) => $r->branchName, $data->matrix);
        $this->assertContains('Branch A', $branchNames);
        $this->assertNotContains('متوسط المجموعة', $branchNames);
        $this->assertNotContains('Branch B', $branchNames);

        // Branch B must not exist in any other breakdown
        $this->assertCount(1, $data->scatter);
        $this->assertEquals('Branch A', $data->scatter[0]->branchName);

        $this->assertCount(1, $data->trendAbsolute->datasets);
        $this->assertEquals('Branch A', $data->trendAbsolute->datasets[0]['label']);

        $this->assertCount(1, $data->growthDecomposition);
        $this->assertEquals('Branch A', $data->growthDecomposition[0]->branchName);

        $this->assertEquals(['Branch A'], $data->channelMix->branches);
    }

    private function createBranchCWithDailyData(): Branch
    {
        $branchC = Branch::create([
            'organization_id' => $this->fixture->organization->id,
            'restaurant_id' => $this->fixture->restaurant->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Branch C',
            'status' => 'active',
            'timezone' => 'Africa/Cairo',
            'opened_on' => '2026-01-01',
        ]);

        for ($day = 3; $day <= 16; $day++) {
            $dayStr = str_pad((string) $day, 2, '0', STR_PAD_LEFT);
            DB::table('agg_branch_daily')->insert([
                'organization_id' => $this->fixture->organization->id,
                'branch_id' => $branchC->id,
                'restaurant_id' => $this->fixture->restaurant->id,
                'business_date' => "2026-10-{$dayStr}",
                'weekday' => ($day - 3) % 7,
                'orders_count' => 10,
                'net_sales' => 1000.00,
                'net_sales_after_returns' => 1000.00,
                'gross_sales' => 1050.00,
                'discounts' => 50.00,
                'returns_amount' => 0.00,
                'cancelled_count' => 0,
                'cogs' => 400.00,
                'gross_profit' => 600.00,
                'expenses_total' => 100.00,
                'labor_cost' => 0.00,
                'rent_cost' => 0.00,
                'other_expenses' => 100.00,
                'waste_cost' => 0.00,
                'inventory_variance_cost' => 0.00,
                'net_profit' => 500.00,
                'sales_by_type' => json_encode([
                    'dine_in' => ['orders' => 8, 'net_sales' => 800.00],
                    'takeaway' => ['orders' => 2, 'net_sales' => 200.00],
                ]),
                'sales_by_method' => json_encode([
                    'cash' => 600.00,
                    'card' => 400.00,
                ]),
            ]);
        }

        return $branchC;
    }

    public function test_branch_manager_suppresses_group_average_even_when_three_or_more_branches_exist(): void
    {
        $this->createBranchCWithDailyData();

        $period = PeriodResolver::resolve(PeriodPreset::last_week, $this->frozenNow);

        $filters = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->managerA, $filters);
        $report = SalesComparisonReport::for($query);
        $data = $report->data();

        // Privacy rule: Organization-level benchmark is suppressed for all users without analytics.view_group,
        // even when >=2 other branches exist, preventing inference via differencing.
        $this->assertCount(1, $data->matrix);
        $branchNames = array_map(fn ($r) => $r->branchName, $data->matrix);
        $this->assertContains('Branch A', $branchNames);
        $this->assertNotContains('متوسط المجموعة', $branchNames);
        $this->assertNotContains('Branch B', $branchNames);
        $this->assertNotContains('Branch C', $branchNames);
    }

    public function test_overlapping_filters_prevent_differencing_for_branch_manager_across_three_branches(): void
    {
        $this->createBranchCWithDailyData();

        $period = PeriodResolver::resolve(PeriodPreset::last_week, $this->frozenNow);
        $comparison = PeriodResolver::comparison($period, ComparisonMode::same_weekday_last_week, PeriodPreset::last_week);

        // Filter 1: Preset last_week with channel takeaway
        $filtersTakeaway = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::same_weekday_last_week,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: 'takeaway',
            granularity: Granularity::day,
        );

        $dataTakeaway = SalesComparisonReport::for(MetricsQuery::for($this->fixture->managerA, $filtersTakeaway))->data();
        $this->assertCount(1, $dataTakeaway->matrix);
        $this->assertEquals('Branch A', $dataTakeaway->matrix[0]->branchName);
        $this->assertFalse($dataTakeaway->matrix[0]->isGroupAverage);
        $this->assertEquals(1960.00, $dataTakeaway->overview['sales']['value']);
        $this->assertEquals(14, $dataTakeaway->overview['orders']['value']);

        // Filter 2: Preset last_week with channel dine_in
        $filtersDineIn = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::same_weekday_last_week,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: 'dine_in',
            granularity: Granularity::day,
        );

        $dataDineIn = SalesComparisonReport::for(MetricsQuery::for($this->fixture->managerA, $filtersDineIn))->data();
        $this->assertCount(1, $dataDineIn->matrix);
        $this->assertEquals('Branch A', $dataDineIn->matrix[0]->branchName);
        $this->assertEquals(4480.00, $dataDineIn->overview['sales']['value']);
        $this->assertEquals(28, $dataDineIn->overview['orders']['value']);

        // Filter 3: Overlapping custom date range (2026-10-10 to 2026-10-12)
        $customPeriod = new Period(
            CarbonImmutable::parse('2026-10-10', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-12', 'Africa/Cairo'),
        );
        $filtersCustomDate = new ReportFilters(
            preset: PeriodPreset::custom,
            period: $customPeriod,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null,
            branchSetId: null,
            restaurantId: $this->fixture->restaurant->id,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $dataCustom = SalesComparisonReport::for(MetricsQuery::for($this->fixture->managerA, $filtersCustomDate))->data();
        $this->assertCount(1, $dataCustom->matrix);
        $this->assertEquals('Branch A', $dataCustom->matrix[0]->branchName);
        $this->assertFalse($dataCustom->matrix[0]->isGroupAverage);

        // Branch B and C never appear in any breakdowns for managerA
        $this->assertCount(1, $dataCustom->scatter);
        $this->assertEquals('Branch A', $dataCustom->scatter[0]->branchName);
        $this->assertCount(1, $dataCustom->trendAbsolute->datasets);
        $this->assertEquals('Branch A', $dataCustom->trendAbsolute->datasets[0]['label']);
        $this->assertEquals(['Branch A'], $dataCustom->channelMix->branches);
    }

    public function test_authorized_owner_sees_group_average_and_all_branches_in_three_branch_org_with_overlapping_filters(): void
    {
        $this->createBranchCWithDailyData();

        $period = PeriodResolver::resolve(PeriodPreset::last_week, $this->frozenNow);
        $comparison = PeriodResolver::comparison($period, ComparisonMode::same_weekday_last_week, PeriodPreset::last_week);

        // 1. Overall report (no channel filter)
        $filtersOverall = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::same_weekday_last_week,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $reportOverall = SalesComparisonReport::for(MetricsQuery::for($this->fixture->owner, $filtersOverall));
        $dataOverall = $reportOverall->data();

        // Matrix has 3 branches + 1 group average row = 4 rows
        $this->assertCount(4, $dataOverall->matrix);
        $matrixOverall = collect($dataOverall->matrix)->keyBy(fn ($r) => $r->isGroupAverage ? 'group' : (string) $r->branchId);

        $this->assertTrue(isset($matrixOverall['group']));
        $groupRow = $matrixOverall['group'];
        $this->assertTrue($groupRow->isGroupAverage);
        $this->assertEquals('متوسط المجموعة', $groupRow->branchName);

        // (6440 [A] + 1920 [B] + 7000 [C]) / 3 = 5120.00
        $this->assertEquals(5120.00, $groupRow->sales);
        // (6340 [A] + 2240 [B] + 7000 [C]) / 3 = 5193.33
        $this->assertEquals(5193.33, round($groupRow->salesComparison, 2));
        // (42 + 12 + 70) / 3 = 41.33 -> round = 41
        $this->assertEquals(41, $groupRow->orders);
        // (42 + 14 + 70) / 3 = 42
        $this->assertEquals(42, $groupRow->ordersComparison);

        // 2. Overlapping channel filter: takeaway
        $filtersTakeaway = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::same_weekday_last_week,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: 'takeaway',
            granularity: Granularity::day,
        );

        $reportTakeaway = SalesComparisonReport::for(MetricsQuery::for($this->fixture->owner, $filtersTakeaway));
        $dataTakeaway = $reportTakeaway->data();

        $matrixTakeaway = collect($dataTakeaway->matrix)->keyBy(fn ($r) => $r->isGroupAverage ? 'group' : (string) $r->branchId);
        $groupRowTakeaway = $matrixTakeaway['group'];
        // (1960 [A] + 0 [B] + 1400 [C]) / 3 = 1120.00
        $this->assertEquals(1120.00, $groupRowTakeaway->sales);
        // (14 [A] + 0 [B] + 14 [C]) / 3 = 9.3 orders
        $this->assertEquals(9, $groupRowTakeaway->orders);

        // 3. Branch scoping preserved for authorized owner when selecting specific branches
        $filtersScoped = new ReportFilters(
            preset: PeriodPreset::last_week,
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

        $reportScoped = SalesComparisonReport::for(MetricsQuery::for($this->fixture->owner, $filtersScoped));
        $dataScoped = $reportScoped->data();

        // Scoped to Branch A and B, plus group benchmark row = 3 rows
        $this->assertCount(3, $dataScoped->matrix);
        $scopedBranchNames = array_map(fn ($r) => $r->branchName, $dataScoped->matrix);
        $this->assertContains('Branch A', $scopedBranchNames);
        $this->assertContains('Branch B', $scopedBranchNames);
        $this->assertNotContains('Branch C', $scopedBranchNames);
        $this->assertContains('متوسط المجموعة', $scopedBranchNames);
    }

    public function test_authorized_analyst_sees_group_average_and_all_branches_in_three_branch_org_with_overlapping_filters(): void
    {
        $this->createBranchCWithDailyData();

        $period = PeriodResolver::resolve(PeriodPreset::last_week, $this->frozenNow);
        $comparison = PeriodResolver::comparison($period, ComparisonMode::same_weekday_last_week, PeriodPreset::last_week);

        $filters = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::same_weekday_last_week,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: 'dine_in',
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->analyst, $filters);
        $report = SalesComparisonReport::for($query);
        $data = $report->data();

        // Analyst has analytics.view_group: matrix includes all branches + group average
        $this->assertCount(4, $data->matrix);
        $matrixDineIn = collect($data->matrix)->keyBy(fn ($r) => $r->isGroupAverage ? 'group' : (string) $r->branchId);
        $this->assertTrue(isset($matrixDineIn['group']));

        $groupRow = $matrixDineIn['group'];
        // Dine-in: (4480 [A] + 1920 [B] + 5600 [C]) / 3 = 4000.00
        $this->assertEquals(4000.00, $groupRow->sales);
        // Dine-in orders: (28 [A] + 12 [B] + 56 [C]) / 3 = 32
        $this->assertEquals(32, $groupRow->orders);

        // Breakdown collections include all 3 branches
        $this->assertCount(3, $data->scatter);
        $this->assertCount(3, $data->trendAbsolute->datasets);
        $this->assertCount(3, $data->growthDecomposition);
        $this->assertCount(3, $data->discountIntensity);
    }

    public function test_traffic_scatter_uses_days_with_data_for_per_day_averages(): void
    {
        $period = PeriodResolver::resolve(PeriodPreset::last_week, $this->frozenNow);

        $filters = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $report = SalesComparisonReport::for(MetricsQuery::for($this->fixture->owner, $filters));
        $data = $report->data();

        $scatterByBranch = collect($data->scatter)->keyBy('branchId');
        $pointA = $scatterByBranch[$this->fixture->branchA->id];
        $pointB = $scatterByBranch[$this->fixture->branchB->id];

        // Branch A: 42 orders over 7 days = 6.00 orders/day
        $this->assertEquals(6.00, $pointA->ordersPerDay);
        $this->assertEquals(153.33, round($pointA->avgTicket, 2));

        // Branch B: 12 orders over 6 days = 2.00 orders/day (not 12 / 7)
        $this->assertEquals(2.00, $pointB->ordersPerDay);
        $this->assertEquals(160.00, round($pointB->avgTicket, 2));

        // Medians
        $this->assertEquals(4.00, $data->medianOrdersPerDay);
        $this->assertEquals(156.67, round($data->medianAvgTicket, 2));
    }

    public function test_indexed_trend_first_bucket_is_100(): void
    {
        $period = PeriodResolver::resolve(PeriodPreset::last_week, $this->frozenNow);

        $filters = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $report = SalesComparisonReport::for(MetricsQuery::for($this->fixture->owner, $filters));
        $data = $report->data();

        $trendIndexed = collect($data->trendIndexed->datasets)->keyBy('branch_id');
        $dsA = $trendIndexed[$this->fixture->branchA->id]['data'];
        $dsB = $trendIndexed[$this->fixture->branchB->id]['data'];

        // Branch A has data on all 7 days, so first bucket is 100.0
        $this->assertEquals(100.0, $dsA[0]);

        // Branch B has missing data on 2026-10-10 (bucket 0 is 0.0)
        // First non-zero bucket is on 2026-10-11 (bucket 1), which is indexed to 100.0
        $this->assertEquals(0.0, $dsB[0]);
        $this->assertEquals(100.0, $dsB[1]);
    }

    public function test_channel_mix_empty_when_not_offered(): void
    {
        $period = PeriodResolver::resolve(PeriodPreset::last_week, $this->frozenNow);

        $filters = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $period,
            comparisonMode: ComparisonMode::none,
            comparison: null,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $report = SalesComparisonReport::for(MetricsQuery::for($this->fixture->owner, $filters));
        $data = $report->data();

        $channelMix = $data->channelMix;
        $branchBIndex = array_search('Branch B', $channelMix->branches, true);
        if (! is_int($branchBIndex)) {
            $this->fail('Branch B not found in branches');
        }

        // Branch B has no takeaway orders -> should be null (empty), not 0.0
        $this->assertNull($channelMix->channelSeries['takeaway'][$branchBIndex]);

        // Branch A has takeaway orders -> should be a valid percentage
        $branchAIndex = array_search('Branch A', $channelMix->branches, true);
        if (! is_int($branchAIndex)) {
            $this->fail('Branch A not found in branches');
        }
        $this->assertNotNull($channelMix->channelSeries['takeaway'][$branchAIndex]);
    }
}
