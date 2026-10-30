<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ExpensesPurchasesReport;

final readonly class ExpensesPurchasesReportData
{
    /**
     * @param  list<BudgetVsActualDto>  $budgetVsActual
     * @param  list<ExpensesVsSalesGrowthDto>  $expensesVsSalesGrowth
     * @param  list<PurchasesPerBranchDto>  $purchasesPerBranch
     * @param  list<SupplierSummaryDto>  $supplierSummary
     * @param  list<PurchasesVsCogsDto>  $purchasesVsCogs
     * @param  list<ExpenseListItemDto>  $expenseList
     */
    public function __construct(
        public ExpensesPurchasesKpisDto $kpis,
        public CategoryBranchMatrixDto $categoryMatrix,
        public array $budgetVsActual,
        public array $expensesVsSalesGrowth,
        public array $purchasesPerBranch,
        public array $supplierSummary,
        public array $purchasesVsCogs,
        public array $expenseList,
        public int $uncategorizedExpenseTypesCount,
    ) {}
}
