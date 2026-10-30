<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ShiftsWeekdaysReport;

final readonly class HourlyHeatmapData
{
    /**
     * @param  list<int>  $hours
     * @param  list<int>  $weekdays
     * @param  list<string>  $weekdayLabels
     * @param  array<int, array<int, float>>  $matrixOrders
     * @param  array<int, array<int, float>>  $matrixSales
     */
    public function __construct(
        public int $branchId,
        public string $branchName,
        public array $hours,
        public array $weekdays,
        public array $weekdayLabels,
        public array $matrixOrders,
        public array $matrixSales,
        public float $maxOrders,
        public float $maxSales,
    ) {}
}
