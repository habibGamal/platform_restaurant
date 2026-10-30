<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\FoodCostWaste;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\FoodCostWasteReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;

class FoodCostTrendChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'اتجاه نسبة تكلفة الخامات';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [
                'datasets' => [],
                'labels' => [],
            ];
        }

        $reportFilters = $this->reportFilters();
        $query = MetricsQuery::for($user, $reportFilters);
        $report = FoodCostWasteReport::for($query);

        $granularity = $reportFilters->granularity;
        $trend = $report->foodCostTrend($granularity);

        $datasets = [];
        foreach ($trend['datasets'] as $ds) {
            $datasets[] = $this->branchLineDataset(
                branchId: $ds['branchId'],
                label: $ds['branchName'],
                data: $ds['data'],
            );
        }

        return [
            'datasets' => $datasets,
            'labels' => $trend['labels'],
        ];
    }
}
