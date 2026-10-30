<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ProductPerformance;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProductPerformanceReport;
use App\Filament\App\Widgets\Reports\BaseKpiStatsWidget;
use App\Models\User;

class ProductPerformanceKpiWidget extends BaseKpiStatsWidget
{
    protected ?string $heading = 'مؤشرات أداء الأصناف الرئيسية';

    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        $reportFilters = $this->reportFilters();
        $query = MetricsQuery::for($user, $reportFilters);
        $report = ProductPerformanceReport::for($query);
        $kpis = $report->kpiSummary();

        return [
            $this->kpiStat(
                label: 'الأصناف المباعة',
                value: (float) $kpis->totalProductsSold,
                comparison: null,
                format: 'number',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'إجمالي الكمية المباعة',
                value: $kpis->totalQtySold,
                comparison: $kpis->comparisonQtySold,
                format: 'number',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'صافي مبيعات الأصناف',
                value: $kpis->totalNetSales,
                comparison: $kpis->comparisonNetSales,
                format: 'money',
                higherIsBetter: true,
                sparkline: $kpis->salesSparkline,
            ),
            $this->kpiStat(
                label: 'مجمل الربح',
                value: $kpis->totalGrossProfit,
                comparison: null,
                format: 'money',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'هامش مجمل الربح',
                value: $kpis->marginPct,
                comparison: null,
                format: 'percent',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'أصناف لم تُبَع (نشطة)',
                value: (float) $kpis->unsoldProductsCount,
                comparison: null,
                format: 'number',
                higherIsBetter: false,
            ),
        ];
    }
}
