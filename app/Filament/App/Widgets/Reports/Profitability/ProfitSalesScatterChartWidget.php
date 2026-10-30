<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Profitability;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProfitabilityReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;

class ProfitSalesScatterChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'مصفوفة الربح مقابل المبيعات (حجم المبيعات مقابل هامش صافي الربح)';

    protected static bool $isLazy = true;

    protected function getType(): string
    {
        return 'scatter';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return ['datasets' => []];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());

        return ProfitabilityReport::for($query)->profitSalesScatterData();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'المبيعات (ج.م)',
                    ],
                ],
                'y' => [
                    'title' => [
                        'display' => true,
                        'text' => 'هامش صافي الربح (%)',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
        ];
    }
}
