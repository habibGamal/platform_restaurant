<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Domain\Metrics\Period\PeriodPreset;
use App\Filament\App\Widgets\Reports\ProductPerformance\ProductCategoryPerformanceWidget;
use App\Filament\App\Widgets\Reports\ProductPerformance\ProductCrossBranchMatrixWidget;
use App\Filament\App\Widgets\Reports\ProductPerformance\ProductParetoWidget;
use App\Filament\App\Widgets\Reports\ProductPerformance\ProductPerformanceKpiWidget;
use App\Filament\App\Widgets\Reports\ProductPerformance\ProductRankingTableWidget;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ProductPerformance extends ReportPage
{
    protected static string $routePath = 'reports/products';

    protected static ?string $title = 'أداء الأصناف';

    protected static ?string $navigationLabel = 'أداء الأصناف';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    public function filtersForm(Schema $schema): Schema
    {
        $schema = parent::filtersForm($schema);
        $components = $schema->getComponents();

        foreach ($components as $component) {
            if ($component instanceof Select && $component->getName() === 'preset') {
                $component->default(PeriodPreset::last_30_days->value);
            }
        }

        return $schema->components($components);
    }

    public function getWidgets(): array
    {
        return [
            ProductPerformanceKpiWidget::class,
            ProductRankingTableWidget::class,
            ProductCrossBranchMatrixWidget::class,
            ProductCategoryPerformanceWidget::class,
            ProductParetoWidget::class,
        ];
    }
}
