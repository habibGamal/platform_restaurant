<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\SalesComparisonReport;

final readonly class TrafficScatterPoint
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public float $ordersPerDay,
        public float $avgTicket,
        public float $sales,
    ) {}
}
