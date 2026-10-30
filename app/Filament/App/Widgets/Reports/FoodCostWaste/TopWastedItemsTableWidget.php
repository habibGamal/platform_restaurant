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

class TopWastedItemsTableWidget extends BaseReportTableWidget
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('أعلى الأصناف هالكاً')
            ->description('الأصناف الأكثر هدراً مرتبة حسب إجمالي تكلفة الهالك ونسبتها من هالك الفرع')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
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

            $items = $report->topWastedItems(limit: 50);

            $records = [];
            foreach ($items as $idx => $item) {
                $records[$idx + 1] = [
                    'id' => $idx + 1,
                    'product_name' => $item->productName,
                    'product_ref' => $item->productRef ?? '—',
                    'branch_name' => $item->branchName,
                    'waste_qty' => Format::number($item->wasteQty, 2).' '.($item->unit ?? 'قطعة'),
                    'waste_cost' => Format::money($item->wasteCost, 2),
                    'waste_share' => Format::percent($item->wasteShareOfBranch),
                ];
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('product_name')
                ->label('اسم الصنف / المنتج')
                ->weight('bold'),
            TextColumn::make('product_ref')
                ->label('كود الصنف')
                ->badge()
                ->color('gray'),
            TextColumn::make('branch_name')
                ->label('الفرع'),
            TextColumn::make('waste_qty')
                ->label('الكمية المهدرة'),
            TextColumn::make('waste_cost')
                ->label('تكلفة الهالك'),
            TextColumn::make('waste_share')
                ->label('نسبته من هالك الفرع')
                ->badge()
                ->color('danger'),
        ];
    }
}
