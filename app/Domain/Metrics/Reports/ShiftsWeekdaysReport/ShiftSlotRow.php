<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ShiftsWeekdaysReport;

final readonly class ShiftSlotRow
{
    public function __construct(
        public string $id,
        public int $branchId,
        public string $branchName,
        public string $slotKey,
        public string $slotLabel,
        public int $shiftsCount,
        public float $totalSales,
        public ?float $avgSalesPerShift,
        public ?float $salesPerHour,
        public ?float $avgTicket,
        public int $cancelledCount,
    ) {}
}
