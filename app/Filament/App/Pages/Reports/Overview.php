<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\Overview\OverviewBranchTableWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewDataFreshnessWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewExpenseMixChartWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewKpiStatsWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewProfitBridgeChartWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewSalesByChannelChartWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewSalesTrendChartWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class Overview extends ReportPage
{
    protected static string $routePath = 'reports/overview';

    protected static ?string $title = 'لوحة التحكم الرئيسية';

    protected static ?string $navigationLabel = 'لوحة التحكم الرئيسية';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            OverviewKpiStatsWidget::class,
            OverviewSalesTrendChartWidget::class,
            OverviewBranchTableWidget::class,
            OverviewProfitBridgeChartWidget::class,
            OverviewExpenseMixChartWidget::class,
            OverviewSalesByChannelChartWidget::class,
            OverviewDataFreshnessWidget::class,
        ];
    }
}
