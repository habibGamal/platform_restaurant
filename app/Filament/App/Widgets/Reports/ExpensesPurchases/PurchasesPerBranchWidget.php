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

class PurchasesPerBranchWidget extends BaseReportTableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('المشتريات لكل فرع (Purchases per Branch)')
            ->description('إجمالي فواتير الشراء والمرتجعات وصافي المشتريات (K20) والاتجاه الأسبوعي لكل فرع')
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

            $items = $report->purchasesPerBranch();

            $records = [];
            foreach ($items as $dto) {
                $trendText = [];
                foreach ($dto->weeklyTrend as $week => $val) {
                    $trendText[] = $week.': '.Format::money($val);
                }
                $trendSummary = ! empty($trendText) ? implode(' | ', $trendText) : '—';

                $records[$dto->branchId] = [
                    'id' => $dto->branchId,
                    'branch_name' => $dto->branchName,
                    'invoices_amount' => Format::money($dto->invoicesAmount),
                    'invoices_count' => Format::number($dto->invoicesCount),
                    'returns_amount' => Format::money($dto->returnsAmount),
                    'net_purchases' => Format::money($dto->netPurchases),
                    'trend' => $trendSummary,
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

            TextColumn::make('invoices_amount')
                ->label('إجمالي فواتير الشراء')
                ->alignEnd(),

            TextColumn::make('invoices_count')
                ->label('عدد الفواتير')
                ->alignCenter(),

            TextColumn::make('returns_amount')
                ->label('المرتجعات')
                ->alignEnd(),

            TextColumn::make('net_purchases')
                ->label('صافي المشتريات (K20)')
                ->alignEnd()
                ->weight('bold')
                ->color('primary'),

            TextColumn::make('trend')
                ->label('الاتجاه حسب الأسبوع')
                ->size('xs')
                ->color('gray'),
        ];
    }
}
