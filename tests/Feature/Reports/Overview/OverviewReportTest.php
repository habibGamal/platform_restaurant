<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Overview;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\PeriodResolver;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Metrics\Reports\OverviewReport;
use App\Models\Warehouse\WhOrderReturn;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class OverviewReportTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 12:00:00', 'Africa/Cairo'));
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_last_week_group_kpis_equal_fixture_expected_values(): void
    {
        $today = CarbonImmutable::now('Africa/Cairo');
        $preset = PeriodPreset::last_week;
        $period = PeriodResolver::resolve($preset, $today);
        $comparisonMode = PeriodResolver::defaultComparison($preset);
        $comparison = PeriodResolver::comparison($period, $comparisonMode, $preset);

        $filters = new ReportFilters(
            preset: $preset,
            period: $period,
            comparisonMode: $comparisonMode,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = OverviewReport::for($query);
        $cards = $report->kpiCards();

        // 1. K06: Net sales after returns
        // Branch A (7 * 920.00 = 6,440.00) + Branch B (6 * 320.00 = 1,920.00) = 8,360.00
        $this->assertEqualsWithDelta(8360.00, $cards['sales']['value'], 0.01);

        // 2. K14: Net profit
        // Branch A (7 * 510.00 = 3,570.00) + Branch B (145 + 5 * 175 = 1,020.00) = 4,590.00
        $this->assertEqualsWithDelta(4590.00, $cards['net_profit']['value'], 0.01);

        // 3. K15: Net margin = 4,590.00 / 8,360.00 = 0.549043...
        $this->assertEqualsWithDelta(4590.00 / 8360.00, $cards['net_margin']['value'], 0.001);

        // 4. K11: Expenses total
        // Branch A (7 * 50.00 = 350.00) + Branch B (6 * 20.00 = 120.00) = 470.00
        $this->assertEqualsWithDelta(470.00, $cards['expenses']['value'], 0.01);

        // 5. K20: Net purchases
        // Branch A (0 invoice - 50.00 return on 10-13 = -50.00) + Branch B (0) = -50.00
        $this->assertEqualsWithDelta(-50.00, $cards['net_purchases']['value'], 0.01);

        // 6. K01: Orders count
        // Branch A (42) + Branch B (12) = 54
        $this->assertSame(54, $cards['orders_count']['value']);

        // 7. K07: Avg ticket = 8,360.00 / 54 = 154.8148...
        $this->assertEqualsWithDelta(8360.00 / 54, $cards['avg_ticket']['value'], 0.01);

        // 8. K12: Waste cost
        // Branch A (0.00) + Branch B (30.00 on 10-11) = 30.00
        $this->assertEqualsWithDelta(30.00, $cards['waste']['value'], 0.01);

        // Assert comparison numbers for week 1 (2026-10-03 to 2026-10-09)
        // Comparison sales: Branch A (6340.00) + Branch B (2240.00) = 8580.00
        $this->assertEqualsWithDelta(8580.00, $cards['sales']['comparison'], 0.01);
        // Growth: (8360 - 8580) / 8580 = -0.02564
        $this->assertEqualsWithDelta((8360.00 - 8580.00) / 8580.00, $cards['sales']['growth'], 0.001);

        // Comparison net profit: Branch A (2380.00) + Branch B (725.00) = 3105.00
        $this->assertEqualsWithDelta(3105.00, $cards['net_profit']['comparison'], 0.01);
        $this->assertEqualsWithDelta((4590.00 - 3105.00) / 3105.00, $cards['net_profit']['growth'], 0.001);

        // Sparklines have 14 points
        $this->assertCount(14, $cards['sales']['sparkline']);
        $this->assertCount(14, $cards['net_profit']['sparkline']);
    }

    public function test_today_view_compares_with_hourly_totals_up_to_current_hour(): void
    {
        // Set test now to 2026-10-10 at 14:00
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 14:00:00', 'Africa/Cairo'));
        $today = CarbonImmutable::now('Africa/Cairo');

        $preset = PeriodPreset::today;
        $period = PeriodResolver::resolve($preset, $today, nowTime: '14:00');
        $comparisonMode = PeriodResolver::defaultComparison($preset);
        $comparison = PeriodResolver::comparison($period, $comparisonMode, $preset);

        $filters = new ReportFilters(
            preset: $preset,
            period: $period,
            comparisonMode: $comparisonMode,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = OverviewReport::for($query);
        $cards = $report->kpiCards();

        // Comparison period is 2026-10-03 (same weekday last week) up to 14:00
        // agg_branch_hourly has orders and net_sales up to hour 14
        $expectedCompHourly = $query->hourlyTotals($comparison, '14:00');

        // K06: Reliable return event timestamp is unavailable in actual schema.
        // The report must return null for sales comparison and growth rather than invent timing.
        $this->assertNull($cards['sales']['comparison']);
        $this->assertNull($cards['sales']['growth']);

        // Orders count comparison is from agg_branch_hourly
        $this->assertSame($expectedCompHourly['orders'], $cards['orders_count']['comparison']);

        // K07: Actual avg ticket is preserved from hourly totals (net_sales / orders)
        $expectedCompAvgTicket = $expectedCompHourly['orders'] > 0
            ? ($expectedCompHourly['net_sales'] / $expectedCompHourly['orders'])
            : null;
        $this->assertEqualsWithDelta($expectedCompAvgTicket, $cards['avg_ticket']['comparison'], 0.01);
        $this->assertNotNull($cards['avg_ticket']['growth']);
    }

    public function test_today_view_returns_null_sales_comparison_when_return_event_timestamp_unavailable(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 14:00:00', 'Africa/Cairo'));
        $today = CarbonImmutable::now('Africa/Cairo');

        $preset = PeriodPreset::today;
        $period = PeriodResolver::resolve($preset, $today, nowTime: '14:00');
        $comparisonMode = PeriodResolver::defaultComparison($preset);
        $comparison = PeriodResolver::comparison($period, $comparisonMode, $preset);

        // 1. Add active return on comparison date (2026-10-03) within Shift 1 (start_at 09:00 <= 14:00)
        WhOrderReturn::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => 'ret-comp-cutoff-active',
            'source_version' => 1,
            'business_date' => '2026-10-03',
            'order_uuid' => 'order-a-2026-10-03-1',
            'shift_uuid' => 'shift-a-2026-10-03-1',
            'total_refund' => 75.00,
            'reverse_stock' => true,
            'returned_cogs' => 30.00,
        ]);

        // 2. Add soft-deleted return on comparison date (must be ignored)
        WhOrderReturn::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => 'ret-comp-cutoff-deleted',
            'source_version' => 1,
            'business_date' => '2026-10-03',
            'order_uuid' => 'order-a-2026-10-03-2',
            'shift_uuid' => 'shift-a-2026-10-03-1',
            'total_refund' => 120.00,
            'reverse_stock' => true,
            'returned_cogs' => 45.00,
            'deleted_at' => '2026-10-03 13:00:00',
        ]);

        $filters = new ReportFilters(
            preset: $preset,
            period: $period,
            comparisonMode: $comparisonMode,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = OverviewReport::for($query);
        $cards = $report->kpiCards();

        $compHourly = $query->hourlyTotals($comparison, '14:00');

        // Since reliable return event timestamp is unavailable in actual schema,
        // the report does not invent timing from shift start_at or order hour.
        $this->assertNull($cards['sales']['comparison']);
        $this->assertNull($cards['sales']['growth']);

        // Actual average ticket K07 is preserved based on hourly completed orders and net sales
        $expectedCompAvgTicket = $compHourly['orders'] > 0 ? ($compHourly['net_sales'] / $compHourly['orders']) : null;
        $this->assertEqualsWithDelta($expectedCompAvgTicket, $cards['avg_ticket']['comparison'], 0.01);
        $this->assertSame($compHourly['orders'], $cards['orders_count']['comparison']);
    }

    public function test_late_returns_do_not_falsely_deduct_from_cutoff_comparison(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 14:00:00', 'Africa/Cairo'));
        $today = CarbonImmutable::now('Africa/Cairo');

        $preset = PeriodPreset::today;
        $period = PeriodResolver::resolve($preset, $today, nowTime: '14:00');
        $comparisonMode = PeriodResolver::defaultComparison($preset);
        $comparison = PeriodResolver::comparison($period, $comparisonMode, $preset);

        // Order was placed at 10:00 (<= 14:00 cutoff hour), but return occurred late (or on a later date).
        // Under the flawed code, wh_orders.hour_of_day <= 14 falsely deducted this return from cutoff sales!
        WhOrderReturn::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => 'ret-late-return-demonstration',
            'source_version' => 1,
            'business_date' => '2026-10-03',
            'order_uuid' => 'order-a-2026-10-03-1',
            'shift_uuid' => null, // referenced an order from morning, but return timing is unknown
            'total_refund' => 200.00,
            'reverse_stock' => true,
            'returned_cogs' => 80.00,
        ]);

        $filters = new ReportFilters(
            preset: $preset,
            period: $period,
            comparisonMode: $comparisonMode,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = OverviewReport::for($query);
        $cards = $report->kpiCards();

        // The report avoids false deductions of late returns by returning null for comparison/growth
        $this->assertNull($cards['sales']['comparison']);
        $this->assertNull($cards['sales']['growth']);

        // Orders count and average ticket (K07) are accurately preserved from hourly completed orders
        $compHourly = $query->hourlyTotals($comparison, '14:00');
        $this->assertSame($compHourly['orders'], $cards['orders_count']['comparison']);
        $expectedCompAvgTicket = $compHourly['orders'] > 0 ? ($compHourly['net_sales'] / $compHourly['orders']) : null;
        $this->assertEqualsWithDelta($expectedCompAvgTicket, $cards['avg_ticket']['comparison'], 0.01);
    }

    public function test_negative_sales_and_comparisons_are_not_clipped(): void
    {
        // When returns exceed sales, net sales after returns is negative.
        // Update agg_branch_daily for specific dates to have negative net_sales_after_returns.
        DB::table('agg_branch_daily')
            ->where('branch_id', $this->fixture->branchA->id)
            ->where('business_date', '2026-10-15')
            ->update([
                'net_sales' => 100.00,
                'returns_amount' => 350.00,
                'net_sales_after_returns' => -250.00,
            ]);

        DB::table('agg_branch_daily')
            ->where('branch_id', $this->fixture->branchA->id)
            ->where('business_date', '2026-10-08')
            ->update([
                'net_sales' => 100.00,
                'returns_amount' => 600.00,
                'net_sales_after_returns' => -500.00,
            ]);

        $currentPeriod = new Period(
            CarbonImmutable::parse('2026-10-15', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-15', 'Africa/Cairo')
        );
        $comparisonPeriod = new Period(
            CarbonImmutable::parse('2026-10-08', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-08', 'Africa/Cairo')
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
        $report = OverviewReport::for($query);
        $cards = $report->kpiCards();

        // 1. Current net sales after returns is negative: must NOT be clipped to 0.0
        $this->assertEqualsWithDelta(-250.00, $cards['sales']['value'], 0.01);

        // 2. Comparison sales is negative: must NOT be clipped with max(0, ...)
        $this->assertEqualsWithDelta(-500.00, $cards['sales']['comparison'], 0.01);

        // 3. Growth: (-250 - (-500)) / abs(-500) = 250 / 500 = +0.50 (+50%)
        $this->assertEqualsWithDelta(0.50, $cards['sales']['growth'], 0.001);

        // 4. Branch table row must also preserve negative sales and growth
        $tableData = $report->branchTable();
        $branchRow = $tableData['branches'][$this->fixture->branchA->id];
        $this->assertEqualsWithDelta(-250.00, $branchRow['sales'], 0.01);
        $this->assertEqualsWithDelta(0.50, $branchRow['growth'], 0.001);
    }

    public function test_cross_branch_uuid_correlation_isolation_and_access_scoping(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 14:00:00', 'Africa/Cairo'));
        $today = CarbonImmutable::now('Africa/Cairo');

        $preset = PeriodPreset::today;
        $period = PeriodResolver::resolve($preset, $today, nowTime: '14:00');
        $comparisonMode = PeriodResolver::defaultComparison($preset);
        $comparison = PeriodResolver::comparison($period, $comparisonMode, $preset);

        // Branch B has a return with shift_uuid that collides with Branch A's shift uuid
        WhOrderReturn::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchB->id,
            'uuid' => 'ret-branch-b-colliding-shift-uuid',
            'source_version' => 1,
            'business_date' => '2026-10-03',
            'order_uuid' => 'order-b-diff',
            'shift_uuid' => 'shift-a-2026-10-03-1', // same shift UUID as Branch A
            'total_refund' => 500.00,
            'reverse_stock' => true,
            'returned_cogs' => 200.00,
        ]);

        // Manager A has access ONLY to Branch A
        $filters = new ReportFilters(
            preset: $preset,
            period: $period,
            comparisonMode: $comparisonMode,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $queryManagerA = MetricsQuery::for($this->fixture->managerA, $filters);
        $reportManagerA = OverviewReport::for($queryManagerA);
        $cardsA = $reportManagerA->kpiCards();

        // Branch A manager sees only Branch A data; cross-branch UUID never correlates
        $this->assertNull($cardsA['sales']['comparison']);
        $hourlyA = $queryManagerA->hourlyTotals($comparison, '14:00');
        $this->assertSame($hourlyA['orders'], $cardsA['orders_count']['comparison']);

        // Check branch table: manager A cannot see branch B, group totals hidden
        $tableA = $reportManagerA->branchTable();
        $this->assertArrayHasKey($this->fixture->branchA->id, $tableA['branches']);
        $this->assertArrayNotHasKey($this->fixture->branchB->id, $tableA['branches']);
        $this->assertNull($tableA['totals']);
    }

    public function test_branch_table_per_branch_numbers_and_totals(): void
    {
        $today = CarbonImmutable::now('Africa/Cairo');
        $preset = PeriodPreset::last_week;
        $period = PeriodResolver::resolve($preset, $today);
        $comparisonMode = PeriodResolver::defaultComparison($preset);
        $comparison = PeriodResolver::comparison($period, $comparisonMode, $preset);

        $filters = new ReportFilters(
            preset: $preset,
            period: $period,
            comparisonMode: $comparisonMode,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = OverviewReport::for($query);
        $tableData = $report->branchTable('sales', 'desc');

        $this->assertCount(2, $tableData['branches']);
        $this->assertNotNull($tableData['totals']);

        $branchA = $tableData['branches'][$this->fixture->branchA->id];
        $this->assertEqualsWithDelta(6440.00, $branchA['sales'], 0.01);
        $this->assertSame(42, $branchA['orders']);
        $this->assertEqualsWithDelta(3570.00, $branchA['net_profit'], 0.01);
        $this->assertEqualsWithDelta(350.00, $branchA['expenses'], 0.01);

        $branchB = $tableData['branches'][$this->fixture->branchB->id];
        $this->assertEqualsWithDelta(1920.00, $branchB['sales'], 0.01);
        $this->assertSame(12, $branchB['orders']);
        $this->assertEqualsWithDelta(1020.00, $branchB['net_profit'], 0.01);
        $this->assertEqualsWithDelta(120.00, $branchB['expenses'], 0.01);

        // Group totals
        $this->assertEqualsWithDelta(8360.00, $tableData['totals']['sales'], 0.01);
        $this->assertEqualsWithDelta(4590.00, $tableData['totals']['net_profit'], 0.01);
        $this->assertSame(54, $tableData['totals']['orders']);
    }

    public function test_branch_manager_a_scope_and_group_totals_hidden(): void
    {
        $today = CarbonImmutable::now('Africa/Cairo');
        $preset = PeriodPreset::last_week;
        $period = PeriodResolver::resolve($preset, $today);
        $comparisonMode = PeriodResolver::defaultComparison($preset);
        $comparison = PeriodResolver::comparison($period, $comparisonMode, $preset);

        $filters = new ReportFilters(
            preset: $preset,
            period: $period,
            comparisonMode: $comparisonMode,
            comparison: $comparison,
            branchIds: null,
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->managerA, $filters);
        $report = OverviewReport::for($query);
        $tableData = $report->branchTable();

        // Manager A sees only Branch A
        $this->assertCount(1, $tableData['branches']);
        $this->assertArrayHasKey($this->fixture->branchA->id, $tableData['branches']);
        $this->assertArrayNotHasKey($this->fixture->branchB->id, $tableData['branches']);

        // Group totals are NOT computed for branch managers (must be null)
        $this->assertNull($tableData['totals']);
    }

    public function test_side_panels_data_supported(): void
    {
        $today = CarbonImmutable::now('Africa/Cairo');
        $preset = PeriodPreset::last_week;
        $period = PeriodResolver::resolve($preset, $today);

        $filters = new ReportFilters(
            preset: $preset,
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

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = OverviewReport::for($query);

        // 1. Profit bridge
        $bridge = $report->profitBridge();
        $this->assertEqualsWithDelta(8360.00, $bridge['sales'], 0.01);
        $this->assertEqualsWithDelta(4590.00, $bridge['net_profit'], 0.01);

        // 2. Expense mix
        $mix = $report->expenseMix();
        $this->assertNotEmpty($mix);

        // 3. Sales by channel
        $channels = $report->salesByChannel();
        $this->assertNotEmpty($channels);

        // 4. Data freshness
        $freshness = $report->dataFreshness();
        $this->assertArrayHasKey('summary', $freshness);
        $this->assertArrayHasKey('branches', $freshness);
        $this->assertCount(2, $freshness['branches']);
    }
}
