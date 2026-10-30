<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ProductPerformanceReport;

final readonly class ProductKpiSummary
{
    public function __construct(
        public int $totalProductsSold,
        public float $totalQtySold,
        public float $totalNetSales,
        public float $totalGrossProfit,
        public ?float $marginPct,
        public int $unsoldProductsCount,
        public ?float $comparisonNetSales = null,
        public ?float $comparisonQtySold = null,
        public ?float $salesGrowth = null,
        public ?float $qtyGrowth = null,
        /** @var array<float|int> */
        public array $salesSparkline = [],
    ) {}
}
