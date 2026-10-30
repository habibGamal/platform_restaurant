<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\FoodCostWaste;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\FoodCostWasteReport;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Models\User;
use App\Support\Format;
use Closure;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PurchasePriceMovementTableWidget extends BaseReportTableWidget
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('حركة أسعار الشراء')
            ->description('مقارنة متوسط أسعار شراء الخامات بين الفترة الحالية وفترة المقارنة لأعلى الأصناف إنفاقاً')
            ->paginated([10, 20])
            ->defaultPaginationPageOption(20);
    }

    protected function getReportRecords(): ?Closure
    {
        return function (): array {
            /** @var User|null $user */
            $user = auth()->user();
            if ($user === null) {
                return [];
            }

            $reportFilters = $this->reportFilters();
            $query = MetricsQuery::for($user, $reportFilters);
            $report = FoodCostWasteReport::for($query);

            $items = $report->purchasePriceMovement(limit: 20);

            $records = [];
            foreach ($items as $idx => $item) {
                $records[$idx + 1] = [
                    'id' => $idx + 1,
                    'product_ref' => $item->productRef,
                    'product_name' => $item->productName,
                    'branch_name' => $item->branchName,
                    'unit' => $item->unit ?? 'قطعة',
                    'current_spend' => Format::money($item->currentSpend, 2),
                    'current_avg_price' => Format::money($item->currentAvgPrice, 2),
                    'comparison_avg_price' => $item->comparisonAvgPrice !== null
                        ? Format::money($item->comparisonAvgPrice, 2)
                        : '—',
                    'price_change' => Format::growth($item->priceChangePct),
                    'is_mapped' => $item->isMapped ? 'موحّد' : 'غير موحّد',
                    'mapping_color' => $item->isMapped ? 'success' : 'warning',
                ];
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('product_name')
                ->label('المادة الخام / الصنف')
                ->weight('bold'),
            TextColumn::make('product_ref')
                ->label('كود الصنف')
                ->badge()
                ->color('gray'),
            TextColumn::make('branch_name')
                ->label('الفرع'),
            TextColumn::make('unit')
                ->label('الوحدة'),
            TextColumn::make('current_spend')
                ->label('إجمالي الإنفاق'),
            TextColumn::make('current_avg_price')
                ->label('متوسط السعر الحالي'),
            TextColumn::make('comparison_avg_price')
                ->label('متوسط سعر المقارنة'),
            TextColumn::make('price_change')
                ->label('تغير السعر')
                ->badge()
                ->color(fn (array $record): string => str_contains((string) $record['price_change'], '↑') ? 'danger' : (str_contains((string) $record['price_change'], '↓') ? 'success' : 'gray')),
            TextColumn::make('is_mapped')
                ->label('حالة التوحيد')
                ->badge()
                ->color(fn (array $record): string => $record['mapping_color'] ?? 'gray'),
        ];
    }
}
