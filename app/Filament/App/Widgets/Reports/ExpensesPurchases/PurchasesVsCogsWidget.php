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

class PurchasesVsCogsWidget extends BaseReportTableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('المشتريات مقابل تكلفة المبيعات (Purchases vs COGS)')
            ->description('مقارنة صافي المشتريات (K20) مقابل تكلفة البضاعة المباعة (K08) لمراقبة حركة وتراكم المخزون والهالك غير المسجل')
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

            $items = $report->purchasesVsCogs();

            $records = [];
            foreach ($items as $dto) {
                $records[$dto->branchId] = [
                    'id' => $dto->branchId,
                    'branch_name' => $dto->branchName,
                    'net_purchases' => Format::money($dto->netPurchases),
                    'cogs' => Format::money($dto->cogs),
                    'ratio' => Format::percent($dto->ratio),
                    'status' => $dto->status,
                    'status_label' => $dto->statusLabel,
                    'tooltip' => $dto->tooltip,
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

            TextColumn::make('net_purchases')
                ->label('صافي المشتريات (K20)')
                ->alignEnd(),

            TextColumn::make('cogs')
                ->label('تكلفة المبيعات (K08)')
                ->alignEnd(),

            TextColumn::make('ratio')
                ->label('نسبة المشتريات إلى التكلفة')
                ->alignCenter()
                ->weight('bold'),

            TextColumn::make('status_label')
                ->label('التقييم ومؤشر المخزون')
                ->tooltip(fn (array $record): string => $record['tooltip'])
                ->badge()
                ->color(fn (array $record): string => match ($record['status']) {
                    'building_up' => 'warning',
                    'running_down' => 'info',
                    'balanced' => 'success',
                    default => 'gray',
                }),
        ];
    }
}
