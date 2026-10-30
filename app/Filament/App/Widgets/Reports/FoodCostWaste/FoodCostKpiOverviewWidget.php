<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\FoodCostWaste;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\FoodCostWasteReport;
use App\Filament\App\Widgets\Reports\BaseKpiStatsWidget;
use App\Models\User;

class FoodCostKpiOverviewWidget extends BaseKpiStatsWidget
{
    protected ?string $heading = 'مؤشرات تكلفة الخامات والهالك الرئيسية';

    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        $reportFilters = $this->reportFilters();
        $query = MetricsQuery::for($user, $reportFilters);
        $report = FoodCostWasteReport::for($query);
        $kpis = $report->kpiOverview();

        return [
            $this->kpiStat(
                label: 'نسبة تكلفة الخامات',
                value: $kpis->foodCostPct,
                comparison: $kpis->foodCostPctComparison,
                format: 'percent',
                higherIsBetter: false,
                sparkline: $kpis->sparklineFoodCost,
            ),
            $this->kpiStat(
                label: 'إجمالي الهالك',
                value: $kpis->wasteCost,
                comparison: $kpis->wasteCostComparison,
                format: 'money',
                higherIsBetter: false,
            ),
            $this->kpiStat(
                label: 'نسبة الهالك من المبيعات',
                value: $kpis->wastePct,
                comparison: $kpis->wastePctComparison,
                format: 'percent',
                higherIsBetter: false,
            ),
            $this->kpiStat(
                label: 'عجز الجرد المخزني',
                value: $kpis->inventoryVarianceCost,
                comparison: $kpis->inventoryVarianceCostComparison,
                format: 'money',
                higherIsBetter: false,
            ),
            $this->kpiStat(
                label: 'إجمالي نسبة الفاقد',
                value: $kpis->totalLossPct,
                comparison: $kpis->totalLossPctComparison,
                format: 'percent',
                higherIsBetter: false,
            ),
        ];
    }
}
