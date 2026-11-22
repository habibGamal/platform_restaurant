<?php

declare(strict_types=1);

namespace App\Ai\Tools\Operations;

use App\Ai\Tools\Finance\ReportTool;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\OverviewReport;

final class OverviewTool extends ReportTool
{
    public function name(): string
    {
        return 'overview_report';
    }

    public function description(): string
    {
        return 'Get the operations overview: sales, profit, orders, expenses, purchases, branch results and the profit bridge.';
    }

    public function progressLabel(): string
    {
        return 'بجهز نظرة عامة على التشغيل…';
    }

    protected function report(MetricsQuery $query): array
    {
        $report = OverviewReport::for($query);
        $table = $report->branchTable();

        return [
            'overview' => $report->kpiCards(), 'branches' => array_values($table['branches']),
            'totals' => $table['totals'], 'profit_bridge' => $report->profitBridge(), 'expense_mix' => $report->expenseMix(),
        ];
    }

    protected function definitions(): array
    {
        return ['inventory_variance' => self::INVENTORY_VARIANCE_DEFINITION];
    }

    protected function summarize(array $metrics, int $branchCount): string
    {
        $sales = $metrics['overview']['sales']['value'];

        return "صافي المبيعات {$sales} ج.م في {$branchCount} فروع؛ جهزت مؤشرات التشغيل والربحية.";
    }
}
