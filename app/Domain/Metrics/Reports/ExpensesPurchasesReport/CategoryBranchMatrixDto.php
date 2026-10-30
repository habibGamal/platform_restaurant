<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ExpensesPurchasesReport;

final readonly class CategoryBranchMatrixDto
{
    /**
     * @param  list<array{id: int, name: string, sales: float}>  $branches
     * @param  list<string>  $categories
     * @param  array<string, string>  $categoryLabels
     * @param  array<string, array<int, array{amount: float, percentOfSales: ?float}>>  $cells
     * @param  array<int, array{amount: float, percentOfSales: ?float}>  $branchTotals
     * @param  array<string, array{amount: float, percentOfSales: ?float}>  $categoryTotals
     * @param  array{amount: float, percentOfSales: ?float}|null  $grandTotal
     */
    public function __construct(
        public array $branches,
        public array $categories,
        public array $categoryLabels,
        public array $cells,
        public array $branchTotals,
        public array $categoryTotals,
        public ?array $grandTotal,
    ) {}
}
