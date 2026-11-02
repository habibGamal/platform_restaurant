<?php

declare(strict_types=1);

namespace App\Ai\Tools\Operations;

use App\Ai\Tools\Finance\ReportTool;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\DataHealthReport;

final class DataHealthTool extends ReportTool
{
    public function name(): string
    {
        return 'data_health';
    }

    public function description(): string
    {
        return 'Check selected-period completeness, reconciliation issues and profit drift, plus current branch sync health and rejected documents. Read-only.';
    }

    public function progressLabel(): string
    {
        return 'بتحقق من اكتمال البيانات ومطابقتها…';
    }

    protected function report(MetricsQuery $query): array
    {
        $report = DataHealthReport::for($query);
        $period = $query->filters()->period;

        return [
            'overview' => $report->summary($period), 'branches' => $report->branchStatusTable(),
            'reconciliation_issues' => $report->reconciliationIssues($period),
            'rejections' => $report->rejectionsLog($period), 'profit_drift' => $report->profitDriftCheck($period),
        ];
    }

    protected function summarize(array $metrics, int $branchCount): string
    {
        $health = $metrics['overview'];

        return "راجعت اكتمال {$health->completeDays} من {$health->totalDays} أيام فروع؛ {$health->openReconciliationIssues} مشاكل مطابقة مفتوحة.";
    }
}
