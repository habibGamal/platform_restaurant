<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\BranchRanking;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\BranchRankingReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;

class RankHistoryChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'تاريخ الترتيب عبر الفترات السابقة';

    protected static bool $isLazy = true;

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
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

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = BranchRankingReport::for($query);
        $rankHistory = $report->data()->rankHistory;

        return [
            'datasets' => $rankHistory->datasets,
            'labels' => $rankHistory->labels,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'reverse' => true,
                    'ticks' => [
                        'stepSize' => 1,
                        'precision' => 0,
                    ],
                    'title' => [
                        'display' => true,
                        'text' => 'الترتيب (المركز الأول في الأعلى)',
                    ],
                ],
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'الفترات الزمنية',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
        ];
    }
}
