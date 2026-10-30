<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\FoodCostWasteReport;

final readonly class FoodCostKpiOverviewDto
{
    /**
     * @param  array<float|int>  $sparklineFoodCost
     */
    public function __construct(
        public ?float $foodCostPct,
        public ?float $foodCostPctComparison,
        public float $wasteCost,
        public ?float $wasteCostComparison,
        public ?float $wastePct,
        public ?float $wastePctComparison,
        public float $inventoryVarianceCost,
        public ?float $inventoryVarianceCostComparison,
        public ?float $inventoryVariancePct,
        public ?float $inventoryVariancePctComparison,
        public float $totalLossCost,
        public ?float $totalLossCostComparison,
        public ?float $totalLossPct,
        public ?float $totalLossPctComparison,
        public array $sparklineFoodCost = [],
    ) {}
}
