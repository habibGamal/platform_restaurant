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
use App\Support\Format;

class ProfitabilityPlMatrixWidget extends BaseReportViewWidget
{
    protected string $view = 'filament.app.reports.profitability.pl-matrix-widget';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    protected ?string $heading = 'قائمة الدخل ومصفوفة الأرباح (P&L Matrix)';

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
            $columns['group'] = 'الإجمالي';
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
            $row = ['metric' => $def['label']];

            foreach ($reportData->branches as $branchId => $branch) {
                $row["branch_{$branchId}"] = $this->formatCellForContract($branch, $key, $def);
            }

            if ($reportData->group !== null) {
                $row['group'] = $this->formatCellForContract($reportData->group, $key, $def);
            }

            $rows[$key] = $row;
        }

        return $rows;
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
    private function formatCellForContract(BranchProfitabilityData $branch, string $key, array $def): string
    {
        $val = $branch->{$key} ?? null;
        if ($val === null) {
            return '—';
        }

        if ($def['is_money']) {
            return Format::money((float) $val, 2);
        }

        return Format::percent((float) $val, 1);
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
