<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\ComingSoonWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class ExpensesPurchases extends ReportPage
{
    protected static string $routePath = 'reports/expenses';

    protected static ?string $title = 'المصروفات والمشتريات';

    protected static ?string $navigationLabel = 'المصروفات والمشتريات';

    protected static ?int $navigationSort = 9;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    public function getWidgets(): array
    {
        return [
            ComingSoonWidget::class,
        ];
    }
}
