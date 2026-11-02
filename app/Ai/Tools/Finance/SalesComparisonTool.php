<?php

declare(strict_types=1);

namespace App\Ai\Tools\Finance;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\SalesComparisonReport;

final class SalesComparisonTool extends ReportTool
{
    public function name(): string
    {
        return 'sales_comparison';
    }

    public function description(): string
    {
        return 'Compare sales, orders, average ticket, growth drivers and discount intensity between the requested periods.';
    }

    public function progressLabel(): string
    {
        return 'بقارن المبيعات وأسباب التغير…';
    }

    protected function report(MetricsQuery $query): array
    {
        $data = SalesComparisonReport::for($query)->data();

        return [
            'overview' => $data->overview, 'branches' => $data->matrix,
            'growth_decomposition' => $data->growthDecomposition, 'discount_intensity' => $data->discountIntensity,
        ];
    }

    protected function summarize(array $metrics, int $branchCount): string
    {
        return "قارنت مؤشرات المبيعات وأسباب التغير في {$branchCount} فروع للفترة المحددة.";
    }
}
