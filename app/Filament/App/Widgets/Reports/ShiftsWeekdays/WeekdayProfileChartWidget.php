<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ShiftsWeekdays;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;

class WeekdayProfileChartWidget extends BaseReportChartWidget
{
    use InteractsWithShiftsReportFilters;

    protected ?string $heading = 'مظهر أداء أيام الأسبوع (متوسط المبيعات)';

    public ?string $filter = 'actual';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, string>|null
     */
    protected function getFilters(): ?array
    {
        return [
            'actual' => 'المبيعات الفعلية (متوسط ج.م)',
            'indexed' => 'مؤشر أداء اليوم (أساس 100)',
        ];
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

        $profile = $data->weekdayProfile;
        $isIndexed = ($this->filter === 'indexed');

        $datasets = [];
        foreach ($profile->datasets as $dataset) {
            $plotData = $isIndexed ? $dataset->indexedData : $dataset->data;

            // Extra tooltip or label notes if small samples exist
            $extra = [];
            $hasSmallSample = in_array(true, $dataset->isSmallSample, true);
            $label = $dataset->label.($hasSmallSample ? ' (*عينة صغيرة)' : '');

            $datasets[] = $this->branchBarDataset(
                branchId: $dataset->branchId,
                label: $label,
                data: $plotData,
                extra: $extra,
            );
        }

        return [
            'datasets' => $datasets,
            'labels' => $profile->labels,
        ];
    }
}
