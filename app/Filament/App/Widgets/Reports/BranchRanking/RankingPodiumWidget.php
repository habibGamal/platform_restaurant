<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\BranchRanking;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\BranchRankingReport;
use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;
use App\Models\User;
use Filament\Widgets\Widget;

class RankingPodiumWidget extends Widget
{
    use InteractsWithReportFilters;

    protected static bool $isLazy = true;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.app.reports.branch-ranking.podium-widget';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [
                'top3' => [],
                'bottom3' => [],
                'singleBranchMode' => true,
                'canSeeGroup' => false,
            ];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = BranchRankingReport::for($query);
        $data = $report->data();

        return [
            'top3' => $data->top3Podium,
            'bottom3' => $data->bottom3Podium,
            'singleBranchMode' => $data->singleBranchMode,
            'canSeeGroup' => $data->canSeeGroup,
        ];
    }
}
