<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ProductPerformanceReport;

final readonly class ProductDetailDailyRow
{
    public function __construct(
        public string $businessDate,
        public int $branchId,
        public string $branchName,
        public float $qtySold,
        public float $netSales,
        public float $cogs,
        public float $grossProfit,
        public ?float $marginPct,
        public float $price,
        public float $unitCost,
        public float $returnsQty,
        public float $wasteQty,
        public float $wasteCost,
    ) {}
}
