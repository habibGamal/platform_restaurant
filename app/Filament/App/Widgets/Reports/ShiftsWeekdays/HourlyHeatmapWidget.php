<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ShiftsWeekdays;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport\HourlyHeatmapData;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Support\Weekday;

class HourlyHeatmapWidget extends BaseReportViewWidget
{
    use InteractsWithShiftsReportFilters;

    protected string $view = 'filament.app.reports.shifts-weekdays.hourly-heatmap';

    protected ?string $heading = 'خريطة كثافة العمل (الساعات × أيام الأسبوع)';

    public ?int $selectedBranchId = null;

    public string $metric = 'orders'; // 'orders' | 'sales'

    public string $mode = 'standard'; // 'standard' (0-23) | 'extended' (24+)

    public function setBranch(int|string $branchId): void
    {
        $this->selectedBranchId = (int) $branchId;
    }

    public function setMetric(string $metric): void
    {
        if (in_array($metric, ['orders', 'sales'], true)) {
            $this->metric = $metric;
        }
    }

    public function setMode(string $mode): void
    {
        if (in_array($mode, ['standard', 'extended'], true)) {
            $this->mode = $mode;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [
                'heading' => $this->getHeading(),
                'availableBranches' => [],
                'selectedBranchId' => null,
                'currentBranchName' => '',
                'metric' => $this->metric,
                'mode' => $this->mode,
                'hasExtendedHours' => false,
                'heatmapData' => null,
                'displayHours' => [],
                'weekdays' => range(0, 6),
                'weekdayLabels' => Weekday::LABELS,
            ];
        }

        $filters = $this->reportFilters();
        $query = MetricsQuery::for($user, $filters);
        $excludeEvents = (bool) ($this->pageFilters['exclude_events'] ?? true);
        $data = ShiftsWeekdaysReport::for($query, $excludeEvents)->data();

        $heatmaps = $data->hourlyHeatmaps;
        $availableBranches = [];
        foreach ($heatmaps as $bId => $hData) {
            $availableBranches[$bId] = $hData->branchName;
        }

        if ($this->selectedBranchId === null || ! isset($heatmaps[$this->selectedBranchId])) {
            $firstId = ! empty($heatmaps) ? (int) array_key_first($heatmaps) : null;
            $this->selectedBranchId = $firstId;
        }

        /** @var HourlyHeatmapData|null $currentHeatmap */
        $currentHeatmap = $this->selectedBranchId !== null ? ($heatmaps[$this->selectedBranchId] ?? null) : null;

        $hasExtendedHours = false;
        $displayHours = [];

        if ($currentHeatmap !== null) {
            foreach ($currentHeatmap->hours as $h) {
                if ($h >= 24) {
                    $hasExtendedHours = true;
                }
                if ($this->mode === 'extended' || $h < 24) {
                    $displayHours[] = $h;
                }
            }
        }

        return [
            'heading' => $this->getHeading(),
            'availableBranches' => $availableBranches,
            'selectedBranchId' => $this->selectedBranchId,
            'currentBranchName' => $currentHeatmap !== null ? $currentHeatmap->branchName : '',
            'metric' => $this->metric,
            'mode' => $this->mode,
            'hasExtendedHours' => $hasExtendedHours,
            'heatmapData' => $currentHeatmap,
            'displayHours' => $displayHours,
            'weekdays' => range(0, 6),
            'weekdayLabels' => Weekday::LABELS,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function getColumns(): array
    {
        return [
            'hour' => 'الساعة',
            ...collect(Weekday::LABELS)->mapWithKeys(fn ($label, $idx) => ['day_'.$idx => $label])->all(),
        ];
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    protected function getRows(): array
    {
        $viewData = $this->getViewData();
        /** @var HourlyHeatmapData|null $heatmap */
        $heatmap = $viewData['heatmapData'];
        if (! $heatmap) {
            return [];
        }

        $rows = [];
        foreach ($viewData['displayHours'] as $h) {
            $row = [
                'hour' => sprintf('%02d:00', $h),
            ];
            foreach ($viewData['weekdays'] as $w) {
                $val = $this->metric === 'orders'
                    ? ($heatmap->matrixOrders[$h][$w] ?? 0.0)
                    : ($heatmap->matrixSales[$h][$w] ?? 0.0);
                $row['day_'.$w] = $val > 0.0 ? (string) $val : '—';
            }
            $rows[$h] = $row;
        }

        return $rows;
    }
}
