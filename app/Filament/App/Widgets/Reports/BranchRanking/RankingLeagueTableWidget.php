<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\BranchRanking;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\BranchRankingReport;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;
use Filament\Tables\Table;

class RankingLeagueTableWidget extends BaseReportViewWidget
{
    protected static bool $isLazy = true;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.app.reports.branch-ranking.league-table-widget';

    private const array KPI_LABELS = [
        'net_margin' => 'هامش الربح',
        'sales_growth' => 'نمو المبيعات',
        'target_achievement' => 'تحقيق المستهدف',
        'food_cost_pct' => 'تكلفة الخامات',
        'waste_pct' => 'الهالك',
        'avg_ticket_growth' => 'نمو متوسط الطلب',
        'cancel_rate' => 'نسبة الإلغاء',
    ];

    public function table(Table $table): Table
    {
        return parent::table($table)->heading(null)
            ->emptyStateHeading('لا توجد فروع مؤهلة للترتيب في هذه الفترة')
            ->recordClasses(fn (array $record): ?string => ($record['_is_own'] ?? false) ? 'bg-primary-50/20 dark:bg-primary-950/20' : null);
    }

    protected function getColumns(): array
    {
        $weights = $this->getViewData()['weights'];
        $columns = ['rank' => 'الترتيب', 'movement' => 'التغيير', 'branch' => 'الفرع', 'score' => 'النتيجة'];
        foreach (self::KPI_LABELS as $key => $label) {
            $columns[$key] = $label.' ('.($weights[$key] ?? BranchRankingReport::DEFAULT_WEIGHTS[$key]).'%)';
        }

        return $columns;
    }

    protected function getRows(): array
    {
        $rows = [];
        foreach ($this->getViewData()['rankedBranches'] as $branch) {
            $movementColor = match ($branch->rankDeltaColor) {
                'success' => 'text-emerald-600 dark:text-emerald-400',
                'danger' => 'text-rose-600 dark:text-rose-400',
                default => 'text-gray-500',
            };
            $name = '<span class="font-semibold">'.e($branch->branchName).'</span>';
            if ($branch->isCurrentManagerBranch) {
                $name .= ' <span class="text-xs text-primary-600 dark:text-primary-400">فرعك</span>';
            }
            if ($branch->hasUnavailableKpi) {
                $name .= ' <span title="تمت إعادة توزيع أوزان المؤشرات غير المتوفرة لهذا الفرع">ⓘ</span>';
            }
            $row = [
                '_is_own' => $branch->isCurrentManagerBranch,
                'rank' => $branch->rank === null ? '—' : '#'.$branch->rank,
                'movement' => '<span class="'.$movementColor.'">'.e($branch->rankDeltaFormatted).'</span>',
                'branch' => $name,
                'score' => $this->scoreBadge($branch->overallScore),
            ];
            foreach (self::KPI_LABELS as $key => $label) {
                $kpi = $branch->kpiScores[$key] ?? null;
                $row[$key] = ($kpi === null || $kpi->rawValue === null) ? '—'
                    : '<div>'.e($kpi->formattedValue).'</div><div class="mt-1">'.$this->scoreBadge($kpi->score).'</div>';
            }
            $rows['branch_'.$branch->branchId] = $row;
        }

        return $rows;
    }

    private function scoreBadge(?float $score): string
    {
        if ($score === null) {
            return '—';
        }
        $color = match (true) {
            $score >= 70 => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
            $score >= 40 => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
            default => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
        };

        return '<span class="inline-block rounded px-2 py-1 text-xs font-bold '.$color.'">'.number_format($score, 1).'</span>';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [
                'rankedBranches' => [],
                'excludedBranches' => [],
                'singleBranchMode' => true,
                'weights' => BranchRankingReport::DEFAULT_WEIGHTS,
            ];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = BranchRankingReport::for($query);
        $data = $report->data();

        return [
            'rankedBranches' => $data->rankedBranches,
            'excludedBranches' => $data->excludedBranches,
            'singleBranchMode' => $data->singleBranchMode,
            'weights' => $data->weights,
        ];
    }
}
