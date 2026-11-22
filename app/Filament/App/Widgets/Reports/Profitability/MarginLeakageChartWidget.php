<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Profitability;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProfitabilityReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;
use Filament\Support\RawJs;

class MarginLeakageChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'تسرب الهامش (توزيع تكاليف كل فرع كنسبة من المبيعات)';

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

        return ProfitabilityReport::for($query)->marginLeakageData();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): RawJs
    {
        return $this->percentageOptions([
            'scales' => [
                'x' => [
                    'stacked' => true,
                ],
                'y' => [
                    'stacked' => true,
                    'max' => 100,
                    'title' => [
                        'display' => true,
                        'text' => '% من المبيعات (المجموع = 100%)',
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
