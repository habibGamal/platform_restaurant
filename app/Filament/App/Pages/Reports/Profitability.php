<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\ComingSoonWidget;
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
            ComingSoonWidget::class,
        ];
    }
}
