<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ProductPerformanceReport;

final readonly class ProductPerformanceRow
{
    public function __construct(
        public string $itemKey,
        public ?string $productUuid,
        public ?string $productRef,
        public string $name,
        public ?string $categoryUuid,
        public string $categoryName,
        public string $unit,
        public float $qtySold,
        public float $grossSales,
        public float $netSales,
        public float $cogs,
        public float $grossProfit,
        public ?float $marginPct,
        public ?float $shareOfSales,
        public ?float $comparisonQty = null,
        public ?float $comparisonSales = null,
        public ?float $salesGrowth = null,
        public ?float $qtyGrowth = null,
        public float $returnsQty = 0.0,
        public float $wasteQty = 0.0,
        public float $wasteCost = 0.0,
        public ?int $groupRank = null,
        public bool $isSold = true,
    ) {}
}
