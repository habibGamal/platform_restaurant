<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Domain\Metrics\Period\PeriodPreset;
use App\Filament\App\Widgets\Reports\BranchRanking\PerKpiLeadersWidget;
use App\Filament\App\Widgets\Reports\BranchRanking\RankHistoryChartWidget;
use App\Filament\App\Widgets\Reports\BranchRanking\RankingLeagueTableWidget;
use App\Filament\App\Widgets\Reports\BranchRanking\RankingPodiumWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class BranchRanking extends ReportPage
{
    protected static string $routePath = 'reports/ranking';

    protected static ?string $title = 'ترتيب الفروع';

    protected static ?string $navigationLabel = 'ترتيب الفروع';

    protected static ?int $navigationSort = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    /**
     * @return array<string, mixed>
     */
    public function getDefaultFilters(): array
    {
        return array_merge(parent::getDefaultFilters(), [
            'preset' => PeriodPreset::last_month->value,
            'lfl' => true,
        ]);
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            RankingPodiumWidget::class,
            RankingLeagueTableWidget::class,
            PerKpiLeadersWidget::class,
            RankHistoryChartWidget::class,
        ];
    }
}
