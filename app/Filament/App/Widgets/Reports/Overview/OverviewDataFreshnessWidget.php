<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Overview;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\OverviewReport;
use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;
use App\Models\User;
use Filament\Widgets\Widget;

class OverviewDataFreshnessWidget extends Widget
{
    use InteractsWithReportFilters;

    protected static bool $isLazy = true;

    protected string $view = 'filament.app.reports.overview.data-freshness-widget';

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return [
                'summary' => ['complete' => 0, 'total' => 0],
                'branches' => [],
            ];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = OverviewReport::for($query);

        return $report->dataFreshness();
    }
}
