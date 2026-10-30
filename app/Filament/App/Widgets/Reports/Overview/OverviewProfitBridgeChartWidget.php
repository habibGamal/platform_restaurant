<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Overview;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\OverviewReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;

class OverviewProfitBridgeChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'جسر الأرباح (Profit Bridge)';

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return ['datasets' => [], 'labels' => []];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = OverviewReport::for($query);

        $bridge = $report->profitBridge();

        $labels = [
            'المبيعات',
            'تكلفة المبيعات (-)',
            'مجمل الربح',
            'المصروفات (-)',
            'الهالك (-)',
            'عجز الجرد (-)',
            'صافي الربح',
        ];

        $data = [
            $bridge['sales'],
            -$bridge['cogs'],
            $bridge['gross_profit'],
            -$bridge['expenses'],
            -$bridge['waste'],
            -$bridge['inventory_variance'],
            $bridge['net_profit'],
        ];

        $colors = [
            '#10b981', // sales (green)
            '#ef4444', // cogs (red)
            '#3b82f6', // gross profit (blue)
            '#ef4444', // expenses (red)
            '#ef4444', // waste (red)
            '#ef4444', // inventory variance (red)
            $bridge['net_profit'] >= 0 ? '#10b981' : '#ef4444', // net profit (green / red)
        ];

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'القيمة (ج.م)',
                    'data' => $data,
                    'backgroundColor' => $colors,
                    'borderColor' => $colors,
                    'borderWidth' => 1,
                ],
            ],
        ];
    }
}
