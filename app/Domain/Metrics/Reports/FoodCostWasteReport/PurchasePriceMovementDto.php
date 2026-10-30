<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\FoodCostWasteReport;

final readonly class PurchasePriceMovementDto
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public string $productRef,
        public string $productName,
        public ?string $unit,
        public bool $isMapped,
        public float $currentQty,
        public float $currentSpend,
        public ?float $currentAvgPrice,
        public ?float $comparisonQty,
        public ?float $comparisonSpend,
        public ?float $comparisonAvgPrice,
        public ?float $priceChangePct,
    ) {}
}
