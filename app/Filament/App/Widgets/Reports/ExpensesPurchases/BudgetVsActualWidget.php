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

class BudgetVsActualWidget extends BaseReportTableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('الميزانية التقديرية مقابل المنصرف الفعلي (Budget vs Actual)')
            ->description('مقارنة المنصرف الفعلي لبنود المصروفات مقابل الميزانية المحملة نسبياً لأيام الفترة (المحددة في POS كـ avg_month_rate)')
            ->paginated([10, 25, 50]);
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

            $items = $report->budgetVsActual();

            $records = [];
            foreach ($items as $dto) {
                $id = $dto->branchId.'_'.$dto->expenseTypeUuid;
                $records[$id] = [
                    'id' => $id,
                    'expense_type_name' => $dto->expenseTypeName,
                    'branch_name' => $dto->branchName,
                    'category_label' => $dto->categoryLabel,
                    'monthly_budget' => Format::money($dto->monthlyBudget),
                    'prorated_budget' => Format::money($dto->proratedBudget),
                    'actual_spent' => Format::money($dto->actualSpent),
                    'variance' => Format::money($dto->variance),
                    'consumption_pct' => Format::percent($dto->consumptionPct),
                    'is_over_budget' => $dto->isOverBudget,
                    'status' => $dto->isOverBudget ? 'متجاوز للميزانية' : 'ضمن الميزانية',
                ];
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('expense_type_name')
                ->label('بند المصروف')
                ->searchable()
                ->weight('bold'),

            TextColumn::make('branch_name')
                ->label('الفرع')
                ->badge()
                ->color('gray'),

            TextColumn::make('category_label')
                ->label('التصنيف')
                ->badge()
                ->color('info'),

            TextColumn::make('monthly_budget')
                ->label('الميزانية الشهرية')
                ->alignEnd(),

            TextColumn::make('prorated_budget')
                ->label('الميزانية المحملة للفترة')
                ->alignEnd(),

            TextColumn::make('actual_spent')
                ->label('المنصرف الفعلي')
                ->alignEnd()
                ->color(fn (array $record): string => $record['is_over_budget'] ? 'danger' : 'gray')
                ->weight(fn (array $record): string => $record['is_over_budget'] ? 'bold' : 'normal'),

            TextColumn::make('variance')
                ->label('الانحراف')
                ->alignEnd()
                ->color(fn (array $record): string => $record['is_over_budget'] ? 'danger' : 'success'),

            TextColumn::make('consumption_pct')
                ->label('نسبة الاستهلاك')
                ->alignCenter(),

            TextColumn::make('status')
                ->label('الحالة')
                ->badge()
                ->color(fn (array $record): string => $record['is_over_budget'] ? 'danger' : 'success'),
        ];
    }
}
