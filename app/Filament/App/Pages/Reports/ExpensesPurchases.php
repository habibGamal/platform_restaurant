<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Domain\Metrics\Period\PeriodPreset;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\BudgetVsActualWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\CategoryBranchMatrixWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\ExpenseListTableWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\ExpensesPurchasesKpiStatsWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\ExpensesPurchasesNoticeWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\ExpensesVsSalesGrowthWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\PurchasesPerBranchWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\PurchasesVsCogsWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\SupplierSummaryTableWidget;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ExpensesPurchases extends ReportPage
{
    protected static string $routePath = 'reports/expenses';

    protected static ?string $title = 'المصروفات والمشتريات';

    protected static ?string $navigationLabel = 'المصروفات والمشتريات';

    protected static ?int $navigationSort = 9;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    /**
     * @return array<string, mixed>
     */
    public function getDefaultFilters(): array
    {
        return array_merge(parent::getDefaultFilters(), [
            'preset' => PeriodPreset::this_month->value,
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        $schema = parent::filtersForm($schema);
        $components = $schema->getComponents();

        foreach ($components as $component) {
            if ($component instanceof Select && $component->getName() === 'preset') {
                $component->default(PeriodPreset::this_month->value);
            }
        }

        return $schema;
    }

    public function getWidgets(): array
    {
        return [
            ExpensesPurchasesNoticeWidget::class,
            ExpensesPurchasesKpiStatsWidget::class,
            CategoryBranchMatrixWidget::class,
            BudgetVsActualWidget::class,
            ExpensesVsSalesGrowthWidget::class,
            PurchasesPerBranchWidget::class,
            SupplierSummaryTableWidget::class,
            PurchasesVsCogsWidget::class,
            ExpenseListTableWidget::class,
        ];
    }
}
