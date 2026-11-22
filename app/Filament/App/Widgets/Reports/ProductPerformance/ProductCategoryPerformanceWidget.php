<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ProductPerformance;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProductPerformanceReport;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Models\Branch;
use App\Models\User;
use App\Support\Format;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductCategoryPerformanceWidget extends BaseReportTableWidget
{
    public ?int $selectedBranchId = null;

    public function setBranch(?int $branchId): void
    {
        $this->selectedBranchId = $branchId;
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table
            ->heading('أداء التصنيفات (Category Performance)')
            ->description('تحليل مبيعات وهوامش ربح تصنيفات الأصناف')
            ->headerActions($this->getTableActions());
    }

    /**
     * @return list<Action|ActionGroup>
     */
    protected function getTableActions(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $branchIds = $query->branchIds();

        if (count($branchIds) <= 1 || ! $query->canSeeOtherBranchNames()) {
            return [];
        }

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->pluck('name', 'id');

        $actions = [
            Action::make('branch_all')
                ->label('كل الفروع المحددة')
                ->icon($this->selectedBranchId === null ? 'heroicon-m-check' : null)
                ->action(fn () => $this->setBranch(null)),
        ];

        foreach ($branches as $id => $name) {
            $actions[] = Action::make("branch_{$id}")
                ->label($name)
                ->icon($this->selectedBranchId === (int) $id ? 'heroicon-m-check' : null)
                ->action(fn () => $this->setBranch((int) $id));
        }

        $currentLabel = $this->selectedBranchId !== null
            ? ($branches[$this->selectedBranchId] ?? 'الفرع المحدد')
            : 'كل الفروع المحددة';

        return [
            ActionGroup::make($actions)
                ->label("الفرع: {$currentLabel}")
                ->icon('heroicon-m-building-storefront')
                ->color($this->selectedBranchId !== null ? 'primary' : 'gray'),
        ];
    }

    protected function getReportRecords(): ?Closure
    {
        return function (): array {
            /** @var User|null $user */
            $user = auth()->user();
            if (! $user) {
                return [];
            }

            $query = MetricsQuery::for($user, $this->reportFilters());
            $report = ProductPerformanceReport::for($query);
            $categories = $report->categoryPerformance($this->selectedBranchId);

            $records = [];
            foreach ($categories as $index => $row) {
                $id = $row->categoryUuid ?: "cat_{$index}";

                $records[$id] = [
                    'id' => $id,
                    'name' => $row->name,
                    'products_count' => Format::number((float) $row->productsCount, 0),
                    'qty_sold' => Format::number($row->qtySold, 0),
                    'sales' => Format::money($row->netSales, 2),
                    'gross_profit' => Format::money($row->grossProfit, 2),
                    'margin_pct' => Format::percent($row->marginPct, 1),
                    'share_of_sales' => Format::percent($row->shareOfSales, 1),
                    'growth' => Format::growth($row->growth),
                ];
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('name')
                ->label('التصنيف')
                ->weight('bold'),

            TextColumn::make('products_count')
                ->label('عدد الأصناف')
                ->alignCenter(),

            TextColumn::make('qty_sold')
                ->label('الكمية المباعة')
                ->alignEnd(),

            TextColumn::make('sales')
                ->label('صافي المبيعات')
                ->alignEnd(),

            TextColumn::make('gross_profit')
                ->label('مجمل الربح')
                ->alignEnd(),

            TextColumn::make('margin_pct')
                ->label('هامش الربح')
                ->alignEnd()
                ->badge()
                ->color('success'),

            TextColumn::make('share_of_sales')
                ->label('الحصة من المبيعات')
                ->alignEnd(),

            TextColumn::make('growth')
                ->label('النمو')
                ->alignEnd(),
        ];
    }
}
