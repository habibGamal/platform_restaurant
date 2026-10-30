<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\SalesComparison;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\SalesComparisonReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;

class SalesTrendChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'اتجاه المبيعات حسب الفرع (Sales Trend)';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        return [
            'absolute' => 'القيمة الفعلية (ج.م)',
            'indexed' => 'مؤشر نسبي (أساس 100)',
        ];
    }

    protected function getData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return ['datasets' => [], 'labels' => []];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = SalesComparisonReport::for($query);
        $data = $report->data();

        $isIndexed = ($this->filter === 'indexed');
        $trendData = $isIndexed ? $data->trendIndexed : $data->trendAbsolute;

        $datasets = [];
        foreach ($trendData->datasets as $ds) {
            $datasets[] = $this->branchLineDataset(
                branchId: $ds['branch_id'],
                label: $ds['label'],
                data: $ds['data'],
            );
        }

        return [
            'datasets' => $datasets,
            'labels' => $trendData->labels,
        ];
    }

    protected function getOptions(): array
    {
        $isIndexed = ($this->filter === 'indexed');

        return [
            'scales' => [
                'y' => [
                    'title' => [
                        'display' => true,
                        'text' => $isIndexed ? 'مؤشر المبيعات (100 = الأساس)' : 'صافي المبيعات (ج.م)',
                    ],
                ],
            ],
        ];
    }
}
