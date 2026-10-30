<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\SalesComparison;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\SalesComparisonReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;
use App\Support\BranchColor;
use App\Support\Format;

class TrafficBasketScatterWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'حركة الزبائن مقابل متوسط الفاتورة (Traffic vs Basket)';

    protected function getType(): string
    {
        return 'bubble';
    }

    public function getDescription(): ?string
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = SalesComparisonReport::for($query);
        $data = $report->data();

        if ($data->medianOrdersPerDay === null && $data->medianAvgTicket === null) {
            return null;
        }

        $parts = [];
        if ($data->medianOrdersPerDay !== null) {
            $parts[] = 'وسيط الطلبات: '.Format::number($data->medianOrdersPerDay).' طلب/يوم';
        }
        if ($data->medianAvgTicket !== null) {
            $parts[] = 'وسيط الفاتورة: '.Format::money($data->medianAvgTicket);
        }

        return implode(' | ', $parts).' (الفقاعة تمثل إجمالي المبيعات)';
    }

    protected function getData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return ['datasets' => []];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = SalesComparisonReport::for($query);
        $data = $report->data();

        $maxSales = 1.0;
        foreach ($data->scatter as $p) {
            if ($p->sales > $maxSales) {
                $maxSales = $p->sales;
            }
        }

        $datasets = [];
        foreach ($data->scatter as $point) {
            $color = BranchColor::for($point->branchId);
            $normalizedRadius = max(8, min(28, (int) round(($point->sales / $maxSales) * 24)));

            $datasets[] = [
                'label' => $point->branchName,
                'data' => [
                    [
                        'x' => $point->ordersPerDay,
                        'y' => $point->avgTicket,
                        'r' => $normalizedRadius,
                    ],
                ],
                'backgroundColor' => $color.'aa',
                'borderColor' => $color,
                'borderWidth' => 2,
            ];
        }

        return [
            'datasets' => $datasets,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'عدد الطلبات اليومي (طلب/يوم)',
                    ],
                ],
                'y' => [
                    'title' => [
                        'display' => true,
                        'text' => 'متوسط قيمة الطلب (ج.م)',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                ],
            ],
        ];
    }
}
