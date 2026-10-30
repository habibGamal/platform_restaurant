<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ShiftsWeekdays;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;

class PeakHoursChartWidget extends BaseReportChartWidget
{
    use InteractsWithShiftsReportFilters;

    protected ?string $heading = 'مقارنة ساعات الذروة (متوسط الطلبات بالساعة)';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array{datasets: list<array<string, mixed>>, labels: list<string>}
     */
    protected function getData(): array
    {
        $user = auth()->user();
        if (! $user) {
            return ['datasets' => [], 'labels' => []];
        }

        $filters = $this->reportFilters();
        $query = MetricsQuery::for($user, $filters);
        $excludeEvents = (bool) ($this->pageFilters['exclude_events'] ?? true);
        $data = ShiftsWeekdaysReport::for($query, $excludeEvents)->data();

        $peakHours = $data->peakHours;
        $datasets = [];

        foreach ($peakHours->datasets as $dataset) {
            $datasets[] = $this->branchLineDataset(
                branchId: (int) $dataset['branch_id'],
                label: (string) $dataset['label'],
                data: $dataset['data'],
            );
        }

        return [
            'datasets' => $datasets,
            'labels' => $peakHours->labels,
        ];
    }
}
