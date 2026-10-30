<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ShiftsWeekdaysReport;

final readonly class ShiftsWeekdaysReportData
{
    /**
     * @param  array<int, HourlyHeatmapData>  $hourlyHeatmaps  Keyed by branchId
     * @param  list<ShiftRow>  $shifts
     * @param  list<ShiftSlotRow>  $shiftSlots
     * @param  list<CashierSummaryRow>  $cashierSummary
     */
    public function __construct(
        public ShiftsOverviewData $overview,
        public WeekdayProfileData $weekdayProfile,
        public array $hourlyHeatmaps,
        public PeakHoursData $peakHours,
        public array $shifts,
        public array $shiftSlots,
        public array $cashierSummary,
    ) {}
}
