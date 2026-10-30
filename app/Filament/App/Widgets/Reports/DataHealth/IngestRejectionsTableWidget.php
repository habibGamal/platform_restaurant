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

class IngestRejectionsTableWidget extends BaseReportTableWidget
{
    protected static ?string $heading = 'سجل رفض المستندات (Rejections Log)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table
            ->heading('سجل رفض المستندات (Rejections Log)')
            ->description('المستندات التي رُفضت أثناء الاستقبال بسبب أخطاء بنية البيانات أو عدم توافق الإصدار')
            ->emptyStateHeading('لا توجد مستندات مرفوضة')
            ->emptyStateDescription('لم يتم تسجيل أي رفض لمستندات الاستقبال خلال الفترة المحددة.')
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
            $rejections = $report->rejectionsLog();

            $records = [];
            foreach ($rejections as $index => $rej) {
                $records[$index] = [
                    'id' => $index,
                    'branch_id' => $rej->branchId,
                    'branch_name' => $rej->branchName,
                    'doc_type' => $rej->docType,
                    'doc_uuid' => $rej->docUuid ?? '—',
                    'reason' => $rej->reason,
                    'pos_version' => $rej->posVersion ?? '—',
                    'first_seen' => $rej->firstSeenAt !== null ? $rej->firstSeenAt->format('Y-m-d H:i') : '—',
                    'last_seen' => $rej->lastSeenAt !== null ? $rej->lastSeenAt->format('Y-m-d H:i') : '—',
                    'count' => $rej->count,
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

            TextColumn::make('doc_type')
                ->label('نوع المستند')
                ->badge()
                ->color('info'),

            TextColumn::make('doc_uuid')
                ->label('المعرف')
                ->fontFamily('mono')
                ->limit(12)
                ->tooltip(fn (?string $state): ?string => $state !== '—' ? $state : null),

            TextColumn::make('reason')
                ->label('سبب الرفض')
                ->wrap(),

            TextColumn::make('pos_version')
                ->label('إصدار POS')
                ->badge()
                ->color('gray'),

            TextColumn::make('first_seen')
                ->label('أول ظهور'),

            TextColumn::make('last_seen')
                ->label('آخر ظهور'),

            TextColumn::make('count')
                ->label('التكرار')
                ->numeric()
                ->color(fn (int $state): string => $state > 1 ? 'danger' : 'gray'),
        ];
    }
}
