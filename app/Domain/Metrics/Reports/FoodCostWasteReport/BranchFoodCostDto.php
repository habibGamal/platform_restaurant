<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\FoodCostWasteReport;

final readonly class BranchFoodCostDto
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public float $netSalesAfterReturns,
        public float $cogs,
        public ?float $foodCostPct,
        public float $wasteCost,
        public ?float $wastePct,
        public ?float $wasteShareOfUsage,
        public float $inventoryVarianceCost,
        public ?float $inventoryVariancePct,
        public float $totalLossCost,
        public ?float $totalLossPct,
        public bool $wasteRecorded,
        public string $targetBandStatus, // 'below_band' | 'in_band' | 'above_band' | 'none'
        public string $targetBandColor,  // 'success' | 'danger' | 'gray'
    ) {}
}
