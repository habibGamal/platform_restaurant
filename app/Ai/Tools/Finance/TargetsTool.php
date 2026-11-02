<?php

declare(strict_types=1);

namespace App\Ai\Tools\Finance;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\TargetsVsActualReport;

final class TargetsTool extends ReportTool
{
    public function name(): string
    {
        return 'targets_vs_actual';
    }

    public function description(): string
    {
        return 'Review sales, profit, orders and food-cost targets against actual results, including branch gaps and required pace.';
    }

    public function progressLabel(): string
    {
        return 'بقارن المستهدف بالمحقق…';
    }

    protected function report(MetricsQuery $query): array
    {
        $data = TargetsVsActualReport::for($query)->data();

        return ['overview' => $data->overview, 'branches' => $data->branchRows];
    }

    protected function summarize(array $metrics, int $branchCount): string
    {
        return "راجعت فجوة المستهدف والمحقق في {$branchCount} فروع؛ القيم الفارغة معناها مفيش مستهدف متسجل.";
    }
}
