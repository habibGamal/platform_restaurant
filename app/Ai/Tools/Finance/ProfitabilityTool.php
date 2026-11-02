<?php

declare(strict_types=1);

namespace App\Ai\Tools\Finance;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProfitabilityReport;

final class ProfitabilityTool extends ReportTool
{
    public function name(): string
    {
        return 'profitability';
    }

    public function description(): string
    {
        return 'Review net profit, margins, cost components and missing-expense warnings using the profitability report.';
    }

    public function progressLabel(): string
    {
        return 'براجع الأرباح وهوامش التكلفة…';
    }

    protected function report(MetricsQuery $query): array
    {
        $report = ProfitabilityReport::for($query);
        $data = $report->data();

        return [
            'overview' => $report->kpiSummary(), 'branches' => array_values($data->branches),
            'group' => $data->group, 'stocktaking_notes' => $data->stocktakingNotes,
            'missing_expense_branches' => $data->missingExpenseBranches,
        ];
    }

    protected function summarize(array $metrics, int $branchCount): string
    {
        $profit = $metrics['overview']['net_profit'];

        return "صافي الربح {$profit} ج.م في نطاق {$branchCount} فروع؛ راجعت مكونات التكلفة والمصروفات.";
    }
}
