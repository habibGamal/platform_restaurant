<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ShiftsWeekdaysReport;

final readonly class ShiftsOverviewData
{
    public function __construct(
        public int $totalShifts,
        public ?int $totalShiftsComparison,
        public float $totalSales,
        public ?float $totalSalesComparison,
        public ?float $avgSalesPerShift,
        public ?float $avgSalesPerShiftComparison,
        public ?float $salesPerHour,
        public ?float $salesPerHourComparison,
        public ?float $avgTicket,
        public ?float $avgTicketComparison,
        public int $cancelledOrders,
        public ?int $cancelledOrdersComparison,
        public float $totalDeficits,
        public ?float $totalDeficitsComparison,
        public int $deficitCount,
        public ?int $deficitCountComparison,
    ) {}
}
