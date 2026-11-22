<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\DataHealth;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\DataHealthReport;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReconciliationIssuesTableWidget extends BaseReportTableWidget
{
    protected static ?string $heading = 'مشاكل المطابقة مع ملخصات نقاط البيع (POS)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table
            ->heading('مشاكل المطابقة مع ملخصات نقاط البيع (POS)')
            ->description('الفروقات المكتشفة بين الحسابات المستخرجة من المستندات وملخصات Day Summary المرسلة من POS')
            ->recordActions([
                Action::make('resync')
                    ->label('طلب إعادة مزامنة')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (): bool => auth()->user()?->can('branches.manage') ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('طلب إعادة مزامنة اليوم')
                    ->modalDescription('سيتم إرسال أمر إعادة المزامنة لنقطة البيع في النبضة التالية لإعادة رفع المستندات المفقودة.')
                    ->modalSubmitActionLabel('إرسال الأمر')
                    ->action(function (array $record): void {
                        $this->requestResync((int) $record['id']);
                    }),
            ])
            ->paginated([15, 30, 50, 100]);
    }

    public function requestResync(int $issueId): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null || ! $user->can('branches.manage')) {
            abort(403, 'غير مصرح لك بطلب إعادة المزامنة.');
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = DataHealthReport::for($query);
        $report->requestResync($issueId, $user);

        Notification::make()
            ->title('تم إرسال طلب إعادة المزامنة للفرع')
            ->success()
            ->send();
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
            $issues = $report->reconciliationIssues();

            $records = [];
            foreach ($issues as $issue) {
                $records[$issue->id] = [
                    'id' => $issue->id,
                    'branch_id' => $issue->branchId,
                    'branch_name' => $issue->branchName,
                    'date' => $issue->date,
                    'field' => $issue->field,
                    'field_label' => $issue->fieldLabel,
                    'pos_value' => $issue->posValue,
                    'platform_value' => $issue->platformValue,
                    'diff' => $issue->diff,
                    'attempts' => $issue->attempts,
                    'status' => $issue->status,
                    'status_label' => $issue->statusLabel,
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

            TextColumn::make('field_label')
                ->label('الحقل محل الاختلاف')
                ->badge()
                ->color('gray'),

            TextColumn::make('pos_value')
                ->label('قيمة POS')
                ->numeric(2)
                ->default('—'),

            TextColumn::make('platform_value')
                ->label('قيمة المنصة')
                ->numeric(2)
                ->default('—'),

            TextColumn::make('diff')
                ->label('الفارق')
                ->numeric(2)
                ->color('danger')
                ->weight('bold'),

            TextColumn::make('attempts')
                ->label('محاولات المزامنة')
                ->numeric(),

            TextColumn::make('status_label')
                ->label('الحالة')
                ->badge()
                ->color(fn (array $record): string => match ($record['status'] ?? '') {
                    'resolved' => 'success',
                    'resync_requested' => 'warning',
                    'failed' => 'danger',
                    default => 'danger',
                }),
        ];
    }
}
