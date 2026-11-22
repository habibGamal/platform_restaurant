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

class BranchStatusTableWidget extends BaseReportTableWidget
{
    protected static ?string $heading = 'حالة اتصال الفروع وجودة المزامنة الحية';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table
            ->heading('حالة اتصال الفروع وجودة المزامنة الحية')
            ->description('مراقبة الاتصال اللحظي بنقاط البيع وحزم البيانات المعلقة والفاشلة')
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

            $query = MetricsQuery::for($user, $this->reportFilters());
            $report = DataHealthReport::for($query);
            $branchRows = $report->branchStatusTable();

            $records = [];
            foreach ($branchRows as $row) {
                $records[$row->branchId] = [
                    'id' => $row->branchId,
                    'branch_id' => $row->branchId,
                    'branch_name' => $row->branchName,
                    'status' => $row->status,
                    'status_label' => match ($row->status) {
                        'online' => 'متصل',
                        'delayed' => 'متأخر',
                        'offline' => 'غير متصل',
                        default => $row->status,
                    },
                    'last_heartbeat' => $row->lastHeartbeatFormatted ?? '—',
                    'last_batch' => $row->lastBatchFormatted ?? '—',
                    'pos_version' => $row->posVersion ?? '—',
                    'is_outdated' => $row->isOutdated,
                    'schema_version' => $row->schemaVersion !== null ? (string) $row->schemaVersion : '—',
                    'pending_count' => $row->pendingCount,
                    'oldest_pending_age' => $row->oldestPendingAge ?? '—',
                    'failed_count' => $row->failedCount,
                    'rejected_docs_7_days' => $row->rejectedDocs7Days,
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

            TextColumn::make('status_label')
                ->label('حالة الاتصال')
                ->badge()
                ->color(fn (array $record): string => match ($record['status'] ?? '') {
                    'online' => 'success',
                    'delayed' => 'warning',
                    'offline' => 'danger',
                    default => 'gray',
                }),

            TextColumn::make('last_heartbeat')
                ->label('آخر نبضة (Heartbeat)'),

            TextColumn::make('last_batch')
                ->label('آخر حزمة دفعة'),

            TextColumn::make('pos_version')
                ->label('إصدار POS')
                ->badge()
                ->color(fn (array $record): string => ($record['is_outdated'] ?? false) ? 'danger' : 'gray')
                ->formatStateUsing(fn (string $state, array $record): string => $state.(($record['is_outdated'] ?? false) ? ' (قديم)' : '')),

            TextColumn::make('schema_version')
                ->label('إصدار المخطط'),

            TextColumn::make('pending_count')
                ->label('معلقات POS')
                ->numeric()
                ->color(fn (int $state): string => $state > 500 ? 'warning' : ($state > 0 ? 'info' : 'gray')),

            TextColumn::make('oldest_pending_age')
                ->label('عمر أقدم معلق'),

            TextColumn::make('failed_count')
                ->label('مستندات فاشلة')
                ->numeric()
                ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),

            TextColumn::make('rejected_docs_7_days')
                ->label('مرفوضات (7 أيام)')
                ->numeric()
                ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
        ];
    }
}
