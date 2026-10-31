<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Filament\App\Widgets\Reports\SalesComparison\DiscountIntensityWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\GrowthDecompositionTableWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\SalesKpiMatrixWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\SalesKpiOverviewWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\SalesMixChartWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\SalesTrendChartWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\TrafficBasketScatterWidget;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SalesComparison extends ReportPage
{
    protected static string $routePath = 'reports/sales';

    protected static ?string $title = 'مقارنة المبيعات';

    protected static ?string $navigationLabel = 'مقارنة المبيعات';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    /**
     * @return array<string, mixed>
     */
    public function getDefaultFilters(): array
    {
        return array_merge(parent::getDefaultFilters(), [
            'channel' => null,
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        $schema = parent::filtersForm($schema);
        $components = $schema->getComponents();

        $components[] = Select::make('channel')
            ->label('قناة البيع')
            ->options([
                'dine_in' => 'صالة (Dine-in)',
                'takeaway' => 'سفري (Takeaway)',
                'delivery' => 'توصيل (Delivery)',
            ])
            ->placeholder('كل القنوات')
            ->nullable();

        return $schema->components($components);
    }

    public function getWidgets(): array
    {
        return [
            SalesKpiOverviewWidget::class,
            SalesKpiMatrixWidget::class,
            TrafficBasketScatterWidget::class,
            SalesTrendChartWidget::class,
            GrowthDecompositionTableWidget::class,
            SalesMixChartWidget::class,
            DiscountIntensityWidget::class,
        ];
    }
}
