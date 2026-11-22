<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\Profitability;

use App\Domain\Metrics\Kpi;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProfitabilityReport;
use App\Domain\Metrics\Reports\ProfitabilityReport\BranchProfitabilityData;
use App\Domain\Metrics\Reports\ProfitabilityReport\ProfitabilityMetricComparison;
use App\Domain\Metrics\Reports\ProfitabilityReport\ProfitabilityReportData;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;
use App\Support\BranchColor;
use App\Support\Format;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ProfitabilityPlMatrixWidget extends BaseReportViewWidget
{
    protected string $view = 'filament.app.reports.profitability.pl-matrix-widget';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    protected ?string $heading = 'قائمة الدخل ومصفوفة الأرباح (P&L Matrix)';

    protected bool $hasTableSearchAndSorting = false;

    public string $laborBasis = 'paid';

    public bool $excludeInventoryVariance = false;

    public bool $showComparisonDelta = true;

    public function setLaborBasis(string $basis): void
    {
        if (in_array($basis, ['paid', 'allocated'], true)) {
            $this->laborBasis = $basis;
        }
    }

    public function toggleExcludeInventoryVariance(): void
    {
        $this->excludeInventoryVariance = ! $this->excludeInventoryVariance;
    }

    public function toggleComparisonDelta(): void
    {
        $this->showComparisonDelta = ! $this->showComparisonDelta;
    }

    protected function getReportData(): ProfitabilityReportData
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            $filters = $this->reportFilters();

            return new ProfitabilityReportData(
                period: $filters->period,
                comparisonPeriod: $filters->comparison,
                laborBasis: $this->laborBasis,
                excludeInventoryVariance: $this->excludeInventoryVariance,
                branches: [],
                group: null,
            );
        }

        $query = MetricsQuery::for($user, $this->reportFilters());

        return ProfitabilityReport::for(
            query: $query,
            laborBasis: $this->laborBasis,
            excludeInventoryVariance: $this->excludeInventoryVariance,
        )->data();
    }

    /**
     * @return array<string, string>
     */
    protected function getColumns(): array
    {
        $reportData = $this->getReportData();
        $columns = ['metric' => 'البند'];

        foreach ($reportData->branches as $branchId => $branch) {
            $columns["branch_{$branchId}"] = $branch->branchName;
        }

        if ($reportData->group !== null) {
            $columns['group'] = 'الإجمالي (المجموعة)';
        }

        return $columns;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    protected function getRows(): array
    {
        $reportData = $this->getReportData();
        $definitions = $this->getRowDefinitions();
        $rows = [];

        foreach ($definitions as $key => $def) {
            $row = [
                '_key' => $key,
                'metric' => $def['label'],
            ];

            foreach ($reportData->branches as $branchId => $branch) {
                $row["branch_{$branchId}"] = $this->formatCellHtml($branch, $key, $def);
            }

            if ($reportData->group !== null) {
                $row['group'] = $this->formatCellHtml($reportData->group, $key, $def);
            }

            $rows[$key] = $row;
        }

        return $rows;
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading(null)
            ->recordClasses(function (array $record): ?string {
                $rowKey = $record['_key'] ?? null;
                $definitions = $this->getRowDefinitions();
                $def = $rowKey ? ($definitions[$rowKey] ?? null) : null;
                if (! $def) {
                    return null;
                }

                $isHighlight = (bool) ($def['is_highlight'] ?? false);
                $isNetProfit = (bool) ($def['is_net_profit'] ?? false);

                if ($isNetProfit) {
                    return 'bg-primary-50/50 dark:bg-primary-950/30 font-bold border-t-2 border-primary-500/40';
                }

                if ($isHighlight) {
                    return 'bg-emerald-50/40 dark:bg-emerald-950/20 font-semibold';
                }

                return null;
            });
    }

    /**
     * @return array<Column>
     */
    protected function getTableColumnsForRecords(): array
    {
        $reportData = $this->getReportData();
        $columns = [
            TextColumn::make('metric')
                ->label('البند')
                ->html()
                ->extraHeaderAttributes(['class' => 'whitespace-nowrap min-w-[200px]'])
                ->extraCellAttributes(['class' => 'whitespace-nowrap font-medium text-gray-900 dark:text-gray-100']),
        ];

        foreach ($reportData->branches as $branchId => $branch) {
            $color = BranchColor::for($branchId);
            $warning = $branch->hasNoExpenses ? ' <span class="text-[10px] text-danger-600 bg-danger-50 dark:bg-danger-950/50 px-1 rounded" title="لا توجد مصروفات مسجلة">⚠️</span>' : '';
            $headerHtml = new HtmlString(
                '<div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: '.$color.'"></span><span>'.e($branch->branchName).'</span>'.$warning.'</div>'
            );

            $columns[] = TextColumn::make("branch_{$branchId}")
                ->label($headerHtml)
                ->html()
                ->placeholder('—')
                ->extraHeaderAttributes(['class' => 'whitespace-nowrap min-w-[150px]'])
                ->extraCellAttributes(['class' => 'whitespace-nowrap']);
        }

        if ($reportData->group !== null) {
            $columns[] = TextColumn::make('group')
                ->label('الإجمالي (المجموعة)')
                ->html()
                ->placeholder('—')
                ->extraHeaderAttributes(['class' => 'whitespace-nowrap min-w-[160px] font-bold'])
                ->extraCellAttributes(['class' => 'whitespace-nowrap font-semibold bg-gray-50/60 dark:bg-white/5']);
        }

        return $columns;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getRowDefinitions(): array
    {
        return [
            'sales' => [
                'label' => 'المبيعات (K06)',
                'is_money' => true,
                'is_percent' => false,
                'is_highlight' => false,
                'kpi_key' => 'sales',
            ],
            'cogs' => [
                'label' => 'تكلفة المبيعات (K08)',
                'is_money' => true,
                'is_percent' => false,
                'is_highlight' => false,
                'kpi_key' => 'cogs',
            ],
            'grossProfit' => [
                'label' => 'مجمل الربح (K09)',
                'is_money' => true,
                'is_percent' => false,
                'is_highlight' => true,
                'kpi_key' => 'grossProfit',
            ],
            'grossMargin' => [
                'label' => 'هامش مجمل الربح (K10)',
                'is_money' => false,
                'is_percent' => true,
                'is_highlight' => false,
                'kpi_key' => 'grossMargin',
            ],
            'laborCost' => [
                'label' => 'تكلفة العمالة (K11a)',
                'is_money' => true,
                'is_percent' => false,
                'is_highlight' => false,
                'kpi_key' => 'laborCost',
            ],
            'rentCost' => [
                'label' => 'الإيجار',
                'is_money' => true,
                'is_percent' => false,
                'is_highlight' => false,
                'kpi_key' => 'rentCost',
            ],
            'otherExpenses' => [
                'label' => 'مصروفات أخرى',
                'is_money' => true,
                'is_percent' => false,
                'is_highlight' => false,
                'kpi_key' => 'otherExpenses',
            ],
            'wasteCost' => [
                'label' => 'الهالك (K12)',
                'is_money' => true,
                'is_percent' => false,
                'is_highlight' => false,
                'kpi_key' => 'wasteCost',
            ],
            'inventoryVarianceCost' => [
                'label' => 'عجز الجرد (K13)',
                'is_money' => true,
                'is_percent' => false,
                'is_highlight' => false,
                'kpi_key' => 'inventoryVarianceCost',
            ],
            'netProfit' => [
                'label' => 'صافي الربح (K14)',
                'is_money' => true,
                'is_percent' => false,
                'is_highlight' => true,
                'is_net_profit' => true,
                'kpi_key' => 'netProfit',
            ],
            'netMargin' => [
                'label' => 'هامش صافي الربح (K15)',
                'is_money' => false,
                'is_percent' => true,
                'is_highlight' => true,
                'kpi_key' => 'netMargin',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private function formatCellHtml(BranchProfitabilityData $branch, string $key, array $def): string
    {
        $cell = $this->formatCell($branch, $key, $def);
        if ($cell['primary'] === '—') {
            return '<span class="text-gray-400 dark:text-gray-600">—</span>';
        }

        $html = '<div class="flex flex-col"><span class="text-sm font-medium">'.e($cell['primary']).'</span>';

        $subItems = [];
        if ($cell['pctOfSales'] !== null) {
            $subItems[] = '<span>'.e($cell['pctOfSales']).'</span>';
        }
        if ($cell['delta'] !== null) {
            $subItems[] = '<span class="'.e($cell['deltaColor']).' font-semibold">'.e($cell['delta']).'</span>';
        }

        if (! empty($subItems)) {
            $html .= '<div class="flex items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">'.implode('', $subItems).'</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $reportData = $this->getReportData();

        return [
            'heading' => $this->getHeading(),
            'reportData' => $reportData,
            'rowDefinitions' => $this->getRowDefinitions(),
            'laborBasis' => $this->laborBasis,
            'excludeInventoryVariance' => $this->excludeInventoryVariance,
            'showComparisonDelta' => $this->showComparisonDelta,
            'hasComparison' => $reportData->comparisonPeriod !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $def
     * @return array{primary: string, pctOfSales: ?string, delta: ?string, deltaColor: string}
     */
    public function formatCell(BranchProfitabilityData $branch, string $key, array $def): array
    {
        $val = $branch->{$key} ?? null;
        $isMoney = (bool) ($def['is_money'] ?? false);
        $kpiKey = (string) ($def['kpi_key'] ?? $key);

        if ($val === null) {
            return [
                'primary' => '—',
                'pctOfSales' => null,
                'delta' => null,
                'deltaColor' => 'gray',
            ];
        }

        $primary = $isMoney ? Format::money((float) $val, 2) : Format::percent((float) $val, 1);

        $pctOfSales = null;
        if ($isMoney && $branch->sales > 0) {
            $pctOfSales = Format::percent((float) $val / $branch->sales, 1);
        }

        $delta = null;
        $deltaColor = 'gray';

        if ($this->showComparisonDelta && isset($branch->comparisons[$kpiKey])) {
            /** @var ProfitabilityMetricComparison $comp */
            $comp = $branch->comparisons[$kpiKey];
            if ($comp->growth !== null) {
                $growthFormatted = Format::growth($comp->growth);
                $delta = $growthFormatted;
                $deltaVal = $comp->delta ?? ($comp->current - $comp->comparison);

                if ($deltaVal != 0.0) {
                    $isGood = Kpi::isGoodDirection($kpiKey, (float) $deltaVal);
                    $deltaColor = $isGood ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400';
                }
            } elseif ($comp->marginPointsDelta !== null && $comp->marginPointsDelta != 0.0) {
                $points = round($comp->marginPointsDelta * 100, 1);
                $sign = $points > 0 ? '+' : '';
                $delta = "{$sign}{$points}%";
                $isGood = $points > 0;
                $deltaColor = $isGood ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400';
            }
        }

        return [
            'primary' => $primary,
            'pctOfSales' => $pctOfSales,
            'delta' => $delta,
            'deltaColor' => $deltaColor,
        ];
    }
}
