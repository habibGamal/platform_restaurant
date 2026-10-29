<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\ComingSoonWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class ProductPerformance extends ReportPage
{
    protected static string $routePath = 'reports/products';

    protected static ?string $title = 'أداء الأصناف';

    protected static ?string $navigationLabel = 'أداء الأصناف';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    public function getWidgets(): array
    {
        return [
            ComingSoonWidget::class,
        ];
    }
}
