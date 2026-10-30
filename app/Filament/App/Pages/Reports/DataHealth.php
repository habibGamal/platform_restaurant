<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\DataHealth\BranchStatusTableWidget;
use App\Filament\App\Widgets\Reports\DataHealth\CompletenessCalendarWidget;
use App\Filament\App\Widgets\Reports\DataHealth\DataHealthOverviewWidget;
use App\Filament\App\Widgets\Reports\DataHealth\IngestRejectionsTableWidget;
use App\Filament\App\Widgets\Reports\DataHealth\ProfitDriftTableWidget;
use App\Filament\App\Widgets\Reports\DataHealth\ReconciliationIssuesTableWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class DataHealth extends ReportPage
{
    protected static string $routePath = 'reports/data-health';

    protected static ?string $title = 'حالة البيانات والاتصال';

    protected static ?string $navigationLabel = 'حالة البيانات والاتصال';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            DataHealthOverviewWidget::class,
            BranchStatusTableWidget::class,
            CompletenessCalendarWidget::class,
            ReconciliationIssuesTableWidget::class,
            IngestRejectionsTableWidget::class,
            ProfitDriftTableWidget::class,
        ];
    }
}
