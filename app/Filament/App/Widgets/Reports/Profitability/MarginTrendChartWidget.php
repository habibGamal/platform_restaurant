<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Profitability;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProfitabilityReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;
use Filament\Support\RawJs;

class MarginTrendChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'اتجاه الهوامش عبر الوقت (مجمل الربح مقابل صافي الربح)';

    protected static bool $isLazy = true;

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
        if (! $user) {
            return ['datasets' => [], 'labels' => []];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());

        return ProfitabilityReport::for($query)->marginTrendData();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): RawJs
    {
        return $this->percentageOptions([
            'scales' => [
                'y' => [
                    'title' => [
                        'display' => true,
                        'text' => 'الهامش (%)',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
        ]);
    }
}
