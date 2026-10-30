<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ProductPerformanceReport;

final readonly class ParetoItem
{
    public function __construct(
        public int $rank,
        public string $itemKey,
        public string $name,
        public ?string $productRef,
        public float $sales,
        public float $sharePct,
        public float $cumulativeSales,
        public float $cumulativePct,
        public bool $isIn80Pct,
    ) {}
}
