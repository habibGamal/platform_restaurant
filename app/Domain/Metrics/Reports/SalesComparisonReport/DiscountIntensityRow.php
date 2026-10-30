<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\SalesComparisonReport;

final readonly class DiscountIntensityRow
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public float $grossSales,
        public float $discounts,
        public ?float $discountPct,
        public ?float $compGrossSales,
        public ?float $compDiscounts,
        public ?float $compDiscountPct,
        public ?float $deltaDiscountPct,
    ) {}
}
