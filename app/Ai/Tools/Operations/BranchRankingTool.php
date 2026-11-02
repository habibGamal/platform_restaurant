<?php

declare(strict_types=1);

namespace App\Ai\Tools\Operations;

use App\Ai\Tools\Finance\ReportTool;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\BranchRankingReport;

final class BranchRankingTool extends ReportTool
{
    public function name(): string
    {
        return 'branch_ranking';
    }

    public function description(): string
    {
        return 'Rank accessible branches by weighted KPIs, showing excluded branches and scoring weights. Single-branch results are not a league comparison.';
    }

    public function progressLabel(): string
    {
        return 'بقارن ترتيب الفروع ومؤشراتها…';
    }

    protected function report(MetricsQuery $query): array
    {
        $data = BranchRankingReport::for($query)->data();

        return [
            'branches' => $data->rankedBranches, 'excluded_branches' => $data->excludedBranches,
            'weights' => $data->weights, 'single_branch_mode' => $data->singleBranchMode,
        ];
    }

    protected function summarize(array $metrics, int $branchCount): string
    {
        $excluded = count($metrics['excluded_branches']);

        return "راجعت ترتيب {$branchCount} فروع؛ {$excluded} فروع مستبعدة حسب شروط التقرير.";
    }
}
