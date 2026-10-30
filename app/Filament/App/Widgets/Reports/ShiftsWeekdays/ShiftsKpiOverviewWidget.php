<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ShiftsWeekdays;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport;
use App\Filament\App\Widgets\Reports\BaseKpiStatsWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ShiftsKpiOverviewWidget extends BaseKpiStatsWidget
{
    use InteractsWithShiftsReportFilters;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        $filters = $this->reportFilters();
        $query = MetricsQuery::for($user, $filters);
        $excludeEvents = (bool) ($this->pageFilters['exclude_events'] ?? true);
        $data = ShiftsWeekdaysReport::for($query, $excludeEvents)->data();
        $overview = $data->overview;

        return [
            $this->kpiStat(
                label: 'عدد الورديات المغلقة',
                value: (float) $overview->totalShifts,
                comparison: $overview->totalShiftsComparison !== null ? (float) $overview->totalShiftsComparison : null,
                format: 'number',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'إجمالي المبيعات (K06)',
                value: $overview->totalSales,
                comparison: $overview->totalSalesComparison,
                format: 'money',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'متوسط مبيعات الوردية',
                value: $overview->avgSalesPerShift,
                comparison: $overview->avgSalesPerShiftComparison,
                format: 'money',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'المبيعات في الساعة',
                value: $overview->salesPerHour,
                comparison: $overview->salesPerHourComparison,
                format: 'money',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'متوسط قيمة الطلب (K07)',
                value: $overview->avgTicket,
                comparison: $overview->avgTicketComparison,
                format: 'money',
                higherIsBetter: true,
            ),
            $this->kpiStat(
                label: 'عجز النقدية (K25)',
                value: $overview->totalDeficits,
                comparison: $overview->totalDeficitsComparison,
                format: 'money',
                higherIsBetter: false,
            ),
            $this->kpiStat(
                label: 'الطلبات الملغاة (K21)',
                value: (float) $overview->cancelledOrders,
                comparison: $overview->cancelledOrdersComparison !== null ? (float) $overview->cancelledOrdersComparison : null,
                format: 'number',
                higherIsBetter: false,
            ),
        ];
    }
}
