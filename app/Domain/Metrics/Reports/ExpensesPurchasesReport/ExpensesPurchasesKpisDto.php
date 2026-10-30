<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ExpensesPurchasesReport;

final readonly class ExpensesPurchasesKpisDto
{
    public function __construct(
        public float $totalExpenses,
        public ?float $totalExpensesComparison,
        public ?float $totalExpensesGrowth,
        public float $laborCost,
        public ?float $laborCostComparison,
        public ?float $laborCostGrowth,
        public float $netPurchases,
        public ?float $netPurchasesComparison,
        public ?float $netPurchasesGrowth,
        public float $cogs,
        public ?float $purchasesToCogsRatio,
        public ?float $purchasesToCogsRatioComparison,
        public float $netSalesAfterReturns,
        public ?float $expensesPercentOfSales,
        public ?float $expensesPercentOfSalesComparison,
    ) {}
}
