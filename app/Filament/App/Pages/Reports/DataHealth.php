<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\ComingSoonWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class DataHealth extends ReportPage
{
    protected static string $routePath = 'reports/data-health';

    protected static ?string $title = 'حالة البيانات والاتصال';

    protected static ?string $navigationLabel = 'حالة البيانات والاتصال';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    public function getWidgets(): array
    {
        return [
            ComingSoonWidget::class,
        ];
    }
}
