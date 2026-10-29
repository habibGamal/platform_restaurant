<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\TargetsVsActual\TargetsBranchTableWidget;
use App\Filament\App\Widgets\Reports\TargetsVsActual\TargetsBurnUpChartWidget;
use App\Filament\App\Widgets\Reports\TargetsVsActual\TargetsGaugeOverviewWidget;
use App\Filament\App\Widgets\Reports\TargetsVsActual\TargetsHistoryWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class TargetsVsActual extends ReportPage
{
    protected static string $routePath = 'reports/targets';

    protected static ?string $title = 'المستهدف مقابل المحقق';

    protected static ?string $navigationLabel = 'المستهدف مقابل المحقق';

    protected static ?int $navigationSort = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    public function getWidgets(): array
    {
        return [
            TargetsGaugeOverviewWidget::class,
            TargetsBranchTableWidget::class,
            TargetsBurnUpChartWidget::class,
            TargetsHistoryWidget::class,
        ];
    }
}
