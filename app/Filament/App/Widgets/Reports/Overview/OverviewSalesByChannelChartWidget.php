<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Overview;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\OverviewReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;
use App\Support\BranchColor;

class OverviewSalesByChannelChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'المبيعات حسب القناة (Sales by Channel)';

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

        $channels = $report->salesByChannel();

        $labels = [];
        $data = [];
        $palette = BranchColor::palette();
        $colors = [];

        foreach ($channels as $index => $item) {
            $labels[] = $item['label'];
            $data[] = $item['sales'];
            $colors[] = $palette[$index % count($palette)];
        }

        if (empty($data)) {
            $labels = ['لا توجد مبيعات'];
            $data = [0];
            $colors = ['#94a3b8'];
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'صافي المبيعات (ج.م)',
                    'data' => $data,
                    'backgroundColor' => $colors,
                    'borderColor' => $colors,
                    'borderWidth' => 1,
                ],
            ],
        ];
    }
}
