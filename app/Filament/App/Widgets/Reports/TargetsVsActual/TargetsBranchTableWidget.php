<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\TargetsVsActual;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\TargetsVsActualReport;
use App\Domain\Metrics\Reports\TargetsVsActualReport\TargetBranchTableRow;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;
use App\Support\Format;
use Filament\Tables\Table;

class TargetsBranchTableWidget extends BaseReportViewWidget
{
    protected ?string $heading = 'مقارنة المستهدف بالمحقق حسب الفرع والمؤشر';

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordClasses(fn (array $record): ?string => ($record['_is_group'] ?? false) ? 'bg-primary-50/40 dark:bg-primary-950/20 font-semibold' : null);
    }

    protected function getColumns(): array
    {
        return [
            'branch' => 'الفرع',
            'metric' => 'المؤشر',
            'target_month' => 'المستهدف الشهري',
            'target_to_date' => 'المستهدف حتى اليوم',
            'actual_to_date' => 'المحقق حتى اليوم',
            'achievement_to_date' => 'نسبة التحقيق',
            'gap' => 'الفجوة',
            'projected' => 'المتوقع لنهاية الشهر',
            'projected_achievement' => 'التحقيق المتوقع',
            'required_daily' => 'المعدل اليومي المطلوب',
            'current_daily' => 'المعدل اليومي الحالي',
            'status' => 'الحالة',
        ];
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
        $branchRows = $report->data()->branchRows;

        $canManageTargets = $user->can('targets.manage') || $user->hasRole('org_owner');

        $rows = [];
        foreach ($branchRows as $row) {
            $recordKey = ($row->isGroup ? 'group' : 'branch_'.($row->branchId ?? '0')).'_'.$row->metric;
            $rows[$recordKey] = $this->formatRow($row, $canManageTargets);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRow(TargetBranchTableRow $row, bool $canManageTargets): array
    {
        $isGroup = $row->isGroup;
        $metric = $row->metric;

        $branchClass = $isGroup
            ? 'font-bold text-primary-600 dark:text-primary-400'
            : 'font-semibold text-gray-900 dark:text-white';

        $branchHtml = "<span class='{$branchClass} whitespace-nowrap'>".e($row->branchName).'</span>';
        if ($isGroup) {
            $branchHtml .= ' <span class="text-xs text-gray-400 font-normal whitespace-nowrap">(مجمّع)</span>';
        }

        $metricHtml = '<span class="text-xs font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">'.e($row->metricLabel).'</span>';

        if ($row->status === 'no_target') {
            $statusBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-400 whitespace-nowrap">لم يُحدد مستهدف</span>';
            if ($canManageTargets && ! $isGroup) {
                $statusBadge .= ' <a href="/app/targets" class="text-xs text-primary-600 hover:underline mr-1 whitespace-nowrap">(تحديد)</a>';
            }

            return [
                '_is_group' => $isGroup,
                'branch' => $branchHtml,
                'metric' => $metricHtml,
                'target_month' => '—',
                'target_to_date' => '—',
                'actual_to_date' => $this->formatMetricValue($metric, $row->actualToDate),
                'achievement_to_date' => '—',
                'gap' => '—',
                'projected' => '—',
                'projected_achievement' => '—',
                'required_daily' => '—',
                'current_daily' => $this->formatMetricValue($metric, $row->currentDailyAverage),
                'status' => $statusBadge,
            ];
        }

        // Value formatting
        $targetMonthStr = $this->formatMetricValue($metric, $row->targetMonth);
        $targetToDateStr = $this->formatMetricValue($metric, $row->targetToDate);
        $actualToDateStr = $this->formatMetricValue($metric, $row->actualToDate);
        $gapStr = $this->formatMetricValue($metric, $row->gap !== null ? abs($row->gap) : null);
        $projectedStr = $this->formatMetricValue($metric, $row->projected);

        if ($metric === 'food_cost_pct') {
            $achievementStr = $row->status === 'within'
                ? '<span class="text-emerald-600 dark:text-emerald-400 font-semibold whitespace-nowrap">ضمن السقف</span>'
                : '<span class="text-rose-600 dark:text-rose-400 font-semibold whitespace-nowrap">تجاوز السقف</span>';
            $projectedAchStr = '—';
            $requiredDailyStr = '—';
            $currentDailyStr = '—';
        } else {
            $achPercent = $row->achievementToDate !== null ? round($row->achievementToDate * 100, 1) : 0.0;
            $achClass = $achPercent >= 100.0
                ? 'text-emerald-600 dark:text-emerald-400 font-semibold'
                : ($achPercent >= 90.0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-rose-600 dark:text-rose-400 font-semibold');

            $achievementStr = "<span class='{$achClass} whitespace-nowrap'><span class='inline-block dir-ltr'>".Format::percent($row->achievementToDate).'</span></span>';
            $projectedAchStr = "<span class='whitespace-nowrap'><span class='inline-block dir-ltr'>".Format::percent($row->projectedAchievement).'</span></span>';
            $requiredDailyStr = $this->formatMetricValue($metric, $row->requiredDailyAverage);
            $currentDailyStr = $this->formatMetricValue($metric, $row->currentDailyAverage);
        }

        $statusBadge = match ($row->status) {
            'on_track' => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-400 whitespace-nowrap">✓ على المسار</span>',
            'at_risk' => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-400 whitespace-nowrap">⚠️ معرض للخطر</span>',
            'behind' => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-400 whitespace-nowrap">✕ متأخر</span>',
            'within' => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-400 whitespace-nowrap">✓ ضمن السقف</span>',
            'above' => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-400 whitespace-nowrap">✕ تجاوز السقف</span>',
            default => '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-400 whitespace-nowrap">لم يُحدد مستهدف</span>',
        };

        return [
            '_is_group' => $isGroup,
            'branch' => $branchHtml,
            'metric' => $metricHtml,
            'target_month' => $targetMonthStr,
            'target_to_date' => $targetToDateStr,
            'actual_to_date' => $actualToDateStr,
            'achievement_to_date' => $achievementStr,
            'gap' => $gapStr,
            'projected' => $projectedStr,
            'projected_achievement' => $projectedAchStr,
            'required_daily' => $requiredDailyStr,
            'current_daily' => $currentDailyStr,
            'status' => $statusBadge,
        ];
    }

    private function formatMetricValue(string $metric, ?float $val): string
    {
        if ($val === null) {
            return '—';
        }

        $formatted = match ($metric) {
            'food_cost_pct' => Format::percent($val),
            'orders' => Format::number($val),
            default => Format::money($val),
        };

        return "<span class=\"whitespace-nowrap font-medium\">{$formatted}</span>";
    }
}
