<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\FoodCostWaste;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\FoodCostWasteReport;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Models\User;
use App\Support\Format;
use Closure;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StocktakingVarianceTableWidget extends BaseReportTableWidget
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('فروقات آخر جرد مخزني')
            ->description('بنود العجز في آخر جرد لكل فرع مرتبة حسب أعلى قيمة عجز')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->actions([
                Action::make('view_stocktaking')
                    ->label('المستند')
                    ->icon('heroicon-o-document-text')
                    ->modalHeading(fn (array $record): string => 'مستند الجرد: '.$record['stocktaking_uuid'])
                    ->modalDescription(fn (array $record): string => 'الفرع: '.$record['branch_name'].' | تاريخ الجرد: '.$record['business_date'])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق'),
            ]);
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

            $items = $report->stocktakingVariances(limit: 50);

            $records = [];
            foreach ($items as $idx => $item) {
                $records[$idx + 1] = [
                    'id' => $idx + 1,
                    'branch_name' => $item->branchName,
                    'business_date' => $item->businessDate,
                    'stocktaking_uuid' => $item->stocktakingUuid,
                    'product_name' => $item->productName,
                    'product_ref' => $item->productRef ?? '—',
                    'stock_quantity' => Format::number($item->stockQuantity, 2).' '.($item->unit ?? 'قطعة'),
                    'real_quantity' => Format::number($item->realQuantity, 2).' '.($item->unit ?? 'قطعة'),
                    'shortage_quantity' => Format::number($item->shortageQuantity, 2).' '.($item->unit ?? 'قطعة'),
                    'price' => Format::money($item->price, 2),
                    'shortage_value' => Format::money($item->shortageValue, 2),
                ];
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('branch_name')
                ->label('الفرع')
                ->weight('bold'),
            TextColumn::make('business_date')
                ->label('تاريخ الجرد'),
            TextColumn::make('product_name')
                ->label('اسم الصنف'),
            TextColumn::make('stock_quantity')
                ->label('الكمية الدفترية'),
            TextColumn::make('real_quantity')
                ->label('الكمية الفعلية'),
            TextColumn::make('shortage_quantity')
                ->label('كمية العجز')
                ->badge()
                ->color('danger'),
            TextColumn::make('price')
                ->label('سعر الوحدة'),
            TextColumn::make('shortage_value')
                ->label('قيمة العجز')
                ->weight('bold')
                ->color('danger'),
        ];
    }
}
