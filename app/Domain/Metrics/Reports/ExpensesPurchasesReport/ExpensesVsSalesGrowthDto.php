<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ExpensesPurchasesReport;

final readonly class ExpensesVsSalesGrowthDto
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public float $currentExpenses,
        public ?float $comparisonExpenses,
        public ?float $expensesGrowth,
        public float $currentSales,
        public ?float $comparisonSales,
        public ?float $salesGrowth,
        public ?float $growthDelta,
        public bool $isWarning,
    ) {}
}
