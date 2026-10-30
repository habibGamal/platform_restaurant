<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\ShiftsWeekdays;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport;
use App\Models\CalendarEvent;
use App\Models\Warehouse\DimStaff;
use App\Models\Warehouse\WhOrderReturn;
use App\Models\Warehouse\WhShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class ShiftsWeekdaysReportTest extends TestCase
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

    public function test_matches_expected_numbers_and_acceptance_criteria_for_fixture(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03', 'Africa/Cairo')->startOfDay(),
            to: CarbonImmutable::parse('2026-10-16', 'Africa/Cairo')->startOfDay(),
            cutoffTime: null,
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::custom,
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
        $report = ShiftsWeekdaysReport::for($query);
        $data = $report->data();

        // 1. Overview KPIs
        $this->assertEquals(54, $data->overview->totalShifts); // 28 for A + 26 for B
        $this->assertEquals(16940.00, $data->overview->totalSales); // 12780 for A + 4160 for B
        $this->assertEquals(313.70, $data->overview->avgSalesPerShift); // 16940 / 54
        $this->assertEquals(48.13, $data->overview->salesPerHour); // 16940 / 352 hours
        $this->assertEquals(154.00, $data->overview->avgTicket); // 16940 / 110 orders
        $this->assertEquals(1, $data->overview->cancelledOrders);
        $this->assertEquals(0.00, $data->overview->totalDeficits);
        $this->assertEquals(0, $data->overview->deficitCount);

        // 2. Weekday Profile Acceptance criteria:
        // "Fixture: weekday averages for A match expected values; the day where B is missing is excluded from B's averages (not counted as 0)."
        $this->assertCount(2, $data->weekdayProfile->datasets);

        $profileByBranch = [];
        foreach ($data->weekdayProfile->datasets as $ds) {
            $profileByBranch[$ds->branchId] = $ds;
        }

        // Branch A
        $dsA = $profileByBranch[$this->fixture->branchA->id];
        $this->assertEquals('Branch A', $dsA->label);
        $this->assertEquals(920.00, $dsA->data[0]); // Saturday (w=0): 1840 / 2
        $this->assertEquals(920.00, $dsA->data[1]); // Sunday (w=1): 1840 / 2
        $this->assertEquals(920.00, $dsA->data[2]); // Monday (w=2): 1840 / 2
        $this->assertEquals(870.00, $dsA->data[3]); // Tuesday (w=3): (820 + 920) / 2 = 870
        $this->assertEquals(920.00, $dsA->data[4]); // Wednesday (w=4): 1840 / 2
        $this->assertEquals(920.00, $dsA->data[5]); // Thursday (w=5): 1840 / 2
        $this->assertEquals(920.00, $dsA->data[6]); // Friday (w=6): 1840 / 2
        $this->assertEquals([2, 2, 2, 2, 2, 2, 2], $dsA->sampleCounts);
        $this->assertEquals([true, true, true, true, true, true, true], $dsA->isSmallSample);
        $this->assertEquals(912.86, $dsA->overallDailyAverage);

        // Branch B: Missing day (2026-10-10, Sat) excluded from averages
        $dsB = $profileByBranch[$this->fixture->branchB->id];
        $this->assertEquals('Branch B', $dsB->label);
        $this->assertEquals(320.00, $dsB->data[0]); // Saturday: 1 day with data = 320.00 (NOT 160.00!)
        $this->assertEquals(320.00, $dsB->data[1]); // Sunday: 2 days = 320.00
        $this->assertEquals(320.00, $dsB->data[2]); // Monday: 2 days = 320.00
        $this->assertEquals(320.00, $dsB->data[3]); // Tuesday: 2 days = 320.00
        $this->assertEquals(320.00, $dsB->data[4]); // Wednesday: 2 days = 320.00
        $this->assertEquals(320.00, $dsB->data[5]); // Thursday: 2 days = 320.00
        $this->assertEquals(320.00, $dsB->data[6]); // Friday: 2 days = 320.00
        $this->assertEquals([1, 2, 2, 2, 2, 2, 2], $dsB->sampleCounts);
        $this->assertEquals([true, true, true, true, true, true, true], $dsB->isSmallSample);
        $this->assertEquals(320.00, $dsB->overallDailyAverage);

        // 3. Shift Table Acceptance criteria:
        // "Shift table total sales = report 01 total for the same period/branches."
        $branchAShiftSales = 0.0;
        $branchBShiftSales = 0.0;
        foreach ($data->shifts as $shift) {
            if ($shift->branchId === $this->fixture->branchA->id) {
                $branchAShiftSales += $shift->netSales;
            } elseif ($shift->branchId === $this->fixture->branchB->id) {
                $branchBShiftSales += $shift->netSales;
            }
        }

        // Branch A total in Report 01 = 12780.00
        $this->assertEquals(12780.00, round($branchAShiftSales, 2));
        // Branch B total in Report 01 = 4160.00
        $this->assertEquals(4160.00, round($branchBShiftSales, 2));
        // Overall total shift sales
        $this->assertEquals(16940.00, round($branchAShiftSales + $branchBShiftSales, 2));

        // 4. Cashier Summary Acceptance criteria:
        // "Deficit totals per cashier match fixture shifts."
        $cashiers = [];
        foreach ($data->cashierSummary as $cs) {
            $cashiers[$cs->cashierUuid] = $cs;
        }

        $this->assertArrayHasKey('user-cashier-1', $cashiers);
        $this->assertArrayHasKey('user-cashier-2', $cashiers);
        $this->assertArrayHasKey('user-b-cashier-1', $cashiers);
        $this->assertArrayHasKey('user-b-cashier-2', $cashiers);

        $c1 = $cashiers['user-cashier-1'];
        $this->assertEquals(14, $c1->shiftsCount);
        $this->assertEquals(6340.00, $c1->totalSales); // 14 × 460 - 100 return
        $this->assertEquals(1, $c1->cancelledCount); // 1 cancelled order in shift 1 on 2026-10-04
        $this->assertEquals(0.00, $c1->deficitTotal);
        $this->assertEquals(0, $c1->deficitCount);

        $c2 = $cashiers['user-cashier-2'];
        $this->assertEquals(14, $c2->shiftsCount);
        $this->assertEquals(6440.00, $c2->totalSales); // 14 × 460
        $this->assertEquals(0, $c2->cancelledCount);
        $this->assertEquals(0.00, $c2->deficitTotal);
        $this->assertEquals(0, $c2->deficitCount);

        // 5. Shift Slots:
        $slots = [];
        foreach ($data->shiftSlots as $slot) {
            $slots[$slot->branchId.'_'.$slot->slotKey] = $slot;
        }

        $aMorning = $slots[$this->fixture->branchA->id.'_morning'];
        $this->assertEquals(14, $aMorning->shiftsCount);
        $this->assertEquals(6340.00, $aMorning->totalSales);

        $aEvening = $slots[$this->fixture->branchA->id.'_evening'];
        $this->assertEquals(14, $aEvening->shiftsCount);
        $this->assertEquals(6440.00, $aEvening->totalSales);

        // 6. Hourly Heatmaps:
        $this->assertArrayHasKey($this->fixture->branchA->id, $data->hourlyHeatmaps);
        $heatmapA = $data->hourlyHeatmaps[$this->fixture->branchA->id];
        $this->assertEquals('Branch A', $heatmapA->branchName);
        $this->assertCount(24, $heatmapA->hours);
    }

    public function test_handles_cash_deficits_correctly(): void
    {
        // Add a shift with a deficit
        $deficitShiftUuid = 'shift-deficit-test-1';
        WhShift::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => $deficitShiftUuid,
            'source_version' => 1,
            'business_date' => '2026-10-03',
            'user_uuid' => 'user-deficit-cashier',
            'start_at' => '2026-10-03 23:00:00',
            'end_at' => '2026-10-04 03:00:00',
            'start_cash' => 500.00,
            'end_cash' => 1000.00,
            'real_cash' => 850.00, // Deficit of 150
            'closed' => true,
        ]);

        DB::table('agg_shift')->insert([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'shift_uuid' => $deficitShiftUuid,
            'business_date' => '2026-10-03',
            'weekday' => 0,
            'start_at' => '2026-10-03 23:00:00',
            'end_at' => '2026-10-04 03:00:00',
            'duration_minutes' => 240,
            'cashier_uuid' => 'user-deficit-cashier',
            'orders_count' => 5,
            'net_sales' => 500.00,
            'gross_profit' => 300.00,
            'avg_ticket' => 100.00,
            'cancelled_count' => 0,
            'cash_deficit' => 150.00,
        ]);

        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03', 'Africa/Cairo')->startOfDay(),
            to: CarbonImmutable::parse('2026-10-03', 'Africa/Cairo')->startOfDay(),
            cutoffTime: null,
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
        $report = ShiftsWeekdaysReport::for($query);
        $data = $report->data();

        $this->assertEquals(150.00, $data->overview->totalDeficits);
        $this->assertEquals(1, $data->overview->deficitCount);

        // Verify shift row has deficit flagged
        $deficitShift = collect($data->shifts)->firstWhere('shiftUuid', $deficitShiftUuid);
        $this->assertNotNull($deficitShift);
        $this->assertTrue($deficitShift->hasDeficit);
        $this->assertEquals(150.00, $deficitShift->cashDeficit);

        // Verify cashier summary has deficit
        $deficitCashier = collect($data->cashierSummary)->firstWhere('cashierUuid', 'user-deficit-cashier');
        $this->assertNotNull($deficitCashier);
        $this->assertEquals(1, $deficitCashier->deficitCount);
        $this->assertEquals(150.00, $deficitCashier->deficitTotal);
    }

    public function test_resolves_dim_staff_cashier_name_including_soft_deleted(): void
    {
        // Create an active staff member and a soft-deleted staff member
        DimStaff::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => 'user-cashier-1',
            'source_version' => 1,
            'name' => 'أحمد محمود (كاشير أول)',
            'role' => 'cashier',
        ]);

        $deletedStaff = DimStaff::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => 'user-cashier-2',
            'source_version' => 1,
            'name' => 'محمد علي (كاشير سابق)',
            'role' => 'cashier',
        ]);
        $deletedStaff->delete(); // Soft delete

        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03', 'Africa/Cairo')->startOfDay(),
            to: CarbonImmutable::parse('2026-10-03', 'Africa/Cairo')->startOfDay(),
            cutoffTime: null,
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
        $report = ShiftsWeekdaysReport::for($query);
        $data = $report->data();

        $c1 = collect($data->cashierSummary)->firstWhere('cashierUuid', 'user-cashier-1');
        $this->assertNotNull($c1);
        $this->assertEquals('أحمد محمود (كاشير أول)', $c1->cashierName);

        $c2 = collect($data->cashierSummary)->firstWhere('cashierUuid', 'user-cashier-2');
        $this->assertNotNull($c2);
        // Soft-deleted cashier name still resolved from dim_staff!
        $this->assertEquals('محمد علي (كاشير سابق)', $c2->cashierName);
    }

    public function test_excludes_calendar_events_when_toggle_is_on(): void
    {
        // Create calendar event for 2026-10-06 (Tuesday)
        CalendarEvent::create([
            'organization_id' => $this->fixture->organization->id,
            'name' => 'عيد الفطر المبارك',
            'kind' => 'eid',
            'date_from' => '2026-10-06',
            'date_to' => '2026-10-06',
        ]);

        $period = new Period(
            from: CarbonImmutable::parse('2026-10-03', 'Africa/Cairo')->startOfDay(),
            to: CarbonImmutable::parse('2026-10-16', 'Africa/Cairo')->startOfDay(),
            cutoffTime: null,
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

        // When excluded (default = true): 2026-10-06 (sales 820) is excluded
        $reportExcluded = ShiftsWeekdaysReport::for($query, excludeCalendarEvents: true);
        $dataExcluded = $reportExcluded->data();

        // 26 shifts instead of 28 for A (the 2 shifts of 2026-10-06 are excluded)
        $this->assertEquals(26, $dataExcluded->overview->totalShifts);
        // Sales: 12780 - 820 = 11960.00
        $this->assertEquals(11960.00, $dataExcluded->overview->totalSales);

        // When not excluded (false): 2026-10-06 is included
        $reportIncluded = ShiftsWeekdaysReport::for($query, excludeCalendarEvents: false);
        $dataIncluded = $reportIncluded->data();

        $this->assertEquals(28, $dataIncluded->overview->totalShifts);
        $this->assertEquals(12780.00, $dataIncluded->overview->totalSales);
    }

    public function test_calculates_comparison_period(): void
    {
        $currentPeriod = new Period(
            from: CarbonImmutable::parse('2026-10-10', 'Africa/Cairo')->startOfDay(),
            to: CarbonImmutable::parse('2026-10-16', 'Africa/Cairo')->startOfDay(),
            cutoffTime: null,
        );

        $compPeriod = new Period(
            from: CarbonImmutable::parse('2026-10-03', 'Africa/Cairo')->startOfDay(),
            to: CarbonImmutable::parse('2026-10-09', 'Africa/Cairo')->startOfDay(),
            cutoffTime: null,
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::last_week,
            period: $currentPeriod,
            comparisonMode: ComparisonMode::same_weekday_last_week,
            comparison: $compPeriod,
            branchIds: [$this->fixture->branchA->id],
            branchSetId: null,
            restaurantId: null,
            likeForLike: false,
            channel: null,
            granularity: Granularity::day,
        );

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = ShiftsWeekdaysReport::for($query);
        $data = $report->data();

        $this->assertEquals(14, $data->overview->totalShifts);
        $this->assertEquals(14, $data->overview->totalShiftsComparison);
        // Week 2 sales for A = 7 × 920 = 6440.00
        $this->assertEquals(6440.00, $data->overview->totalSales);
        // Week 1 sales for A = 6 × 920 + 820 = 6340.00
        $this->assertEquals(6340.00, $data->overview->totalSalesComparison);
    }

    public function test_soft_deleted_shifts_are_omitted_and_soft_deleted_returns_do_not_affect_sales(): void
    {
        $period = new Period(
            from: CarbonImmutable::parse('2026-10-10', 'Africa/Cairo')->startOfDay(),
            to: CarbonImmutable::parse('2026-10-16', 'Africa/Cairo')->startOfDay(),
            cutoffTime: null,
        );

        $filters = new ReportFilters(
            preset: PeriodPreset::last_week,
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

        // Baseline: Week 2 for Branch A has 14 shifts and 6440.00 total sales
        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = ShiftsWeekdaysReport::for($query);
        $baselineData = $report->data();
        $this->assertEquals(14, $baselineData->overview->totalShifts);
        $this->assertEquals(6440.00, $baselineData->overview->totalSales);

        // 1. Insert a soft-deleted shift into wh_shifts (and agg_shift)
        $deletedShiftUuid = 'shift-a-deleted-test';
        WhShift::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => $deletedShiftUuid,
            'source_version' => 1,
            'business_date' => '2026-10-12',
            'user_uuid' => 'user-cashier-1',
            'start_at' => '2026-10-12 18:00:00',
            'end_at' => '2026-10-12 23:00:00',
            'closed' => true,
            'deleted_at' => '2026-10-12 23:30:00',
        ]);
        DB::table('agg_shift')->insert([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'shift_uuid' => $deletedShiftUuid,
            'business_date' => '2026-10-12',
            'cashier_uuid' => 'user-cashier-1',
            'start_at' => '2026-10-12 18:00:00',
            'end_at' => '2026-10-12 23:00:00',
            'duration_minutes' => 300,
            'weekday' => 2,
            'orders_count' => 10,
            'net_sales' => 1500.00,
            'gross_profit' => 800.00,
            'cancelled_count' => 0,
            'avg_ticket' => 150.00,
            'cash_deficit' => 0.00,
        ]);

        // 2. Insert a soft-deleted order return referencing an existing active shift
        $activeShiftUuid = 'shift-a-2026-10-10-1';
        WhOrderReturn::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'uuid' => 'return-deleted-test',
            'source_version' => 1,
            'business_date' => '2026-10-10',
            'order_uuid' => 'order-a-2026-10-10-1',
            'shift_uuid' => $activeShiftUuid,
            'total_refund' => 200.00,
            'reverse_stock' => true,
            'returned_cogs' => 80.00,
            'deleted_at' => '2026-10-10 16:00:00',
        ]);

        // Re-run report
        $reportWithDeletions = ShiftsWeekdaysReport::for($query);
        $dataWithDeletions = $reportWithDeletions->data();

        // Soft-deleted shift MUST NOT be included: total shifts stays 14
        $this->assertEquals(14, $dataWithDeletions->overview->totalShifts);
        $shiftUuids = array_map(fn ($s) => $s->shiftUuid, $dataWithDeletions->shifts);
        $this->assertNotContains($deletedShiftUuid, $shiftUuids);

        // Soft-deleted return MUST NOT affect sales: total sales stays 6440.00
        $this->assertEquals(6440.00, $dataWithDeletions->overview->totalSales);
        $activeShiftRow = collect($dataWithDeletions->shifts)->firstWhere('shiftUuid', $activeShiftUuid);
        $this->assertNotNull($activeShiftRow);
        $this->assertEquals(460.00, $activeShiftRow->netSales);
    }
}
