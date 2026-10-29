<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\ComingSoonWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class FoodCostWaste extends ReportPage
{
    protected static string $routePath = 'reports/food-cost';

    protected static ?string $title = 'تكلفة الخامات والهالك';

    protected static ?string $navigationLabel = 'تكلفة الخامات والهالك';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    public function getWidgets(): array
    {
        return [
            ComingSoonWidget::class,
        ];
    }
}
