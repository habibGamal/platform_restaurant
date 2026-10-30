<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ShiftsWeekdaysReport;

final readonly class CashierSummaryRow
{
    public function __construct(
        public string $id,
        public string $cashierUuid,
        public string $cashierName,
        public int $branchId,
        public string $branchName,
        public int $shiftsCount,
        public float $totalSales,
        public ?float $salesPerHour,
        public ?float $avgTicket,
        public int $ordersCount,
        public int $cancelledCount,
        public ?float $cancelRate,
        public int $deficitCount,
        public float $deficitTotal,
    ) {}
}
