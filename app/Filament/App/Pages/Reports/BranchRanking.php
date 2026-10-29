<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\ComingSoonWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class BranchRanking extends ReportPage
{
    protected static string $routePath = 'reports/ranking';

    protected static ?string $title = 'ترتيب الفروع';

    protected static ?string $navigationLabel = 'ترتيب الفروع';

    protected static ?int $navigationSort = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    public function getWidgets(): array
    {
        return [
            ComingSoonWidget::class,
        ];
    }
}
