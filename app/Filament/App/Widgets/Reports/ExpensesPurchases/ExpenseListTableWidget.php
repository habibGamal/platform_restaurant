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

class ExpenseListTableWidget extends BaseReportTableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('سجل تفاصيل المصروفات (Expense Drill-down)')
            ->description('بيانات المصروفات المسجلة من نقاط البيع (wh_expenses) مع إمكانية البحث والتفصيل')
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

            $items = $report->expenseList(limit: 200);

            $records = [];
            foreach ($items as $dto) {
                $shiftShort = $dto->shiftUuid !== null ? substr($dto->shiftUuid, 0, 8) : '—';

                $records[$dto->id] = [
                    'id' => $dto->id,
                    'business_date' => $dto->businessDate,
                    'branch_name' => $dto->branchName,
                    'shift' => $shiftShort,
                    'expense_type_name' => $dto->expenseTypeName,
                    'category_label' => $dto->categoryLabel,
                    'amount' => Format::money($dto->amount),
                    'notes' => $dto->notes ?? '—',
                ];
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('business_date')
                ->label('تاريخ العمل')
                ->sortable(),

            TextColumn::make('branch_name')
                ->label('الفرع')
                ->badge()
                ->color('gray'),

            TextColumn::make('shift')
                ->label('الوردية')
                ->fontFamily('mono')
                ->size('xs'),

            TextColumn::make('expense_type_name')
                ->label('بند المصروف')
                ->searchable()
                ->weight('bold'),

            TextColumn::make('category_label')
                ->label('التصنيف')
                ->badge()
                ->color('info'),

            TextColumn::make('amount')
                ->label('المبلغ')
                ->alignEnd()
                ->weight('bold'),

            TextColumn::make('notes')
                ->label('ملاحظات')
                ->limit(50),
        ];
    }
}
