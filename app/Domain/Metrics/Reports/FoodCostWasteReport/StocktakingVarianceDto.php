<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\FoodCostWasteReport;

final readonly class StocktakingVarianceDto
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public string $stocktakingUuid,
        public string $businessDate,
        public string $productUuid,
        public string $productName,
        public ?string $productRef,
        public ?string $unit,
        public float $stockQuantity,
        public float $realQuantity,
        public float $shortageQuantity,
        public float $price,
        public float $shortageValue,
    ) {}
}
