<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Ai\Tools\Concerns\CachesToolResults;
use App\Ai\Tools\Concerns\ExtractsKpiValues;
use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Domain\Metrics\AllocatedCost;
use App\Domain\Metrics\Kpi;
use App\Domain\Metrics\MetricsQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final class GetKpis implements Tool
{
    use CachesToolResults, ExtractsKpiValues, FormatsToolResult, UsesReportFilters;

    public function __construct(
        public readonly User $user,
    ) {}

    public function name(): string
    {
        return 'get_kpis';
    }

    public function description(): string
    {
        return 'Get key performance indicator totals (K01-K25), comparison totals, growth deltas, and optional per-branch breakdown for accessible branches.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->reportFilterSchema($schema), [
            'kpis' => $schema->array()
                ->items($schema->string())
                ->description('List of requested K-codes (e.g. ["K01", "K06", "K14"]) or aliases. Defaults to core KPIs when omitted.'),
            'by_branch' => $schema->boolean()
                ->description('Whether to return KPI values broken down by branch.'),
            'channel' => $schema->string()
                ->description('Optional sales channel (note: channel filter is not supported by MetricsQuery).'),
        ]);
    }

    public function handle(Request $request): string
    {
        try {
            return $this->cached($this->name(), $request->all(), function () use ($request): string {
                return $this->execute($request);
            });
        } catch (AuthorizationException $e) {
            return $this->formatError('forbidden_branch', 'ليس لديك صلاحية للوصول إلى هذا الفرع أو البيانات المطلوبة.');
        } catch (ValidationException $e) {
            return $this->formatError('validation_error', 'بيانات غير صالحة.', ['errors' => $e->errors()]);
        } catch (Throwable $e) {
            report($e);

            return $this->formatError('internal_error', 'حدث خطأ غير متوقع أثناء تجهيز البيانات.');
        }
    }

    protected function execute(Request $request): string
    {
        $rules = array_merge($this->reportFilterRules(), [
            'kpis' => ['sometimes', 'nullable', 'array'],
            'kpis.*' => ['string'],
            'by_branch' => ['sometimes', 'nullable', 'boolean'],
            'channel' => ['sometimes', 'nullable', 'string'],
        ]);

        /** @var array<string, mixed> $args */
        $args = $request->validate($rules);

        $filters = $this->reportFiltersFromArgs($args);
        $query = MetricsQuery::for($this->user, $filters);

        /** @var list<int> $branchIds */
        $branchIds = $query->branchIds();

        /** @var list<string>|null $requestedKpis */
        $requestedKpis = isset($args['kpis']) && is_array($args['kpis']) ? array_values($args['kpis']) : null;
        $kpiCodes = $this->resolveKpiCodes($requestedKpis);

        $totals = $query->totals();
        $compTotals = $query->comparisonTotals();

        $allocatedLabor = AllocatedCost::labor($branchIds, $filters->period);
        $compAllocatedLabor = $filters->comparison !== null
            ? AllocatedCost::labor($branchIds, $filters->comparison)
            : null;

        $formattedTotals = [];
        foreach ($kpiCodes as $code) {
            $curr = $this->extractKpi($code, $totals, $allocatedLabor);
            $prev = $compTotals !== null ? $this->extractKpi($code, $compTotals, $compAllocatedLabor) : null;
            $growth = Kpi::growth($curr, $prev);

            $formattedTotals[$code] = [
                'value' => $this->formatMetricNumber($code, $curr),
                'comparison_value' => $this->formatMetricNumber($code, $prev),
                'growth' => $growth !== null ? round($growth, 4) : null,
            ];
        }

        $byBranchMetrics = null;
        $includeByBranch = (bool) ($args['by_branch'] ?? false);

        if ($includeByBranch && ! empty($branchIds)) {
            $byBranchValues = $query->byBranch();
            $compByBranchValues = $filters->comparison !== null
                ? $query->byBranch($filters->comparison)
                : [];

            $byBranchMetrics = [];
            foreach ($branchIds as $bId) {
                $branchValues = $byBranchValues[$bId] ?? null;
                $compBranchValues = $compByBranchValues[$bId] ?? null;

                $branchAllocatedLabor = AllocatedCost::labor([$bId], $filters->period);
                $compBranchAllocatedLabor = $filters->comparison !== null
                    ? AllocatedCost::labor([$bId], $filters->comparison)
                    : null;

                $branchKpis = [];
                foreach ($kpiCodes as $code) {
                    $curr = $branchValues !== null ? $this->extractKpi($code, $branchValues, $branchAllocatedLabor) : null;
                    $prev = $compBranchValues !== null ? $this->extractKpi($code, $compBranchValues, $compBranchAllocatedLabor) : null;
                    $growth = Kpi::growth($curr, $prev);

                    $branchKpis[$code] = [
                        'value' => $this->formatMetricNumber($code, $curr),
                        'comparison_value' => $this->formatMetricNumber($code, $prev),
                        'growth' => $growth !== null ? round($growth, 4) : null,
                    ];
                }

                $byBranchMetrics[$bId] = $branchKpis;
            }
        }

        $branchMap = $this->resolveBranchNames($query, $branchIds);
        $completeness = $this->buildCompleteness($query, $filters->period, $branchMap);

        $definitions = [];
        foreach ($kpiCodes as $code) {
            if (isset(self::KPI_DEFINITIONS[$code])) {
                $definitions[$code] = self::KPI_DEFINITIONS[$code];
            }
        }

        $notes = [];
        if (! empty($args['channel'])) {
            $notes[] = 'channel filter not supported here';
        }

        $metricsPayload = [
            'totals' => $formattedTotals,
        ];
        if ($byBranchMetrics !== null) {
            $metricsPayload['by_branch'] = $byBranchMetrics;
        }

        return $this->formatEnvelope(
            period: $filters->period,
            comparison: $filters->comparison,
            branchMap: $branchMap,
            metrics: $metricsPayload,
            completeness: $completeness,
            definitions: $definitions,
            notes: $notes,
            preset: $filters->preset,
            comparisonMode: $filters->comparisonMode,
        );
    }
}
