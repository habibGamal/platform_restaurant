<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ExpensesPurchases;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Models\User;
use App\Support\Format;
use Closure;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExpensesVsSalesGrowthWidget extends BaseReportTableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('مقارنة نمو المصروفات مقابل نمو المبيعات')
            ->description('مقارنة معدل نمو المصروفات (K11) مقابل معدل نمو المبيعات (K06) لكل فرع للكشف المبكر عن تصاعد التكاليف')
            ->paginated([15, 30, 50, 100]);
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
            $report = ExpensesPurchasesReport::for($query);

            $items = $report->expensesVsSalesGrowth();

            $records = [];
            foreach ($items as $dto) {
                $statusLabel = '—';
                if ($dto->expensesGrowth !== null && $dto->salesGrowth !== null) {
                    $statusLabel = $dto->isWarning
                        ? 'المصروفات تنمو أسرع من المبيعات ⚠️'
                        : 'نمو متوازن وطبيعي ✓';
                }

                $records[$dto->branchId] = [
                    'id' => $dto->branchId,
                    'branch_name' => $dto->branchName,
                    'current_expenses' => Format::money($dto->currentExpenses),
                    'expenses_growth' => Format::growth($dto->expensesGrowth),
                    'current_sales' => Format::money($dto->currentSales),
                    'sales_growth' => Format::growth($dto->salesGrowth),
                    'growth_delta' => $dto->growthDelta !== null ? Format::percent($dto->growthDelta) : '—',
                    'is_warning' => $dto->isWarning,
                    'status' => $statusLabel,
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

            TextColumn::make('current_expenses')
                ->label('المصروفات (K11)')
                ->alignEnd(),

            TextColumn::make('expenses_growth')
                ->label('نمو المصروفات (K24)')
                ->alignCenter(),

            TextColumn::make('current_sales')
                ->label('صافي المبيعات (K06)')
                ->alignEnd(),

            TextColumn::make('sales_growth')
                ->label('نمو المبيعات (K24)')
                ->alignCenter(),

            TextColumn::make('growth_delta')
                ->label('فارق النمو')
                ->alignCenter()
                ->color(fn (array $record): string => $record['is_warning'] ? 'danger' : 'gray'),

            TextColumn::make('status')
                ->label('حالة النمو')
                ->badge()
                ->color(fn (array $record): string => match (true) {
                    $record['is_warning'] => 'danger',
                    $record['status'] === '—' => 'gray',
                    default => 'success',
                }),
        ];
    }
}
