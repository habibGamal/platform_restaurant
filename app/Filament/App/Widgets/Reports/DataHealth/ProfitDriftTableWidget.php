<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\DataHealth;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\DataHealthReport;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Models\User;
use Closure;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProfitDriftTableWidget extends BaseReportTableWidget
{
    protected static ?string $heading = 'فحص انحراف حساب الأرباح (POS vs Platform Profit Drift)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table
            ->heading('فحص انحراف حساب الأرباح (POS vs Platform Profit Drift)')
            ->description('الأيام التي تختلف فيها معادلة الربح المسجلة في نقاط البيع (Σ profit_pos) عن مجمل ربح المنصة (K09) بأكثر من 1%')
            ->emptyStateHeading('لا يوجد انحراف في حساب الأرباح')
            ->emptyStateDescription('جميع الأيام المسجلة تتطابق فيها أرباح نقطة البيع مع أرباح المنصة بنسبة خطأ أقل من 1%.')
            ->paginated(false);
    }

    protected function getReportRecords(): ?Closure
    {
        return function (): array {
            /** @var User|null $user */
            $user = auth()->user();
            if ($user === null) {
                return [];
            }

            $query = MetricsQuery::for($user, $this->reportFilters());
            $report = DataHealthReport::for($query);
            $drifts = $report->profitDriftCheck();

            $records = [];
            foreach ($drifts as $index => $row) {
                $records[$index] = [
                    'id' => $index,
                    'branch_id' => $row->branchId,
                    'branch_name' => $row->branchName,
                    'date' => $row->date,
                    'pos_profit' => $row->posProfit,
                    'platform_profit' => $row->platformProfit,
                    'diff' => $row->diff,
                    'diff_percent' => $row->diffPercent,
                ];
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('date')
                ->label('التاريخ')
                ->sortable(),

            TextColumn::make('branch_name')
                ->label('الفرع')
                ->weight('bold'),

            TextColumn::make('pos_profit')
                ->label('ربح POS (Σ profit_pos)')
                ->money('EGP'),

            TextColumn::make('platform_profit')
                ->label('ربح المنصة (K09 Gross Profit)')
                ->money('EGP'),

            TextColumn::make('diff')
                ->label('الفارق')
                ->money('EGP')
                ->color('danger')
                ->weight('bold'),

            TextColumn::make('diff_percent')
                ->label('نسبة الانحراف')
                ->formatStateUsing(fn (float $state): string => number_format($state, 2).'%')
                ->color('danger')
                ->weight('bold'),
        ];
    }
}
