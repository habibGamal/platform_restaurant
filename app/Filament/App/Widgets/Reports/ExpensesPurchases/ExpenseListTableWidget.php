<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ExpensesPurchases;

use App\Domain\Metrics\MetricsQuery;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Models\User;
use App\Support\Format;
use Closure;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ExpenseListTableWidget extends BaseReportTableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('تفاصيل المصروفات حسب الفرع')
            ->description('المصروفات المسجلة خلال الفترة المختارة، مع البحث والترتيب وتقسيم النتائج إلى صفحات.')
            ->paginated([15, 30, 50, 100]);
    }

    protected function getReportRecords(): ?Closure
    {
        return function (array $columnSearches, ?string $search, ?string $sortColumn, ?string $sortDirection, int|string $page, int|string|null $recordsPerPage) {
            /** @var User|null $user */
            $user = auth()->user();
            if ($user === null) {
                return new LengthAwarePaginator([], 0, 15, 1);
            }

            $reportFilters = $this->reportFilters();
            $query = MetricsQuery::for($user, $reportFilters);
            $period = $reportFilters->period;
            $columns = [
                'business_date' => 'wh_expenses.business_date', 'branch_name' => 'branches.name',
                'shift' => 'wh_expenses.shift_uuid', 'expense_type_name' => 'dim_expense_types.name',
                'category_label' => DB::raw("COALESCE(dim_expense_types.category_override, dim_expense_types.category, 'other')"),
                'amount' => 'wh_expenses.amount', 'notes' => 'wh_expenses.notes',
            ];
            $source = DB::table('wh_expenses')
                ->whereIn('wh_expenses.branch_id', $query->branchIds())
                ->whereBetween('wh_expenses.business_date', [$period->from->toDateString(), $period->to->toDateString()])
                ->whereNull('wh_expenses.deleted_at')
                ->join('branches', 'branches.id', '=', 'wh_expenses.branch_id')
                ->leftJoin('dim_expense_types', function ($join): void {
                    $join->on('wh_expenses.expense_type_uuid', '=', 'dim_expense_types.uuid')
                        ->on('wh_expenses.branch_id', '=', 'dim_expense_types.branch_id');
                })
                ->select('wh_expenses.id', 'wh_expenses.business_date', 'wh_expenses.shift_uuid', 'wh_expenses.amount', 'wh_expenses.notes', 'branches.name as branch_name', 'dim_expense_types.name as expense_type_name', 'dim_expense_types.category', 'dim_expense_types.category_override');

            foreach ($columnSearches as $column => $value) {
                if (isset($columns[$column]) && is_string($value) && $value !== '') {
                    $this->searchExpenseColumn($source, $column, $value, $columns[$column]);
                }
            }
            if ($search !== null && $search !== '') {
                $source->where(function (Builder $nested) use ($columns, $search): void {
                    foreach ($columns as $column => $sqlColumn) {
                        $this->searchExpenseColumn($nested, $column, $search, $sqlColumn, 'or');
                    }
                });
            }
            $order = isset($columns[$sortColumn ?? '']) ? $columns[$sortColumn] : 'wh_expenses.business_date';
            $direction = $sortDirection === 'asc' ? 'asc' : 'desc';
            $source->orderBy($order, $direction)->orderByDesc('wh_expenses.id');
            $result = $source->paginate((int) ($recordsPerPage ?: 15), ['*'], $this->getTablePaginationPageName(), (int) $page);
            $result->setCollection($result->getCollection()->mapWithKeys(function ($row): array {
                $category = (string) ($row->category_override ?? $row->category ?? 'other');

                return [$row->id => [
                    'id' => $row->id, 'business_date' => $row->business_date, 'branch_name' => $row->branch_name,
                    'shift' => $row->shift_uuid !== null ? substr($row->shift_uuid, 0, 8) : '—',
                    'expense_type_name' => $row->expense_type_name ?? 'غير محدد',
                    'category_label' => self::CATEGORY_LABELS[$category] ?? $category,
                    'amount' => Format::money((float) $row->amount), 'notes' => $row->notes ?? '—',
                ]];
            }));

            return $result;
        };
    }

    private const CATEGORY_LABELS = [
        'labor' => 'عمالة', 'rent' => 'إيجار', 'utilities' => 'مرافق', 'marketing' => 'تسويق',
        'maintenance' => 'صيانة', 'operational' => 'تشغيلية', 'supplies' => 'مستلزمات', 'other' => 'أخرى',
    ];

    private function searchExpenseColumn(Builder $query, string $column, string $search, mixed $sqlColumn, string $boolean = 'and'): void
    {
        if ($column === 'category_label') {
            $matching = array_keys(array_filter(self::CATEGORY_LABELS, fn (string $label, string $key): bool => mb_stripos($label, $search) !== false || mb_stripos($key, $search) !== false, ARRAY_FILTER_USE_BOTH));
            $query->whereIn($sqlColumn, $matching, $boolean);

            return;
        }
        $query->where($sqlColumn, 'like', '%'.$search.'%', $boolean);
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
