<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\SalesComparison;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\SalesComparisonReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;
use App\Support\BranchColor;

class SalesMixChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'مزيج المبيعات وطرق الدفع (Channel & Payment Mix)';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getFilters(): ?array
    {
        return [
            'channel' => 'مزيج القنوات (Channel Mix)',
            'payment' => 'مزيج طرق الدفع (Payment Mix)',
        ];
    }

    protected function getData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return ['datasets' => [], 'labels' => []];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = SalesComparisonReport::for($query);
        $mix = $report->data()->channelMix;

        $isPayment = ($this->filter === 'payment');
        $palette = BranchColor::palette();

        $datasets = [];

        if ($isPayment) {
            $methods = $mix->paymentMethods;
            foreach ($methods as $idx => $pm) {
                $color = $palette[$idx % count($palette)];
                $label = $mix->paymentLabels[$pm] ?? $pm;
                $data = $mix->paymentSeries[$pm] ?? [];

                $datasets[] = [
                    'label' => $label,
                    'data' => $data,
                    'backgroundColor' => $color,
                    'borderColor' => $color,
                    'borderWidth' => 1,
                ];
            }
        } else {
            $channels = $mix->channels;
            foreach ($channels as $idx => $ch) {
                $color = $palette[$idx % count($palette)];
                $label = $mix->channelLabels[$ch] ?? $ch;
                $data = $mix->channelSeries[$ch] ?? [];

                $datasets[] = [
                    'label' => $label,
                    'data' => $data,
                    'backgroundColor' => $color,
                    'borderColor' => $color,
                    'borderWidth' => 1,
                ];
            }
        }

        return [
            'labels' => $mix->branches,
            'datasets' => $datasets,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => [
                    'stacked' => true,
                ],
                'y' => [
                    'stacked' => true,
                    'max' => 100,
                    'title' => [
                        'display' => true,
                        'text' => 'النسبة المئوية (100%)',
                    ],
                ],
            ],
            'plugins' => [
                'tooltip' => [
                    'enabled' => true,
                ],
            ],
        ];
    }
}
