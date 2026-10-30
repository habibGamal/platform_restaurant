<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ProductPerformanceReport;

final readonly class ProductDetailSummary
{
    /**
     * @param  list<ProductDetailDailyRow>  $dailyRows
     */
    public function __construct(
        public string $itemKey,
        public string $name,
        public ?string $productRef,
        public string $categoryName,
        public string $unit,
        public float $totalQtySold,
        public float $totalNetSales,
        public float $totalCogs,
        public float $totalGrossProfit,
        public ?float $marginPct,
        public float $totalReturnsQty,
        public float $totalWasteQty,
        public float $totalWasteCost,
        public array $dailyRows,
    ) {}
}
