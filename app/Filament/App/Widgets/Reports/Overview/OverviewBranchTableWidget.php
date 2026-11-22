<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Overview;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\OverviewReport;
use App\Filament\App\Pages\Reports\Overview;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Models\User;
use App\Support\Format;
use Closure;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OverviewBranchTableWidget extends BaseReportTableWidget
{
    protected static ?string $heading = 'أداء الفروع';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table
            ->heading('أداء الفروع')
            ->paginated([15, 30, 50, 100])
            ->recordUrl(function (array $record): ?string {
                $id = $record['branch_id'] ?? null;
                if (! $id || ($record['id'] ?? null) === 'total') {
                    return null;
                }

                return Overview::getUrl(['branches' => $id]);
            });
    }

    protected function getReportRecords(): ?Closure
    {
        return function (?string $sortColumn = null, ?string $sortDirection = null): array {
            /** @var User|null $user */
            $user = auth()->user();
            if (! $user) {
                return [];
            }

            $query = MetricsQuery::for($user, $this->reportFilters());
            $report = OverviewReport::for($query);

            $data = $report->branchTable($sortColumn, $sortDirection);
            $rows = $data['branches'];

            if ($data['totals'] !== null) {
                $rows['total'] = $data['totals'];
            }

            return $rows;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('branch')
                ->label('الفرع')
                ->html()
                ->formatStateUsing(function (string $state, array $record): string {
                    if (($record['id'] ?? null) === 'total') {
                        return '<span class="font-bold text-gray-900 dark:text-white">'.e($state).'</span>';
                    }

                    $status = (string) ($record['completeness_status'] ?? 'complete');
                    $dotColor = match ($status) {
                        'complete' => 'bg-emerald-500',
                        'closed' => 'bg-amber-500',
                        'open' => 'bg-sky-500',
                        'missing' => 'bg-rose-500',
                        'not_opened' => 'bg-gray-400',
                        default => 'bg-gray-300',
                    };

                    return '<div class="flex items-center gap-2">'
                        .'<span class="inline-block w-2.5 h-2.5 rounded-full shrink-0 '.$dotColor.'"></span>'
                        .'<span class="font-medium text-gray-900 dark:text-white">'.e($state).'</span>'
                        .'</div>';
                })
                ->sortable(),

            TextColumn::make('sales')
                ->label('المبيعات')
                ->html()
                ->formatStateUsing(function ($state, array $record): string {
                    $val = is_numeric($state) ? (float) $state : null;
                    $growth = $record['growth'] ?? null;
                    $growthStr = is_numeric($growth) ? Format::growth((float) $growth) : '—';
                    $growthColor = (is_numeric($growth) && (float) $growth > 0)
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : ((is_numeric($growth) && (float) $growth < 0) ? 'text-rose-600 dark:text-rose-400' : 'text-gray-500');

                    $isTotal = ($record['id'] ?? null) === 'total';
                    $weight = $isTotal ? 'font-bold' : 'font-medium';

                    return '<div>'
                        .'<div class="'.$weight.' text-gray-900 dark:text-white">'.Format::money($val, 2).'</div>'
                        .'<div class="text-xs '.$growthColor.'">'.e($growthStr).'</div>'
                        .'</div>';
                })
                ->sortable(),

            TextColumn::make('orders')
                ->label('الطلبات')
                ->formatStateUsing(fn ($state): string => is_numeric($state) ? Format::number((float) $state, 0) : '—')
                ->sortable(),

            TextColumn::make('avg_ticket')
                ->label('متوسط الطلب')
                ->formatStateUsing(fn ($state): string => is_numeric($state) ? Format::money((float) $state, 2) : '—')
                ->sortable(),

            TextColumn::make('gross_profit')
                ->label('مجمل الربح')
                ->formatStateUsing(fn ($state): string => is_numeric($state) ? Format::money((float) $state, 2) : '—')
                ->sortable(),

            TextColumn::make('net_profit')
                ->label('صافي الربح')
                ->html()
                ->formatStateUsing(function ($state): string {
                    if (! is_numeric($state)) {
                        return '—';
                    }
                    $val = (float) $state;
                    $formatted = Format::money($val, 2);
                    if ($val < 0) {
                        return '<span class="text-rose-600 dark:text-rose-400 font-medium">'.e($formatted).'</span>';
                    }

                    return '<span class="text-gray-900 dark:text-white font-medium">'.e($formatted).'</span>';
                })
                ->sortable(),

            TextColumn::make('net_margin')
                ->label('هامش الربح')
                ->html()
                ->formatStateUsing(function ($state, array $record): string {
                    if (! is_numeric($state)) {
                        return '—';
                    }
                    $val = (float) $state;
                    $formatted = Format::percent($val, 1);
                    $color = $record['net_margin_color'] ?? 'gray';
                    $cssColor = match ($color) {
                        'success' => 'text-emerald-600 dark:text-emerald-400',
                        'warning' => 'text-amber-600 dark:text-amber-400',
                        'danger' => 'text-rose-600 dark:text-rose-400',
                        default => 'text-gray-600 dark:text-gray-400',
                    };

                    return '<span class="'.$cssColor.' font-medium">'.e($formatted).'</span>';
                })
                ->sortable(),

            TextColumn::make('food_cost_pct')
                ->label('تكلفة الخامات %')
                ->formatStateUsing(fn ($state): string => is_numeric($state) ? Format::percent((float) $state, 1) : '—')
                ->sortable(),

            TextColumn::make('waste_pct')
                ->label('الهالك %')
                ->formatStateUsing(fn ($state): string => is_numeric($state) ? Format::percent((float) $state, 1) : '—')
                ->sortable(),

            TextColumn::make('expenses')
                ->label('المصروفات')
                ->formatStateUsing(fn ($state): string => is_numeric($state) ? Format::money((float) $state, 2) : '—')
                ->sortable(),

            TextColumn::make('net_purchases')
                ->label('المشتريات')
                ->formatStateUsing(fn ($state): string => is_numeric($state) ? Format::money((float) $state, 2) : '—')
                ->sortable(),

            TextColumn::make('target_pct')
                ->label('المستهدف %')
                ->html()
                ->formatStateUsing(function ($state, array $record): string {
                    if (! is_numeric($state)) {
                        return '<span class="text-gray-400">—</span>';
                    }
                    $val = (float) $state;
                    $formatted = Format::percent($val, 1);
                    $status = (string) ($record['target_status'] ?? 'no_target');
                    $color = match ($status) {
                        'on_track', 'above' => 'text-emerald-600 dark:text-emerald-400',
                        'at_risk', 'within' => 'text-amber-600 dark:text-amber-400',
                        'behind' => 'text-rose-600 dark:text-rose-400',
                        default => 'text-gray-600 dark:text-gray-400',
                    };

                    return '<span class="'.$color.' font-medium">'.e($formatted).'</span>';
                })
                ->sortable(),
        ];
    }
}
