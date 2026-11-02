<?php

declare(strict_types=1);

namespace App\Ai\Tools\Finance;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport;

final class ExpensesPurchasesTool extends ReportTool
{
    public function name(): string
    {
        return 'expenses_purchases';
    }

    public function description(): string
    {
        return 'Review expense and purchase KPIs, budgets, expense-versus-sales growth, suppliers and recent expenses.';
    }

    public function progressLabel(): string
    {
        return 'براجع المصروفات والمشتريات…';
    }

    protected function report(MetricsQuery $query): array
    {
        $report = ExpensesPurchasesReport::for($query);

        return [
            'overview' => $report->kpis(), 'budget_vs_actual' => $report->budgetVsActual(),
            'expenses_vs_sales_growth' => $report->expensesVsSalesGrowth(),
            'purchases_per_branch' => $report->purchasesPerBranch(), 'suppliers' => $report->supplierSummary(),
            'purchases_vs_cogs' => $report->purchasesVsCogs(), 'expenses' => $report->expenseList(10),
            'uncategorized_expense_types' => $report->uncategorizedExpenseTypesCount(),
        ];
    }

    protected function summarize(array $metrics, int $branchCount): string
    {
        return "راجعت المصروفات والمشتريات في {$branchCount} فروع؛ فيه {$metrics['uncategorized_expense_types']} أنواع مصروفات بدون تصنيف.";
    }
}
