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

class SupplierSummaryTableWidget extends BaseReportTableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('تحليل الموردين وحصص التوريد (Supplier Breakdown)')
            ->description('حجم المشتريات وعدد الفواتير لكل مورد بعد مطابقة الأسماء عبر الفروع وحصة كل مورد من إجمالي المشتريات')
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

            $items = $report->supplierSummary();

            $records = [];
            foreach ($items as $index => $dto) {
                $branchesText = implode('، ', $dto->branchNames);

                $records[$index] = [
                    'id' => $index,
                    'supplier_name' => $dto->supplierName,
                    'branches' => $branchesText,
                    'branches_count' => Format::number($dto->branchesCount),
                    'invoices_count' => Format::number($dto->invoicesCount),
                    'net_purchases' => Format::money($dto->netPurchases),
                    'share' => Format::percent($dto->shareOfPurchases),
                ];
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('supplier_name')
                ->label('المورد')
                ->searchable()
                ->weight('bold'),

            TextColumn::make('branches')
                ->label('الفروع المشترية')
                ->limit(40),

            TextColumn::make('branches_count')
                ->label('عدد الفروع')
                ->alignCenter()
                ->badge()
                ->color('gray'),

            TextColumn::make('invoices_count')
                ->label('عدد الفواتير')
                ->alignCenter(),

            TextColumn::make('net_purchases')
                ->label('صافي المشتريات')
                ->alignEnd()
                ->weight('bold'),

            TextColumn::make('share')
                ->label('الحصة من المشتريات')
                ->alignCenter()
                ->badge()
                ->color('primary'),
        ];
    }
}
