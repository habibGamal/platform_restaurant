<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\ComingSoonWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class Overview extends ReportPage
{
    protected static string $routePath = 'reports/overview';

    protected static ?string $title = 'لوحة التحكم الرئيسية';

    protected static ?string $navigationLabel = 'لوحة التحكم الرئيسية';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    public function getWidgets(): array
    {
        return [
            ComingSoonWidget::class,
        ];
    }
}
