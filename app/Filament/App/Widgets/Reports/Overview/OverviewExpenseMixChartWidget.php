<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Overview;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\OverviewReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;
use App\Support\BranchColor;

class OverviewExpenseMixChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'توزيع المصروفات (Expense Mix)';

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    protected function getType(): string
    {
        return 'doughnut';
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

        $mix = $report->expenseMix();

        $labels = [];
        $data = [];
        $palette = BranchColor::palette();
        $colors = [];

        foreach ($mix as $index => $item) {
            $labels[] = $item['label'];
            $data[] = $item['amount'];
            $colors[] = $palette[$index % count($palette)];
        }

        if (empty($data)) {
            $labels = ['لا توجد مصروفات'];
            $data = [0];
            $colors = ['#94a3b8'];
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'المصروفات',
                    'data' => $data,
                    'backgroundColor' => $colors,
                    'borderColor' => '#ffffff',
                    'borderWidth' => 2,
                ],
            ],
        ];
    }
}
