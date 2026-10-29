<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\TargetsVsActual;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\TargetsVsActualReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\Branch;
use App\Models\User;

class TargetsBurnUpChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'مخطط التراكمي للمستهدف والمحقق (Burn-up Chart)';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $branchIds = $query->branchIds();
        $canSeeGroup = $query->canSeeGroup();
        $canSeeOtherBranchNames = $query->canSeeOtherBranchNames();

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get()
            ->keyBy('id');

        $options = [];
        if ($canSeeGroup && count($branchIds) > 0) {
            $options['group'] = 'إجمالي المجموعة';
        }

        foreach ($branchIds as $idx => $bId) {
            $branch = $branches->get($bId);
            $name = ($branch !== null && ($canSeeOtherBranchNames || count($branchIds) === 1))
                ? $branch->name
                : 'فرع #'.($idx + 1);

            $options['branch_'.$bId] = $name;
        }

        return $options;
    }

    protected function getData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return ['datasets' => [], 'labels' => []];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = TargetsVsActualReport::for($query);
        $burnUpMap = $report->data()->burnUpSeries;

        if (empty($burnUpMap)) {
            return ['datasets' => [], 'labels' => []];
        }

        $activeKey = $this->filter;
        if ($activeKey === null || ! isset($burnUpMap[$activeKey])) {
            $activeKey = isset($burnUpMap['group']) ? 'group' : (string) array_key_first($burnUpMap);
        }

        $series = $burnUpMap[$activeKey] ?? null;
        if ($series === null) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => [
                [
                    'label' => 'المستهدف التراكمي',
                    'data' => $series->target,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => '#3b82f6',
                    'fill' => false,
                    'tension' => 0.1,
                    'pointRadius' => 2,
                ],
                [
                    'label' => 'المحقق التراكمي',
                    'data' => $series->actual,
                    'borderColor' => '#10b981',
                    'backgroundColor' => '#10b981',
                    'fill' => false,
                    'tension' => 0.1,
                    'pointRadius' => 3,
                ],
                [
                    'label' => 'المسار المتوقع',
                    'data' => $series->projected,
                    'borderColor' => '#059669',
                    'backgroundColor' => '#059669',
                    'borderDash' => [5, 5],
                    'fill' => false,
                    'tension' => 0.1,
                    'pointRadius' => 2,
                ],
            ],
            'labels' => $series->labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'أيام الشهر',
                    ],
                ],
                'y' => [
                    'title' => [
                        'display' => true,
                        'text' => 'صافي المبيعات التراكمي (ج.م)',
                    ],
                    'beginAtZero' => true,
                ],
            ],
        ];
    }
}
