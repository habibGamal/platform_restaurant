<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\SalesComparison;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\SalesComparisonReport;
use App\Filament\App\Widgets\Reports\BaseKpiStatsWidget;
use App\Models\User;

class SalesKpiOverviewWidget extends BaseKpiStatsWidget
{
    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = SalesComparisonReport::for($query);
        $overview = $report->data()->overview;

        return [
            $this->kpiStat(
                label: 'صافي المبيعات (K06)',
                value: $overview['sales']['value'] !== null ? (float) $overview['sales']['value'] : null,
                comparison: $overview['sales']['comparison'] !== null ? (float) $overview['sales']['comparison'] : null,
                format: 'money',
                higherIsBetter: true,
                sparkline: $overview['sales']['sparkline'] ?? [],
            ),
            $this->kpiStat(
                label: 'عدد الطلبات (K01)',
                value: $overview['orders']['value'] !== null ? (float) $overview['orders']['value'] : null,
                comparison: $overview['orders']['comparison'] !== null ? (float) $overview['orders']['comparison'] : null,
                format: 'number',
                higherIsBetter: true,
                sparkline: $overview['orders']['sparkline'] ?? [],
            ),
            $this->kpiStat(
                label: 'متوسط الفاتورة (K07)',
                value: $overview['avg_ticket']['value'],
                comparison: $overview['avg_ticket']['comparison'],
                format: 'money',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'الخصومات (K03)',
                value: $overview['discounts']['value'],
                comparison: $overview['discounts']['comparison'],
                format: 'money',
                higherIsBetter: false,
            ),
            $this->kpiStat(
                label: 'نسبة الإلغاء (K21)',
                value: $overview['cancel_rate']['value'],
                comparison: $overview['cancel_rate']['comparison'],
                format: 'percent',
                higherIsBetter: false,
            ),
            $this->kpiStat(
                label: 'نسبة المرتجع (K22)',
                value: $overview['return_rate']['value'],
                comparison: $overview['return_rate']['comparison'],
                format: 'percent',
                higherIsBetter: false,
            ),
        ];
    }
}
