<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Profitability;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProfitabilityReport;
use App\Filament\App\Widgets\Reports\BaseKpiStatsWidget;
use App\Models\User;

class ProfitabilityKpiStatsWidget extends BaseKpiStatsWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $summary = ProfitabilityReport::for($query)->kpiSummary();

        return [
            $this->kpiStat(
                label: 'صافي الربح',
                value: (float) $summary['net_profit'],
                comparison: $summary['net_profit_comparison'] !== null ? (float) $summary['net_profit_comparison'] : null,
                format: 'money',
                higherIsBetter: true,
                sparkline: (array) $summary['sparkline_profit'],
            ),
            $this->kpiStat(
                label: 'هامش صافي الربح',
                value: $summary['net_margin'] !== null ? (float) $summary['net_margin'] : null,
                comparison: $summary['net_margin_comparison'] !== null ? (float) $summary['net_margin_comparison'] : null,
                format: 'percent',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'مجمل الربح',
                value: (float) $summary['gross_profit'],
                comparison: $summary['gross_profit_comparison'] !== null ? (float) $summary['gross_profit_comparison'] : null,
                format: 'money',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'هامش مجمل الربح',
                value: $summary['gross_margin'] !== null ? (float) $summary['gross_margin'] : null,
                comparison: $summary['gross_margin_comparison'] !== null ? (float) $summary['gross_margin_comparison'] : null,
                format: 'percent',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'نسبة تكلفة الخامات',
                value: $summary['food_cost_pct'] !== null ? (float) $summary['food_cost_pct'] : null,
                comparison: $summary['food_cost_pct_comparison'] !== null ? (float) $summary['food_cost_pct_comparison'] : null,
                format: 'percent',
                higherIsBetter: false,
            ),
            $this->kpiStat(
                label: 'نسبة العمالة',
                value: $summary['labor_pct'] !== null ? (float) $summary['labor_pct'] : null,
                comparison: $summary['labor_pct_comparison'] !== null ? (float) $summary['labor_pct_comparison'] : null,
                format: 'percent',
                higherIsBetter: false,
            ),
        ];
    }
}
