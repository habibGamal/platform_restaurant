<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\ComingSoonWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class ShiftsWeekdays extends ReportPage
{
    protected static string $routePath = 'reports/shifts';

    protected static ?string $title = 'الورديات وأيام الأسبوع';

    protected static ?string $navigationLabel = 'الورديات وأيام الأسبوع';

    protected static ?int $navigationSort = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public function getWidgets(): array
    {
        return [
            ComingSoonWidget::class,
        ];
    }
}
