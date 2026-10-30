<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ProfitabilityReport;

final readonly class BranchProfitabilityData
{
    /**
     * @param  list<string>  $stocktakingDates
     * @param  array<string, ProfitabilityMetricComparison>  $comparisons
     */
    public function __construct(
        public int $branchId,
        public string $branchName,
        public float $sales,
        public float $cogs,
        public float $grossProfit,
        public ?float $grossMargin,
        public float $laborCost,
        public float $rentCost,
        public float $otherExpenses,
        public float $totalExpenses,
        public float $wasteCost,
        public float $inventoryVarianceCost,
        public float $netProfit,
        public ?float $netMargin,
        public ?float $cogsPct,
        public ?float $laborPct,
        public ?float $rentPct,
        public ?float $otherExpensesPct,
        public ?float $wastePct,
        public ?float $inventoryVariancePct,
        public bool $hasNoExpenses = false,
        public array $stocktakingDates = [],
        public array $comparisons = [],
    ) {}
}
