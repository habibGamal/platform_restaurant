<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ShiftsWeekdaysReport;

final readonly class ShiftRow
{
    public function __construct(
        public string $id,
        public int $branchId,
        public string $branchName,
        public string $shiftUuid,
        public string $businessDate,
        public int $weekday,
        public string $weekdayLabel,
        public ?string $startAt,
        public ?string $endAt,
        public string $timeRange,
        public ?int $durationMinutes,
        public string $durationFormatted,
        public ?string $cashierUuid,
        public string $cashierName,
        public int $ordersCount,
        public float $netSales, // K06
        public ?float $salesPerHour,
        public ?float $avgTicket,
        public int $cancelledCount,
        public float $cashDeficit, // K25
        public bool $hasDeficit,
    ) {}
}
