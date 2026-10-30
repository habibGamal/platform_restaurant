<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\FoodCostWasteReport;

final readonly class TopWastedItemDto
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public string $productUuid,
        public string $productName,
        public ?string $productRef,
        public ?string $unit,
        public float $wasteQty,
        public float $wasteCost,
        public ?float $wasteShareOfBranch,
    ) {}
}
