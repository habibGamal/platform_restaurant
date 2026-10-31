<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\TargetsVsActual;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\TargetsVsActualReport;
use App\Domain\Metrics\Reports\TargetsVsActualReport\TargetHistoryRow;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;
use App\Support\Format;
use Filament\Tables\Table;

class TargetsHistoryWidget extends BaseReportViewWidget
{
    protected ?string $heading = 'سجل تحقيق المستهدفات التاريخي (آخر 12 شهراً)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordClasses(fn (array $record): ?string => ($record['_is_group'] ?? false) ? 'bg-primary-50/40 dark:bg-primary-950/20 font-semibold' : null);
    }

    protected function getColumns(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return ['branch' => 'الفرع'];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = TargetsVsActualReport::for($query);
        $data = $report->data();

        $columns = ['branch' => 'الفرع'];
        foreach ($data->historyMonths as $mKey) {
            $columns[$mKey] = $data->historyMonthLabels[$mKey] ?? $mKey;
        }

        return $columns;
    }

    protected function getRows(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = TargetsVsActualReport::for($query);
        $data = $report->data();

        $rows = [];
        foreach ($data->historyRows as $row) {
            $recordKey = $row->isGroup ? 'group' : 'branch_'.($row->branchId ?? '0');
            $rows[$recordKey] = $this->formatRow($row, $data->historyMonths);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $historyMonths
     * @return array<string, mixed>
     */
    private function formatRow(TargetHistoryRow $row, array $historyMonths): array
    {
        $branchClass = $row->isGroup
            ? 'font-bold text-primary-600 dark:text-primary-400'
            : 'font-semibold text-gray-900 dark:text-white';

        $branchHtml = "<span class='{$branchClass} whitespace-nowrap'>".e($row->branchName).'</span>';
        if ($row->isGroup) {
            $branchHtml .= ' <span class="text-xs text-gray-400 font-normal whitespace-nowrap">(مجمّع)</span>';
        }

        $formatted = [
            '_is_group' => $row->isGroup,
            'branch' => $branchHtml,
        ];

        foreach ($historyMonths as $mKey) {
            $cell = $row->cells[$mKey] ?? null;

            if ($cell === null || $cell->status === 'no_target' || $cell->achievement === null) {
                $formatted[$mKey] = '<span class="text-xs text-gray-400 whitespace-nowrap">—</span>';

                continue;
            }

            $badgeColor = match ($cell->status) {
                'on_track' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-400',
                'at_risk' => 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-400',
                default => 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-400',
            };

            $tooltip = 'المحقق: '.Format::money($cell->actual).' / المستهدف: '.Format::money($cell->target);
            $formatted[$mKey] = "<span class='inline-block px-2.5 py-1 rounded text-xs font-semibold {$badgeColor} whitespace-nowrap' title='".e($tooltip)."'><span class='inline-block dir-ltr'>".Format::percent($cell->achievement).'</span></span>';
        }

        return $formatted;
    }
}
