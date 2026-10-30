<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport\CashierSummaryRow;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport\HourlyHeatmapData;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport\PeakHoursData;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport\ShiftRow;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport\ShiftSlotRow;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport\ShiftsOverviewData;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport\ShiftsWeekdaysReportData;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport\WeekdayProfileData;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport\WeekdayProfileDataset;
use App\Models\Branch;
use App\Support\Weekday;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ShiftsWeekdaysReport
{
    private ?ShiftsWeekdaysReportData $cachedData = null;

    public function __construct(
        public readonly MetricsQuery $query,
        public readonly bool $excludeCalendarEvents = true,
    ) {}

    public static function for(
        MetricsQuery $query,
        bool $excludeCalendarEvents = true,
    ): self {
        return new self($query, $excludeCalendarEvents);
    }

    public function data(): ShiftsWeekdaysReportData
    {
        if ($this->cachedData !== null) {
            return $this->cachedData;
        }

        $filters = $this->query->filters();
        $period = $filters->period;
        $comparisonPeriod = $filters->comparison;
        $branchIds = $this->query->branchIds();

        if (empty($branchIds)) {
            return $this->emptyReportData();
        }

        $canSeeOtherBranchNames = $this->query->canSeeOtherBranchNames();
        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get()
            ->keyBy('id');

        $currentExcludedDates = $this->getExcludedDates($period);
        $comparisonExcludedDates = $comparisonPeriod !== null ? $this->getExcludedDates($comparisonPeriod) : [];

        // 1. Overview KPIs
        $currentShiftsAgg = $this->fetchShiftsData($period, $branchIds, $currentExcludedDates);
        $comparisonShiftsAgg = $comparisonPeriod !== null
            ? $this->fetchShiftsData($comparisonPeriod, $branchIds, $comparisonExcludedDates)
            : null;

        $overview = $this->buildOverview($currentShiftsAgg, $comparisonShiftsAgg);

        // 2. Weekday Profile
        $weekdayProfile = $this->buildWeekdayProfile($period, $branchIds, $branches, $canSeeOtherBranchNames, $currentExcludedDates);

        // 3. Hourly Heatmaps per branch
        $hourlyHeatmaps = $this->buildHourlyHeatmaps($period, $branchIds, $branches, $canSeeOtherBranchNames, $currentExcludedDates);

        // 4. Peak Hours comparison chart
        $peakHours = $this->buildPeakHours($period, $branchIds, $branches, $canSeeOtherBranchNames, $currentExcludedDates);

        // 5. Shift Table
        $shifts = $this->buildShiftRows($currentShiftsAgg, $branches, $canSeeOtherBranchNames);

        // 6. Shift Slots comparison
        $shiftSlots = $this->buildShiftSlotRows($shifts);

        // 7. Cashier Summary
        $cashierSummary = $this->buildCashierSummaryRows($shifts);

        $this->cachedData = new ShiftsWeekdaysReportData(
            overview: $overview,
            weekdayProfile: $weekdayProfile,
            hourlyHeatmaps: $hourlyHeatmaps,
            peakHours: $peakHours,
            shifts: $shifts,
            shiftSlots: $shiftSlots,
            cashierSummary: $cashierSummary,
        );

        return $this->cachedData;
    }

    /**
     * @return array<string, true>
     */
    private function getExcludedDates(Period $period): array
    {
        if (! $this->excludeCalendarEvents) {
            return [];
        }

        $orgId = $this->query->user->organization_id;
        $events = DB::table('calendar_events')
            ->where(function ($q) use ($orgId): void {
                if ($orgId !== null) {
                    $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                } else {
                    $q->whereNull('organization_id');
                }
            })
            ->whereIn('kind', ['ramadan', 'eid'])
            ->where('date_from', '<=', $period->to->toDateString())
            ->where('date_to', '>=', $period->from->toDateString())
            ->get(['date_from', 'date_to']);

        $excluded = [];
        foreach ($events as $event) {
            $eStart = CarbonImmutable::parse((string) $event->date_from)->startOfDay();
            $eEnd = CarbonImmutable::parse((string) $event->date_to)->startOfDay();

            $cursor = $eStart > $period->from ? $eStart : $period->from;
            $limit = $eEnd < $period->to ? $eEnd : $period->to;

            while ($cursor <= $limit) {
                $excluded[$cursor->toDateString()] = true;
                $cursor = $cursor->addDay();
            }
        }

        return $excluded;
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, true>  $excludedDates
     * @return array{
     *     shifts: Collection<int, \stdClass>,
     *     staffMap: array<string, string>,
     *     returnsByShift: array<string, float>
     * }
     */
    private function fetchShiftsData(Period $period, array $branchIds, array $excludedDates): array
    {
        // 1. Find closed shifts
        $closedShiftUuids = DB::table('wh_shifts')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->where('closed', true)
            ->whereNull('deleted_at')
            ->pluck('uuid')
            ->flip()
            ->all();

        // 2. Query agg_shift
        $shiftQuery = $this->query->scoped('agg_shift', $period);
        if (! empty($excludedDates)) {
            $shiftQuery->whereNotIn('business_date', array_keys($excludedDates));
        }

        $allShifts = $shiftQuery
            ->orderBy('business_date', 'desc')
            ->orderBy('start_at', 'desc')
            ->get();

        // Filter closed shifts
        $closedShifts = $allShifts->filter(fn (\stdClass $s): bool => isset($closedShiftUuids[(string) $s->shift_uuid]))->values();

        // 3. Query returns per shift
        $returnsQuery = DB::table('wh_order_returns')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereNull('deleted_at')
            ->whereNotNull('shift_uuid');

        if (! empty($excludedDates)) {
            $returnsQuery->whereNotIn('business_date', array_keys($excludedDates));
        }

        $returnsByShift = $returnsQuery
            ->groupBy('shift_uuid')
            ->selectRaw('shift_uuid, COALESCE(SUM(total_refund), 0) as total_refund')
            ->pluck('total_refund', 'shift_uuid')
            ->map(fn ($val): float => (float) $val)
            ->all();

        // 4. Query dim_staff (including soft deleted)
        $staffMap = DB::table('dim_staff')
            ->whereIn('branch_id', $branchIds)
            ->get(['branch_id', 'uuid', 'name'])
            ->mapWithKeys(fn (\stdClass $s): array => [$s->branch_id.'_'.$s->uuid => (string) $s->name])
            ->all();

        return [
            'shifts' => $closedShifts,
            'staffMap' => $staffMap,
            'returnsByShift' => $returnsByShift,
        ];
    }

    /**
     * @param  array{shifts: Collection<int, \stdClass>, staffMap: array<string, string>, returnsByShift: array<string, float>}  $current
     * @param  array{shifts: Collection<int, \stdClass>, staffMap: array<string, string>, returnsByShift: array<string, float>}|null  $comparison
     */
    private function buildOverview(array $current, ?array $comparison): ShiftsOverviewData
    {
        $currentTotals = $this->summarizeShifts($current['shifts'], $current['returnsByShift']);
        $comparisonTotals = $comparison !== null
            ? $this->summarizeShifts($comparison['shifts'], $comparison['returnsByShift'])
            : null;

        return new ShiftsOverviewData(
            totalShifts: $currentTotals['count'],
            totalShiftsComparison: $comparisonTotals !== null ? $comparisonTotals['count'] : null,
            totalSales: $currentTotals['sales'],
            totalSalesComparison: $comparisonTotals !== null ? $comparisonTotals['sales'] : null,
            avgSalesPerShift: $currentTotals['count'] > 0 ? round($currentTotals['sales'] / $currentTotals['count'], 2) : null,
            avgSalesPerShiftComparison: ($comparisonTotals !== null && $comparisonTotals['count'] > 0)
                ? round($comparisonTotals['sales'] / $comparisonTotals['count'], 2)
                : null,
            salesPerHour: $currentTotals['hours'] > 0 ? round($currentTotals['sales'] / $currentTotals['hours'], 2) : null,
            salesPerHourComparison: ($comparisonTotals !== null && $comparisonTotals['hours'] > 0)
                ? round($comparisonTotals['sales'] / $comparisonTotals['hours'], 2)
                : null,
            avgTicket: $currentTotals['orders'] > 0 ? round($currentTotals['sales'] / $currentTotals['orders'], 2) : null,
            avgTicketComparison: ($comparisonTotals !== null && $comparisonTotals['orders'] > 0)
                ? round($comparisonTotals['sales'] / $comparisonTotals['orders'], 2)
                : null,
            cancelledOrders: $currentTotals['cancelled'],
            cancelledOrdersComparison: $comparisonTotals !== null ? $comparisonTotals['cancelled'] : null,
            totalDeficits: $currentTotals['deficits'],
            totalDeficitsComparison: $comparisonTotals !== null ? $comparisonTotals['deficits'] : null,
            deficitCount: $currentTotals['deficitCount'],
            deficitCountComparison: $comparisonTotals !== null ? $comparisonTotals['deficitCount'] : null,
        );
    }

    /**
     * @param  Collection<int, \stdClass>  $shifts
     * @param  array<string, float>  $returnsByShift
     * @return array{
     *     count: int,
     *     sales: float,
     *     hours: float,
     *     orders: int,
     *     cancelled: int,
     *     deficits: float,
     *     deficitCount: int
     * }
     */
    private function summarizeShifts(Collection $shifts, array $returnsByShift): array
    {
        $count = $shifts->count();
        $sales = 0.0;
        $totalMinutes = 0;
        $orders = 0;
        $cancelled = 0;
        $deficits = 0.0;
        $deficitCount = 0;

        foreach ($shifts as $shift) {
            $shiftUuid = (string) $shift->shift_uuid;
            $refund = $returnsByShift[$shiftUuid] ?? 0.0;
            $shiftSales = max(0.0, (float) $shift->net_sales - $refund);

            $sales += $shiftSales;
            $orders += (int) $shift->orders_count;
            $cancelled += (int) $shift->cancelled_count;

            if ($shift->duration_minutes !== null) {
                $totalMinutes += (int) $shift->duration_minutes;
            }

            $deficit = (float) $shift->cash_deficit;
            if ($deficit > 0.0) {
                $deficits += $deficit;
                $deficitCount++;
            }
        }

        return [
            'count' => $count,
            'sales' => round($sales, 2),
            'hours' => $totalMinutes > 0 ? ($totalMinutes / 60.0) : 0.0,
            'orders' => $orders,
            'cancelled' => $cancelled,
            'deficits' => round($deficits, 2),
            'deficitCount' => $deficitCount,
        ];
    }

    /**
     * @param  list<int>  $branchIds
     * @param  Collection<int, Branch>  $branches
     * @param  array<string, true>  $excludedDates
     */
    private function buildWeekdayProfile(
        Period $period,
        array $branchIds,
        Collection $branches,
        bool $canSeeOtherBranchNames,
        array $excludedDates,
    ): WeekdayProfileData {
        $dailyQuery = $this->query->scoped('agg_branch_daily', $period);
        if (! empty($excludedDates)) {
            $dailyQuery->whereNotIn('business_date', array_keys($excludedDates));
        }

        $dailyRows = $dailyQuery->get(['branch_id', 'business_date', 'weekday', 'net_sales_after_returns']);

        // Group rows by branch_id
        /** @var array<int, list<\stdClass>> $rowsByBranch */
        $rowsByBranch = [];
        foreach ($dailyRows as $row) {
            $rowsByBranch[(int) $row->branch_id][] = $row;
        }

        $datasets = [];

        foreach ($branchIds as $branchId) {
            $branchRows = $rowsByBranch[$branchId] ?? [];
            $branchModel = $branches->get($branchId);
            $branchName = $this->resolveBranchLabel($branchId, $branchModel, $canSeeOtherBranchNames);

            // Group by weekday (0..6)
            /** @var array<int, array{sales: float, days: array<string, bool>}> $weekdayMap */
            $weekdayMap = [];
            for ($w = 0; $w <= 6; $w++) {
                $weekdayMap[$w] = ['sales' => 0.0, 'days' => []];
            }

            $allDistinctDays = [];
            $totalBranchSales = 0.0;

            foreach ($branchRows as $row) {
                $w = (int) $row->weekday;
                $dateStr = (string) $row->business_date;
                $sales = (float) $row->net_sales_after_returns;

                if (isset($weekdayMap[$w])) {
                    $weekdayMap[$w]['sales'] += $sales;
                    $weekdayMap[$w]['days'][$dateStr] = true;
                }

                $allDistinctDays[$dateStr] = true;
                $totalBranchSales += $sales;
            }

            $totalDaysWithData = count($allDistinctDays);
            $overallDailyAverage = $totalDaysWithData > 0 ? ($totalBranchSales / $totalDaysWithData) : 0.0;

            $data = [];
            $indexedData = [];
            $sampleCounts = [];
            $isSmallSample = [];

            for ($w = 0; $w <= 6; $w++) {
                $count = count($weekdayMap[$w]['days']);
                $salesSum = $weekdayMap[$w]['sales'];
                $avg = $count > 0 ? round($salesSum / $count, 2) : 0.0;

                $data[] = $avg;
                $sampleCounts[] = $count;
                $isSmallSample[] = $count < 3;

                if ($overallDailyAverage > 0.0 && $count > 0) {
                    $indexedData[] = round(($avg / $overallDailyAverage) * 100.0, 1);
                } else {
                    $indexedData[] = 0.0;
                }
            }

            $datasets[] = new WeekdayProfileDataset(
                branchId: $branchId,
                label: $branchName,
                data: $data,
                indexedData: $indexedData,
                sampleCounts: $sampleCounts,
                isSmallSample: $isSmallSample,
                overallDailyAverage: round($overallDailyAverage, 2),
            );
        }

        return new WeekdayProfileData(
            labels: Weekday::LABELS,
            datasets: $datasets,
        );
    }

    /**
     * @param  list<int>  $branchIds
     * @param  Collection<int, Branch>  $branches
     * @param  array<string, true>  $excludedDates
     * @return array<int, HourlyHeatmapData>
     */
    private function buildHourlyHeatmaps(
        Period $period,
        array $branchIds,
        Collection $branches,
        bool $canSeeOtherBranchNames,
        array $excludedDates,
    ): array {
        // Query distinct days per weekday per branch from agg_branch_daily
        $dailyQuery = $this->query->scoped('agg_branch_daily', $period);
        if (! empty($excludedDates)) {
            $dailyQuery->whereNotIn('business_date', array_keys($excludedDates));
        }

        $dailyRows = $dailyQuery->get(['branch_id', 'business_date', 'weekday']);
        /** @var array<int, array<int, array<string, bool>>> $weekdayDaysPerBranch */
        $weekdayDaysPerBranch = [];
        foreach ($dailyRows as $row) {
            $bId = (int) $row->branch_id;
            $w = (int) $row->weekday;
            $weekdayDaysPerBranch[$bId][$w][(string) $row->business_date] = true;
        }

        $hourlyQuery = $this->query->scoped('agg_branch_hourly', $period);
        if (! empty($excludedDates)) {
            $hourlyQuery->whereNotIn('business_date', array_keys($excludedDates));
        }

        $hourlyRows = $hourlyQuery->get(['branch_id', 'business_date', 'hour', 'weekday', 'orders_count', 'net_sales']);

        /** @var array<int, list<\stdClass>> $hourlyByBranch */
        $hourlyByBranch = [];
        $hoursFound = [];

        foreach ($hourlyRows as $row) {
            $bId = (int) $row->branch_id;
            $h = (int) $row->hour;
            $hourlyByBranch[$bId][] = $row;
            $hoursFound[$h] = true;
        }

        // Determine hours list: at least 0..23, plus any >= 24
        $maxHourFound = empty($hoursFound) ? 23 : max(array_keys($hoursFound));
        $maxHour = max(23, $maxHourFound);
        $hours = range(0, $maxHour);

        $heatmaps = [];

        foreach ($branchIds as $branchId) {
            $branchModel = $branches->get($branchId);
            $branchName = $this->resolveBranchLabel($branchId, $branchModel, $canSeeOtherBranchNames);
            $bRows = $hourlyByBranch[$branchId] ?? [];

            // Aggregate totals per [hour][weekday]
            $ordersSum = [];
            $salesSum = [];

            foreach ($hours as $h) {
                for ($w = 0; $w <= 6; $w++) {
                    $ordersSum[$h][$w] = 0.0;
                    $salesSum[$h][$w] = 0.0;
                }
            }

            foreach ($bRows as $row) {
                $h = (int) $row->hour;
                $w = (int) $row->weekday;
                if (isset($ordersSum[$h][$w])) {
                    $ordersSum[$h][$w] += (float) $row->orders_count;
                    $salesSum[$h][$w] += (float) $row->net_sales;
                }
            }

            $matrixOrders = [];
            $matrixSales = [];
            $maxOrders = 0.0;
            $maxSales = 0.0;

            foreach ($hours as $h) {
                for ($w = 0; $w <= 6; $w++) {
                    $dayCount = isset($weekdayDaysPerBranch[$branchId][$w])
                        ? count($weekdayDaysPerBranch[$branchId][$w])
                        : 0;

                    $avgOrders = $dayCount > 0 ? round($ordersSum[$h][$w] / $dayCount, 1) : 0.0;
                    $avgSales = $dayCount > 0 ? round($salesSum[$h][$w] / $dayCount, 2) : 0.0;

                    $matrixOrders[$h][$w] = $avgOrders;
                    $matrixSales[$h][$w] = $avgSales;

                    if ($avgOrders > $maxOrders) {
                        $maxOrders = $avgOrders;
                    }
                    if ($avgSales > $maxSales) {
                        $maxSales = $avgSales;
                    }
                }
            }

            $heatmaps[$branchId] = new HourlyHeatmapData(
                branchId: $branchId,
                branchName: $branchName,
                hours: $hours,
                weekdays: range(0, 6),
                weekdayLabels: Weekday::LABELS,
                matrixOrders: $matrixOrders,
                matrixSales: $matrixSales,
                maxOrders: $maxOrders,
                maxSales: $maxSales,
            );
        }

        return $heatmaps;
    }

    /**
     * @param  list<int>  $branchIds
     * @param  Collection<int, Branch>  $branches
     * @param  array<string, true>  $excludedDates
     */
    private function buildPeakHours(
        Period $period,
        array $branchIds,
        Collection $branches,
        bool $canSeeOtherBranchNames,
        array $excludedDates,
    ): PeakHoursData {
        // Query distinct days with data per branch
        $dailyQuery = $this->query->scoped('agg_branch_daily', $period);
        if (! empty($excludedDates)) {
            $dailyQuery->whereNotIn('business_date', array_keys($excludedDates));
        }

        $dailyRows = $dailyQuery->get(['branch_id', 'business_date']);
        /** @var array<int, array<string, bool>> $daysWithDataPerBranch */
        $daysWithDataPerBranch = [];
        foreach ($dailyRows as $row) {
            $daysWithDataPerBranch[(int) $row->branch_id][(string) $row->business_date] = true;
        }

        $hourlyQuery = $this->query->scoped('agg_branch_hourly', $period);
        if (! empty($excludedDates)) {
            $hourlyQuery->whereNotIn('business_date', array_keys($excludedDates));
        }

        $hourlyRows = $hourlyQuery->get(['branch_id', 'hour', 'orders_count']);

        /** @var array<int, array<int, float>> $hourlyOrdersPerBranch */
        $hourlyOrdersPerBranch = [];
        foreach ($hourlyRows as $row) {
            $bId = (int) $row->branch_id;
            $h = (int) $row->hour;
            $hourlyOrdersPerBranch[$bId][$h] = ($hourlyOrdersPerBranch[$bId][$h] ?? 0.0) + (float) $row->orders_count;
        }

        $hours = range(0, 23);
        $labels = array_map(fn (int $h): string => sprintf('%02d:00', $h), $hours);
        $datasets = [];

        foreach ($branchIds as $branchId) {
            $branchModel = $branches->get($branchId);
            $branchName = $this->resolveBranchLabel($branchId, $branchModel, $canSeeOtherBranchNames);
            $totalDays = isset($daysWithDataPerBranch[$branchId]) ? count($daysWithDataPerBranch[$branchId]) : 0;

            $data = [];
            foreach ($hours as $h) {
                $orders = $hourlyOrdersPerBranch[$branchId][$h] ?? 0.0;
                $avg = $totalDays > 0 ? round($orders / $totalDays, 2) : 0.0;
                $data[] = $avg;
            }

            $datasets[] = [
                'branch_id' => $branchId,
                'label' => $branchName,
                'data' => $data,
            ];
        }

        return new PeakHoursData(
            labels: $labels,
            datasets: $datasets,
        );
    }

    /**
     * @param  array{shifts: Collection<int, \stdClass>, staffMap: array<string, string>, returnsByShift: array<string, float>}  $shiftsData
     * @param  Collection<int, Branch>  $branches
     * @return list<ShiftRow>
     */
    private function buildShiftRows(
        array $shiftsData,
        Collection $branches,
        bool $canSeeOtherBranchNames,
    ): array {
        $rows = [];
        $staffMap = $shiftsData['staffMap'];
        $returnsByShift = $shiftsData['returnsByShift'];

        foreach ($shiftsData['shifts'] as $shift) {
            $branchId = (int) $shift->branch_id;
            $branchModel = $branches->get($branchId);
            $branchName = $this->resolveBranchLabel($branchId, $branchModel, $canSeeOtherBranchNames);

            $shiftUuid = (string) $shift->shift_uuid;
            $refund = $returnsByShift[$shiftUuid] ?? 0.0;
            $netSales = max(0.0, (float) $shift->net_sales - $refund); // K06
            $ordersCount = (int) $shift->orders_count;
            $cancelledCount = (int) $shift->cancelled_count;
            $cashDeficit = (float) $shift->cash_deficit;

            $durationMinutes = $shift->duration_minutes !== null ? (int) $shift->duration_minutes : null;
            $hours = ($durationMinutes !== null && $durationMinutes > 0) ? ($durationMinutes / 60.0) : null;
            $salesPerHour = ($hours !== null && $hours > 0) ? round($netSales / $hours, 2) : null;
            $avgTicket = $ordersCount > 0 ? round($netSales / $ordersCount, 2) : null;

            $cashierUuid = $shift->cashier_uuid ? (string) $shift->cashier_uuid : null;
            $cashierName = '—';
            if ($cashierUuid !== null) {
                $staffKey = $branchId.'_'.$cashierUuid;
                $cashierName = $staffMap[$staffKey] ?? $cashierUuid;
            }

            $startAtStr = $shift->start_at ? Carbon::parse((string) $shift->start_at)->format('H:i') : null;
            $endAtStr = $shift->end_at ? Carbon::parse((string) $shift->end_at)->format('H:i') : null;
            $timeRange = ($startAtStr && $endAtStr) ? "{$startAtStr} - {$endAtStr}" : '—';

            $durationFormatted = '—';
            if ($durationMinutes !== null) {
                $h = intdiv($durationMinutes, 60);
                $m = $durationMinutes % 60;
                $durationFormatted = $m > 0 ? "{$h} س {$m} د" : "{$h} س";
            }

            $weekday = (int) $shift->weekday;
            $weekdayLabel = Weekday::label($weekday);

            $rows[] = new ShiftRow(
                id: $branchId.'_'.$shiftUuid,
                branchId: $branchId,
                branchName: $branchName,
                shiftUuid: $shiftUuid,
                businessDate: (string) $shift->business_date,
                weekday: $weekday,
                weekdayLabel: $weekdayLabel,
                startAt: $shift->start_at ? (string) $shift->start_at : null,
                endAt: $shift->end_at ? (string) $shift->end_at : null,
                timeRange: $timeRange,
                durationMinutes: $durationMinutes,
                durationFormatted: $durationFormatted,
                cashierUuid: $cashierUuid,
                cashierName: $cashierName,
                ordersCount: $ordersCount,
                netSales: round($netSales, 2),
                salesPerHour: $salesPerHour,
                avgTicket: $avgTicket,
                cancelledCount: $cancelledCount,
                cashDeficit: round($cashDeficit, 2),
                hasDeficit: $cashDeficit > 0.0,
            );
        }

        return $rows;
    }

    /**
     * @param  list<ShiftRow>  $shifts
     * @return list<ShiftSlotRow>
     */
    private function buildShiftSlotRows(array $shifts): array
    {
        /** @var array<string, array{
         *     branchId: int,
         *     branchName: string,
         *     slotKey: string,
         *     slotLabel: string,
         *     shiftsCount: int,
         *     totalSales: float,
         *     totalMinutes: int,
         *     ordersCount: int,
         *     cancelledCount: int
         * }> $grouped */
        $grouped = [];

        foreach ($shifts as $shift) {
            $startHour = 9; // Default fallback
            if ($shift->startAt !== null) {
                $startHour = (int) Carbon::parse($shift->startAt)->format('H');
            }

            $isMorning = $startHour < 15;
            $slotKey = $isMorning ? 'morning' : 'evening';
            $slotLabel = $isMorning ? 'صباحية (< 15:00)' : 'مسائية (≥ 15:00)';
            $groupKey = $shift->branchId.'_'.$slotKey;

            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'branchId' => $shift->branchId,
                    'branchName' => $shift->branchName,
                    'slotKey' => $slotKey,
                    'slotLabel' => $slotLabel,
                    'shiftsCount' => 0,
                    'totalSales' => 0.0,
                    'totalMinutes' => 0,
                    'ordersCount' => 0,
                    'cancelledCount' => 0,
                ];
            }

            $grouped[$groupKey]['shiftsCount']++;
            $grouped[$groupKey]['totalSales'] += $shift->netSales;
            $grouped[$groupKey]['ordersCount'] += $shift->ordersCount;
            $grouped[$groupKey]['cancelledCount'] += $shift->cancelledCount;
            if ($shift->durationMinutes !== null) {
                $grouped[$groupKey]['totalMinutes'] += $shift->durationMinutes;
            }
        }

        $rows = [];
        foreach ($grouped as $k => $data) {
            $shiftsCount = $data['shiftsCount'];
            $totalSales = round($data['totalSales'], 2);
            $hours = $data['totalMinutes'] > 0 ? ($data['totalMinutes'] / 60.0) : 0.0;
            $ordersCount = $data['ordersCount'];

            $rows[] = new ShiftSlotRow(
                id: $k,
                branchId: $data['branchId'],
                branchName: $data['branchName'],
                slotKey: $data['slotKey'],
                slotLabel: $data['slotLabel'],
                shiftsCount: $shiftsCount,
                totalSales: $totalSales,
                avgSalesPerShift: $shiftsCount > 0 ? round($totalSales / $shiftsCount, 2) : null,
                salesPerHour: $hours > 0 ? round($totalSales / $hours, 2) : null,
                avgTicket: $ordersCount > 0 ? round($totalSales / $ordersCount, 2) : null,
                cancelledCount: $data['cancelledCount'],
            );
        }

        return $rows;
    }

    /**
     * @param  list<ShiftRow>  $shifts
     * @return list<CashierSummaryRow>
     */
    private function buildCashierSummaryRows(array $shifts): array
    {
        /** @var array<string, array{
         *     cashierUuid: string,
         *     cashierName: string,
         *     branchId: int,
         *     branchName: string,
         *     shiftsCount: int,
         *     totalSales: float,
         *     totalMinutes: int,
         *     ordersCount: int,
         *     cancelledCount: int,
         *     deficitCount: int,
         *     deficitTotal: float
         * }> $grouped */
        $grouped = [];

        foreach ($shifts as $shift) {
            $cUuid = $shift->cashierUuid ?? 'unknown';
            $groupKey = $shift->branchId.'_'.$cUuid;

            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'cashierUuid' => $cUuid,
                    'cashierName' => $shift->cashierName,
                    'branchId' => $shift->branchId,
                    'branchName' => $shift->branchName,
                    'shiftsCount' => 0,
                    'totalSales' => 0.0,
                    'totalMinutes' => 0,
                    'ordersCount' => 0,
                    'cancelledCount' => 0,
                    'deficitCount' => 0,
                    'deficitTotal' => 0.0,
                ];
            }

            $grouped[$groupKey]['shiftsCount']++;
            $grouped[$groupKey]['totalSales'] += $shift->netSales;
            $grouped[$groupKey]['ordersCount'] += $shift->ordersCount;
            $grouped[$groupKey]['cancelledCount'] += $shift->cancelledCount;
            if ($shift->durationMinutes !== null) {
                $grouped[$groupKey]['totalMinutes'] += $shift->durationMinutes;
            }

            if ($shift->hasDeficit) {
                $grouped[$groupKey]['deficitCount']++;
                $grouped[$groupKey]['deficitTotal'] += $shift->cashDeficit;
            }
        }

        $rows = [];
        foreach ($grouped as $k => $data) {
            $totalSales = round($data['totalSales'], 2);
            $hours = $data['totalMinutes'] > 0 ? ($data['totalMinutes'] / 60.0) : 0.0;
            $orders = $data['ordersCount'];
            $cancelled = $data['cancelledCount'];
            $totalAttempts = $orders + $cancelled;

            $rows[] = new CashierSummaryRow(
                id: $k,
                cashierUuid: $data['cashierUuid'],
                cashierName: $data['cashierName'],
                branchId: $data['branchId'],
                branchName: $data['branchName'],
                shiftsCount: $data['shiftsCount'],
                totalSales: $totalSales,
                salesPerHour: $hours > 0 ? round($totalSales / $hours, 2) : null,
                avgTicket: $orders > 0 ? round($totalSales / $orders, 2) : null,
                ordersCount: $orders,
                cancelledCount: $cancelled,
                cancelRate: $totalAttempts > 0 ? round($cancelled / $totalAttempts, 4) : null,
                deficitCount: $data['deficitCount'],
                deficitTotal: round($data['deficitTotal'], 2),
            );
        }

        return $rows;
    }

    private function resolveBranchLabel(int $branchId, ?Branch $branch, bool $canSeeOtherBranchNames): string
    {
        if ($branch === null) {
            return "فرع {$branchId}";
        }

        if ($canSeeOtherBranchNames) {
            return $branch->name;
        }

        return $branch->name;
    }

    private function emptyReportData(): ShiftsWeekdaysReportData
    {
        return new ShiftsWeekdaysReportData(
            overview: new ShiftsOverviewData(
                totalShifts: 0,
                totalShiftsComparison: null,
                totalSales: 0.0,
                totalSalesComparison: null,
                avgSalesPerShift: null,
                avgSalesPerShiftComparison: null,
                salesPerHour: null,
                salesPerHourComparison: null,
                avgTicket: null,
                avgTicketComparison: null,
                cancelledOrders: 0,
                cancelledOrdersComparison: null,
                totalDeficits: 0.0,
                totalDeficitsComparison: null,
                deficitCount: 0,
                deficitCountComparison: null,
            ),
            weekdayProfile: new WeekdayProfileData(
                labels: Weekday::LABELS,
                datasets: [],
            ),
            hourlyHeatmaps: [],
            peakHours: new PeakHoursData(
                labels: [],
                datasets: [],
            ),
            shifts: [],
            shiftSlots: [],
            cashierSummary: [],
        );
    }
}
