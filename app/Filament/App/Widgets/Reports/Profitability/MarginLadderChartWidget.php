<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Profitability;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProfitabilityReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;

class MarginLadderChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'سلم الهوامش (ترتيب الفروع حسب هامش صافي الربح)';

    protected static bool $isLazy = true;

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return ['datasets' => [], 'labels' => []];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $ladder = ProfitabilityReport::for($query)->marginLadderData();

        $datasets = [
            [
                'label' => 'هامش صافي الربح (%)',
                'data' => $ladder['data'],
                'backgroundColor' => $ladder['colors'],
                'borderColor' => $ladder['colors'],
                'borderWidth' => 1,
            ],
        ];

        if ($ladder['groupMargin'] !== null && ! empty($ladder['data'])) {
            $datasets[] = [
                'label' => "متوسط المجموعة ({$ladder['groupMargin']}%)",
                'data' => array_fill(0, count($ladder['data']), $ladder['groupMargin']),
                'type' => 'line',
                'borderColor' => '#6b7280',
                'borderDash' => [5, 5],
                'pointRadius' => 0,
                'fill' => false,
            ];
        }

        return [
            'labels' => $ladder['labels'],
            'datasets' => $datasets,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'scales' => [
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'هامش صافي الربح (%)',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
            ],
        ];
    }
}
