<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Overview;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\OverviewReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;

class OverviewSalesTrendChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'اتجاه المبيعات (Sales Trend)';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = 'total';

    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return [
            'total' => 'إجمالي المبيعات',
            'per_branch' => 'حسب الفرع',
        ];
    }

    protected function getType(): string
    {
        return 'line';
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

        $activeFilter = $this->filter ?? 'total';
        $trend = $report->salesTrend($activeFilter);

        if ($activeFilter === 'per_branch') {
            $datasets = [];
            foreach ($trend['datasets'] as $ds) {
                $datasets[] = $this->branchLineDataset(
                    branchId: (int) ($ds['branch_id'] ?? 0),
                    label: (string) ($ds['label'] ?? ''),
                    data: (array) ($ds['data'] ?? []),
                );
            }

            return [
                'datasets' => $datasets,
                'labels' => $trend['labels'],
            ];
        }

        $datasets = [];
        foreach ($trend['datasets'] as $ds) {
            $isCurrent = (bool) ($ds['is_current'] ?? true);
            $datasets[] = $this->lineDataset(
                label: (string) ($ds['label'] ?? ''),
                data: (array) ($ds['data'] ?? []),
                color: $isCurrent ? '#10b981' : '#94a3b8',
                extra: $isCurrent ? [] : [
                    'borderDash' => [5, 5],
                ]
            );
        }

        return [
            'datasets' => $datasets,
            'labels' => $trend['labels'],
        ];
    }
}
