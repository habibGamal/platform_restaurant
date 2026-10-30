<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\BranchRanking;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\BranchRankingReport;
use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;
use App\Models\User;
use Filament\Widgets\Widget;

class PerKpiLeadersWidget extends Widget
{
    use InteractsWithReportFilters;

    protected static bool $isLazy = true;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.app.reports.branch-ranking.leaders-widget';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [
                'leaders' => [],
                'singleBranchMode' => true,
                'singleBranchRow' => null,
            ];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = BranchRankingReport::for($query);
        $data = $report->data();

        $singleBranchRow = (! empty($data->rankedBranches)) ? $data->rankedBranches[0] : null;

        return [
            'leaders' => $data->kpiLeaders,
            'singleBranchMode' => $data->singleBranchMode,
            'singleBranchRow' => $singleBranchRow,
        ];
    }
}
