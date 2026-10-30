<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ExpensesPurchasesReport;

final readonly class BudgetVsActualDto
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public string $expenseTypeUuid,
        public string $expenseTypeName,
        public string $category,
        public string $categoryLabel,
        public float $monthlyBudget,
        public float $proratedBudget,
        public float $actualSpent,
        public float $variance,
        public ?float $consumptionPct,
        public bool $isOverBudget,
    ) {}
}
