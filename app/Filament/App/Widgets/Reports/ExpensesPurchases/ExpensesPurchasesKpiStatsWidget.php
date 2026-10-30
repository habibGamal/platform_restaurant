<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ExpensesPurchases;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport;
use App\Filament\App\Widgets\Reports\BaseKpiStatsWidget;
use App\Models\User;

class ExpensesPurchasesKpiStatsWidget extends BaseKpiStatsWidget
{
    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        $reportFilters = $this->reportFilters();
        $query = MetricsQuery::for($user, $reportFilters);
        $report = ExpensesPurchasesReport::for($query);
        $kpis = $report->kpis();

        return [
            $this->kpiStat(
                label: 'إجمالي المصروفات',
                value: $kpis->totalExpenses,
                comparison: $kpis->totalExpensesComparison,
                format: 'money',
                higherIsBetter: false,
            ),
            $this->kpiStat(
                label: 'تكلفة العمالة',
                value: $kpis->laborCost,
                comparison: $kpis->laborCostComparison,
                format: 'money',
                higherIsBetter: false,
            ),
            $this->kpiStat(
                label: 'صافي المشتريات',
                value: $kpis->netPurchases,
                comparison: $kpis->netPurchasesComparison,
                format: 'money',
                higherIsBetter: false,
            ),
            $this->kpiStat(
                label: 'نسبة المشتريات إلى COGS',
                value: $kpis->purchasesToCogsRatio,
                comparison: $kpis->purchasesToCogsRatioComparison,
                format: 'percent',
                higherIsBetter: false,
            ),
            $this->kpiStat(
                label: 'المصروفات كنسبة من المبيعات',
                value: $kpis->expensesPercentOfSales,
                comparison: $kpis->expensesPercentOfSalesComparison,
                format: 'percent',
                higherIsBetter: false,
            ),
        ];
    }
}
