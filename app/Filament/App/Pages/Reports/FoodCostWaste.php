<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\FoodCostWaste\FoodCostBranchMatrixWidget;
use App\Filament\App\Widgets\Reports\FoodCostWaste\FoodCostKpiOverviewWidget;
use App\Filament\App\Widgets\Reports\FoodCostWaste\FoodCostTrendChartWidget;
use App\Filament\App\Widgets\Reports\FoodCostWaste\PurchasePriceMovementTableWidget;
use App\Filament\App\Widgets\Reports\FoodCostWaste\StocktakingVarianceTableWidget;
use App\Filament\App\Widgets\Reports\FoodCostWaste\TopWastedItemsTableWidget;
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
            FoodCostKpiOverviewWidget::class,
            FoodCostBranchMatrixWidget::class,
            FoodCostTrendChartWidget::class,
            TopWastedItemsTableWidget::class,
            StocktakingVarianceTableWidget::class,
            PurchasePriceMovementTableWidget::class,
        ];
    }
}
