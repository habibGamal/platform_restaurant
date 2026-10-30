<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ProductPerformanceReport;

final readonly class CategoryPerformanceRow
{
    public function __construct(
        public ?string $categoryUuid,
        public string $name,
        public int $productsCount,
        public float $qtySold,
        public float $netSales,
        public float $cogs,
        public float $grossProfit,
        public ?float $marginPct,
        public ?float $shareOfSales,
        public ?float $comparisonSales = null,
        public ?float $growth = null,
    ) {}
}
