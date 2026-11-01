<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Ai\Tools\Concerns\CachesToolResults;
use App\Ai\Tools\Concerns\ExtractsKpiValues;
use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Domain\Metrics\KpiValues;
use App\Domain\Metrics\MetricsQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final class GetKpiSeries implements Tool
{
    use CachesToolResults, ExtractsKpiValues, FormatsToolResult, UsesReportFilters;

    public function __construct(
        public readonly User $user,
    ) {}

    public function name(): string
    {
        return 'get_kpi_series';
    }

    public function description(): string
    {
        return 'Get time series for key performance indicators (K01-K25) broken down into daily, weekly, or monthly buckets, optionally broken down per branch.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->reportFilterSchema($schema), [
            'kpis' => $schema->array()
                ->items($schema->string())
                ->description('List of requested K-codes (e.g. ["K01", "K06", "K14"]) or aliases. Defaults to sales and orders when omitted.'),
            'per_branch' => $schema->boolean()
                ->description('Whether to return the series broken down per branch.'),
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
            'per_branch' => ['sometimes', 'nullable', 'boolean'],
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

        $perBranch = (bool) ($args['per_branch'] ?? false);
        $granularity = $filters->granularity;

        /** @var ($perBranch is true ? array<int, array<string, KpiValues>> : array<string, KpiValues>) $rawSeries */
        $rawSeries = $query->series($granularity, $filters->period, $perBranch);

        $formattedSeries = [];
        if ($perBranch) {
            /** @var array<int, array<string, KpiValues>> $rawSeries */
            foreach ($rawSeries as $bId => $buckets) {
                $branchBuckets = [];
                foreach ($buckets as $dateBucket => $kpiValues) {
                    $bucketMetrics = [];
                    foreach ($kpiCodes as $code) {
                        $bucketMetrics[$code] = $this->formatMetricNumber($code, $this->extractKpi($code, $kpiValues));
                    }
                    $branchBuckets[$dateBucket] = $bucketMetrics;
                }
                $formattedSeries[$bId] = $branchBuckets;
            }
        } else {
            /** @var array<string, KpiValues> $rawSeries */
            foreach ($rawSeries as $dateBucket => $kpiValues) {
                $bucketMetrics = [];
                foreach ($kpiCodes as $code) {
                    $bucketMetrics[$code] = $this->formatMetricNumber($code, $this->extractKpi($code, $kpiValues));
                }
                $formattedSeries[$dateBucket] = $bucketMetrics;
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

        return $this->formatEnvelope(
            period: $filters->period,
            comparison: $filters->comparison,
            branchMap: $branchMap,
            metrics: [
                'granularity' => $granularity->value,
                'per_branch' => $perBranch,
                'series' => $formattedSeries,
            ],
            completeness: $completeness,
            definitions: $definitions,
            notes: $notes,
            preset: $filters->preset,
            comparisonMode: $filters->comparisonMode,
        );
    }
}
