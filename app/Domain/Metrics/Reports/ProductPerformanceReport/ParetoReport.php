<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ProductPerformanceReport;

final readonly class ParetoReport
{
    /**
     * @param  list<ParetoItem>  $items
     */
    public function __construct(
        public array $items,
        public float $totalSales,
        public int $itemsCount80Pct,
        public int $itemsCountTotal,
        public float $itemsRatio80Pct,
    ) {}
}
