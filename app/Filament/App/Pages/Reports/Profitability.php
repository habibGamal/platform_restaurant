<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\Profitability\MarginLadderChartWidget;
use App\Filament\App\Widgets\Reports\Profitability\MarginLeakageChartWidget;
use App\Filament\App\Widgets\Reports\Profitability\MarginTrendChartWidget;
use App\Filament\App\Widgets\Reports\Profitability\ProfitabilityKpiStatsWidget;
use App\Filament\App\Widgets\Reports\Profitability\ProfitabilityPlMatrixWidget;
use App\Filament\App\Widgets\Reports\Profitability\ProfitSalesScatterChartWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class Profitability extends ReportPage
{
    protected static string $routePath = 'reports/profitability';

    protected static ?string $title = 'الأرباح والهوامش';

    protected static ?string $navigationLabel = 'الأرباح والهوامش';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public function getWidgets(): array
    {
        return [
            ProfitabilityKpiStatsWidget::class,
            ProfitabilityPlMatrixWidget::class,
            MarginLadderChartWidget::class,
            MarginLeakageChartWidget::class,
            MarginTrendChartWidget::class,
            ProfitSalesScatterChartWidget::class,
        ];
    }
}
