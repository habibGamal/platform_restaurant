<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Overview;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\OverviewReport;
use App\Filament\App\Pages\Reports\DataHealth;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OverviewDataFreshnessWidget extends BaseReportTableWidget
{
    protected int|string|array $columnSpan = 'full';

    /**
     * @var array{summary: array{complete: int, total: int}, branches: list<array<string, mixed>>}|null
     */
    private ?array $cachedFreshness = null;

    /**
     * @return array{summary: array{complete: int, total: int}, branches: list<array<string, mixed>>}
     */
    private function getFreshness(): array
    {
        if ($this->cachedFreshness !== null) {
            return $this->cachedFreshness;
        }

        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return $this->cachedFreshness = [
                'summary' => ['complete' => 0, 'total' => 0],
                'branches' => [],
            ];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = OverviewReport::for($query);

        return $this->cachedFreshness = $report->dataFreshness();
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table
            ->heading('حالة مزامنة واكتمال البيانات')
            ->description(function (): string {
                $summary = $this->getFreshness()['summary'];

                return "{$summary['complete']} من {$summary['total']} فرع مكتمل البيانات";
            })
            ->headerActions([
                Action::make('dataHealth')
                    ->label('تقرير صحة البيانات ←')
                    ->url(fn (): string => DataHealth::canAccess() ? DataHealth::getUrl() : url('/app/reports/data-health'))
                    ->link(),
            ])
            ->paginated(false)
            ->emptyStateHeading('لا توجد فروع محددة')
            ->emptyStateDescription(null);
    }

    protected function getReportRecords(): ?Closure
    {
        return function (): array {
            $data = $this->getFreshness();
            $records = [];

            foreach ($data['branches'] as $branch) {
                $records[$branch['id']] = $branch;
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('name')
                ->label('الفرع')
                ->weight('medium')
                ->searchable(false),

            TextColumn::make('last_sync')
                ->label('آخر مزامنة')
                ->placeholder('لا توجد مزامنة')
                ->description(fn (array $record): ?string => $record['last_sync_time'] ? "الساعة {$record['last_sync_time']}" : null),

            TextColumn::make('status')
                ->label('الحالة')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => match ($state) {
                    'online' => 'متصل',
                    'delayed' => 'متأخر (> 15 دقيقة)',
                    'offline' => 'غير متصل',
                    default => $state ?? '—',
                })
                ->color(fn (?string $state): string => match ($state) {
                    'online' => 'success',
                    'delayed' => 'warning',
                    'offline' => 'danger',
                    default => 'gray',
                }),
        ];
    }
}
