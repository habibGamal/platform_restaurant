<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\ComingSoonWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class SalesComparison extends ReportPage
{
    protected static string $routePath = 'reports/sales';

    protected static ?string $title = 'مقارنة المبيعات';

    protected static ?string $navigationLabel = 'مقارنة المبيعات';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    public function getWidgets(): array
    {
        return [
            ComingSoonWidget::class,
        ];
    }
}
