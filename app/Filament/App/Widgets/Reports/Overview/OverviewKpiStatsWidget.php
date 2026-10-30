<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Overview;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\OverviewReport;
use App\Filament\App\Widgets\Reports\BaseKpiStatsWidget;
use App\Models\User;
use App\Support\Format;
use Illuminate\Contracts\Support\Htmlable;

class OverviewKpiStatsWidget extends BaseKpiStatsWidget
{
    protected ?string $heading = null;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        $reportFilters = $this->reportFilters();
        $query = MetricsQuery::for($user, $reportFilters);
        $report = OverviewReport::for($query);
        $cards = $report->kpiCards();

        $stats = [
            $this->kpiStat(
                label: 'المبيعات',
                value: $cards['sales']['value'],
                comparison: $cards['sales']['comparison'],
                format: 'money',
                higherIsBetter: true,
                sparkline: $cards['sales']['sparkline'],
            ),
            $this->kpiStat(
                label: 'صافي الربح',
                value: $cards['net_profit']['value'],
                comparison: $cards['net_profit']['comparison'],
                format: 'money',
                higherIsBetter: true,
                sparkline: $cards['net_profit']['sparkline'],
            ),
            $this->kpiStat(
                label: 'هامش الربح',
                value: $cards['net_margin']['value'],
                comparison: $cards['net_margin']['comparison'],
                format: 'percent',
                higherIsBetter: true,
                sparkline: $cards['net_margin']['sparkline'],
            ),
            $this->kpiStat(
                label: 'عدد الطلبات',
                value: (float) $cards['orders_count']['value'],
                comparison: $cards['orders_count']['comparison'] !== null ? (float) $cards['orders_count']['comparison'] : null,
                format: 'number',
                higherIsBetter: true,
                sparkline: $cards['orders_count']['sparkline'],
            ),
            $this->kpiStat(
                label: 'متوسط قيمة الطلب',
                value: $cards['avg_ticket']['value'],
                comparison: $cards['avg_ticket']['comparison'],
                format: 'money',
                higherIsBetter: true,
                sparkline: $cards['avg_ticket']['sparkline'],
            ),
            $this->kpiStat(
                label: 'المصروفات',
                value: $cards['expenses']['value'],
                comparison: $cards['expenses']['comparison'],
                format: 'money',
                higherIsBetter: false,
                sparkline: $cards['expenses']['sparkline'],
            ),
            $this->kpiStat(
                label: 'المشتريات',
                value: $cards['net_purchases']['value'],
                comparison: $cards['net_purchases']['comparison'],
                format: 'money',
                higherIsBetter: false,
                sparkline: $cards['net_purchases']['sparkline'],
            ),
        ];

        // K12 waste + K17 wastePct
        $wasteStat = $this->kpiStat(
            label: 'الهالك',
            value: $cards['waste']['value'],
            comparison: $cards['waste']['comparison'],
            format: 'money',
            higherIsBetter: false,
            sparkline: $cards['waste']['sparkline'],
        );

        if ($cards['waste']['waste_pct'] !== null) {
            $wastePctStr = Format::percent($cards['waste']['waste_pct']);
            $currentDesc = $wasteStat->getDescription();
            $currentDescStr = $currentDesc instanceof Htmlable
                ? $currentDesc->toHtml()
                : (is_string($currentDesc) ? $currentDesc : '');

            $desc = ($currentDescStr !== '' && $currentDescStr !== '—')
                ? "{$currentDescStr} · نسبة الهالك {$wastePctStr}"
                : "نسبة الهالك {$wastePctStr}";
            $wasteStat->description($desc);
        }

        $stats[] = $wasteStat;

        return $stats;
    }
}
