<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\SalesComparisonReport;

final readonly class GrowthDecompositionRow
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public int $orders0,
        public int $orders1,
        public float $ticket0,
        public float $ticket1,
        public float $netSales0,
        public float $netSales1,
        public float $deltaNetSales,
        public float $ordersEffect,
        public float $ticketEffect,
        public float $returns0,
        public float $returns1,
        public float $returnsEffect,
        public float $sales0,
        public float $sales1,
        public float $deltaSales,
    ) {}
}
