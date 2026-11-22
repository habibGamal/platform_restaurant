<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ProductPerformance;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProductPerformanceReport;
use App\Filament\App\Widgets\Reports\BaseReportChartWidget;
use App\Models\User;
use Filament\Support\RawJs;

class ProductParetoWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'تحليل باريتو (توزيع المبيعات التراكمي - 80/20)';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): RawJs
    {
        return $this->percentageOptions();
    }

    public function getDescription(): ?string
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = ProductPerformanceReport::for($query);
        $pareto = $report->pareto();

        if ($pareto->itemsCountTotal === 0) {
            return 'لا توجد مبيعات في الفترة المحددة';
        }

        $ratioPct = round($pareto->itemsRatio80Pct * 100, 1);

        return "{$pareto->itemsCount80Pct} من {$pareto->itemsCountTotal} صنف يحقق 80% من إجمالي المبيعات ({$ratioPct}% من إجمالي الأصناف). النسبة التراكمية تجمع حصة كل صنف مع حصص الأصناف الأعلى مبيعاً قبله؛ مثلاً 40% ثم 25% تصبح 65%.";
    }

    protected function getData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return [
                'datasets' => [],
                'labels' => [],
            ];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = ProductPerformanceReport::for($query);
        $pareto = $report->pareto();

        $labels = [];
        $shares = [];
        $cumulatives = [];

        foreach ($pareto->items as $item) {
            $labels[] = $item->name;
            $shares[] = $item->sharePct;
            $cumulatives[] = $item->cumulativePct;
        }

        return [
            'datasets' => [
                $this->barDataset(
                    label: 'حصة الصنف من المبيعات (%)',
                    data: $shares,
                    color: '#3b82f6',
                ),
                $this->lineDataset(
                    label: 'النسبة التراكمية (%)',
                    data: $cumulatives,
                    color: '#f59e0b',
                    extra: [
                        'type' => 'line',
                        'borderWidth' => 2,
                        'pointRadius' => 3,
                    ],
                ),
            ],
            'labels' => $labels,
        ];
    }
}
