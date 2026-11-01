<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Ai\Tools\Concerns\CachesToolResults;
use App\Ai\Tools\Concerns\ExtractsKpiValues;
use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Ai\Tools\Metrics\QueryMetrics\DimensionNameResolver;
use App\Ai\Tools\Metrics\QueryMetrics\QueryMetricsRegistry;
use App\Ai\Tools\Metrics\QueryMetrics\TableMatchResolver;
use App\Domain\Metrics\MetricsQuery;
use App\Models\User;
use App\Support\Weekday;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final class QueryMetrics implements Tool
{
    use CachesToolResults, ExtractsKpiValues, FormatsToolResult, UsesReportFilters;

    public function __construct(
        public readonly User $user,
    ) {}

    public function name(): string
    {
        return 'query_metrics';
    }

    public function description(): string
    {
        return 'Query whitelisted metrics and aggregates across branches, products, time dimensions, expenses, purchases, or shifts with strict scoping and group-by combinations.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->reportFilterSchema($schema), [
            'measures' => $schema->array()
                ->items($schema->string())
                ->description('List of whitelisted metric names to compute (e.g. ["net_sales", "orders_count", "gross_profit", "qty_sold"]).'),
            'group_by' => $schema->array()
                ->items($schema->string())
                ->description('Optional grouping dimensions (max 2) from whitelist: branch, restaurant, date, week, month, weekday, hour, product, category, channel, payment_method, expense_category, supplier, shift, cashier.'),
            'order_by' => $schema->string()
                ->description('Optional measure name from the requested measures to sort by (e.g. "net_sales" or "net_sales desc").'),
            'order_direction' => $schema->string()
                ->enum(['asc', 'desc'])
                ->description('Optional sort direction (asc or desc). Defaults to desc.'),
            'limit' => $schema->integer()
                ->description('Maximum number of rows to return (1-50, defaults to 20).'),
            'channel' => $schema->string()
                ->description('Optional sales channel filter.'),
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
        $registry = new QueryMetricsRegistry;
        $whitelistedMeasures = $registry->metricNames();
        $whitelistedGroupBy = $registry->dimensionNames();

        $rules = array_merge($this->reportFilterRules(), [
            'measures' => ['required', 'array', 'min:1'],
            'measures.*' => ['string', Rule::in($whitelistedMeasures)],
            'group_by' => ['sometimes', 'nullable', 'array', 'max:2'],
            'group_by.*' => ['string', Rule::in($whitelistedGroupBy)],
            'order_by' => ['sometimes', 'nullable', 'string'],
            'order_direction' => ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'channel' => ['sometimes', 'nullable', 'string'],
        ]);

        /** @var array<string, mixed> $args */
        $args = $request->validate($rules);

        /** @var list<string> $measures */
        $measures = array_values(array_unique((array) $args['measures']));

        /** @var list<string> $groupBy */
        $groupBy = isset($args['group_by']) && is_array($args['group_by'])
            ? array_values(array_unique($args['group_by']))
            : [];

        // Parse order_by and order_direction
        $rawOrderBy = $args['order_by'] ?? null;
        $orderDirection = strtolower((string) ($args['order_direction'] ?? 'desc'));
        if (! in_array($orderDirection, ['asc', 'desc'], true)) {
            $orderDirection = 'desc';
        }

        $orderBy = null;
        if (is_string($rawOrderBy) && trim($rawOrderBy) !== '') {
            $trimmed = trim($rawOrderBy);
            if (str_contains($trimmed, ' ')) {
                $parts = preg_split('/\s+/', $trimmed);
                if ($parts !== false && count($parts) >= 2) {
                    $orderBy = $parts[0];
                    $dir = strtolower($parts[1]);
                    if (in_array($dir, ['asc', 'desc'], true)) {
                        $orderDirection = $dir;
                    }
                } else {
                    $orderBy = $trimmed;
                }
            } else {
                $orderBy = $trimmed;
            }
        }

        // Validate that order_by is one of the requested measures
        if ($orderBy !== null && ! in_array($orderBy, $measures, true)) {
            throw ValidationException::withMessages([
                'order_by' => ['The order_by field must be one of the selected measures: ['.implode(', ', $measures).'].'],
            ]);
        }

        // Resolve aggregate table
        $tableResolver = new TableMatchResolver($registry);
        $table = $tableResolver->resolveTable($measures, $groupBy);

        if ($table === null) {
            return $this->formatError(
                'unsupported_combination',
                'المقاييس وأبعاد التجميع المحددة غير مدعومة معاً في جدول تجميعي واحد.',
                [
                    'supported' => $tableResolver->supportedCombinationsList(),
                ]
            );
        }

        $filters = $this->reportFiltersFromArgs($args);
        $query = MetricsQuery::for($this->user, $filters);

        /** @var list<int> $branchIds */
        $branchIds = $query->branchIds();

        /** @var Builder $builder */
        $builder = $query->scoped($table, $filters->period);

        // Build select and group by clauses
        /** @var list<literal-string> $selectRawExpressions */
        $selectRawExpressions = [];
        /** @var list<literal-string> $groupByExpressions */
        $groupByExpressions = [];

        foreach ($groupBy as $dimName) {
            $dim = $registry->getDimension($dimName);
            if ($dim === null) {
                continue;
            }

            $expr = $dim->getExpression($table);
            if ($expr === null) {
                continue;
            }

            if ($dim->name === 'product') {
                $selectRawExpressions[] = 'COALESCE(product_ref, product_uuid) as product_key';
                $selectRawExpressions[] = 'MAX(product_ref) as product_ref';
                $selectRawExpressions[] = 'MAX(product_uuid) as product_uuid';
                $groupByExpressions[] = 'COALESCE(product_ref, product_uuid)';
            } elseif ($dim->name === 'date') {
                $selectRawExpressions[] = 'business_date as date';
                $groupByExpressions[] = 'business_date';
            } elseif ($dim->name === 'expense_category') {
                $selectRawExpressions[] = 'category as expense_category';
                $groupByExpressions[] = 'category';
            } elseif ($dim->name === 'week') {
                $selectRawExpressions[] = "{$expr} as week";
                $groupByExpressions[] = $expr;
            } elseif ($dim->name === 'month') {
                $selectRawExpressions[] = "{$expr} as month";
                $groupByExpressions[] = $expr;
            } else {
                $outputKey = $dim->outputKey;
                $selectRawExpressions[] = "{$expr} as {$outputKey}";
                $groupByExpressions[] = $expr;
            }
        }

        foreach ($measures as $measureName) {
            $metric = $registry->getMetric($measureName);
            if ($metric === null) {
                continue;
            }

            $impl = $metric->getImplementation($table);
            if ($impl === null) {
                continue;
            }

            $mName = $metric->name;
            $selectRawExpressions[] = "{$impl->sqlExpression} as {$mName}";

            if ($impl->numeratorExpression !== null && $impl->denominatorExpression !== null) {
                $selectRawExpressions[] = "({$impl->numeratorExpression}) as _{$mName}_num";
                $selectRawExpressions[] = "({$impl->denominatorExpression}) as _{$mName}_den";
            }
        }

        foreach ($selectRawExpressions as $rawExpr) {
            $builder->selectRaw($rawExpr);
        }

        foreach ($groupByExpressions as $expr) {
            $builder->groupBy(DB::raw($expr));
        }

        // Apply ordering
        if ($orderBy !== null) {
            $orderMetric = $registry->getMetric($orderBy);
            $orderImpl = $orderMetric?->getImplementation($table);
            if ($orderImpl !== null) {
                $builder->orderBy(DB::raw($orderImpl->sqlExpression), $orderDirection);
            }
        } elseif (! empty($groupBy)) {
            $chronological = array_intersect($groupBy, ['date', 'week', 'month', 'weekday', 'hour']);
            if (! empty($chronological)) {
                $firstChrono = reset($chronological);
                $dim = $registry->getDimension($firstChrono);
                $expr = $dim?->getExpression($table);
                if ($expr !== null) {
                    $builder->orderBy(DB::raw($expr), 'asc');
                }
            } else {
                $firstMeasure = $measures[0];
                $mMetric = $registry->getMetric($firstMeasure);
                $mImpl = $mMetric?->getImplementation($table);
                if ($mImpl !== null) {
                    $builder->orderBy(DB::raw($mImpl->sqlExpression), 'desc');
                }
            }
        }

        $limit = isset($args['limit']) ? min(50, max(1, (int) $args['limit'])) : 20;
        $builder->limit($limit);

        /** @var Collection<int, \stdClass> $rawResults */
        $rawResults = $builder->get();

        $branchMap = $this->resolveBranchNames($query, $branchIds);
        $orgId = (int) ($this->user->organization_id ?? 0);

        $nameResolver = new DimensionNameResolver;
        $resolvedRows = $nameResolver->resolve(
            $rawResults,
            $groupBy,
            $orgId,
            $branchIds,
            $branchMap,
        );

        $rows = [];
        foreach ($resolvedRows as $rawRow) {
            /** @var array<string, mixed> $row */
            $row = [];

            // Add dimension outputs
            foreach ($groupBy as $dimName) {
                $dim = $registry->getDimension($dimName);
                if ($dim === null) {
                    continue;
                }

                $outputKey = $dim->outputKey;
                if (isset($rawRow->{$outputKey})) {
                    $val = $rawRow->{$outputKey};
                    if ($dimName === 'branch') {
                        $row['branch_id'] = (int) $val;
                        $row['branch_name'] = (string) ($rawRow->branch_name ?? "فرع {$val}");
                    } elseif ($dimName === 'restaurant') {
                        $row['restaurant_id'] = (int) $val;
                        $row['restaurant_name'] = (string) ($rawRow->restaurant_name ?? "مطعم {$val}");
                    } elseif ($dimName === 'product') {
                        $row['product_key'] = (string) $val;
                        $row['product_name'] = (string) ($rawRow->product_name ?? $val);
                        if (isset($rawRow->product_ref) && $rawRow->product_ref !== '') {
                            $row['product_ref'] = (string) $rawRow->product_ref;
                        }
                    } elseif ($dimName === 'category') {
                        $row['category_uuid'] = (string) $val;
                        $row['category_name'] = (string) ($rawRow->category_name ?? $val);
                    } elseif ($dimName === 'supplier') {
                        $row['supplier_uuid'] = (string) $val;
                        $row['supplier_name'] = (string) ($rawRow->supplier_name ?? $val);
                    } elseif ($dimName === 'cashier') {
                        $row['cashier_uuid'] = (string) $val;
                        $row['cashier_name'] = (string) ($rawRow->cashier_name ?? $val);
                    } elseif ($dimName === 'weekday') {
                        $row['weekday'] = (int) $val;
                        $row['weekday_name'] = (string) ($rawRow->weekday_name ?? Weekday::label((int) $val));
                    } elseif ($dimName === 'hour') {
                        $row['hour'] = (int) $val;
                    } else {
                        $row[$outputKey] = (string) $val;
                    }
                }
            }

            // Add measure values
            foreach ($measures as $measureName) {
                $metric = $registry->getMetric($measureName);
                $impl = $metric?->getImplementation($table);

                $val = null;
                if ($impl !== null && $impl->numeratorExpression !== null && $impl->denominatorExpression !== null) {
                    $num = isset($rawRow->{"_{$measureName}_num"}) ? (float) $rawRow->{"_{$measureName}_num"} : null;
                    $den = isset($rawRow->{"_{$measureName}_den"}) ? (float) $rawRow->{"_{$measureName}_den"} : null;
                    if ($den !== null && $den != 0.0 && $num !== null) {
                        $val = $impl->isRatio ? round($num / $den, 4) : round($num / $den, 2);
                    } else {
                        $val = null;
                    }
                } elseif (isset($rawRow->{$measureName})) {
                    $rawVal = $rawRow->{$measureName};
                    $val = (float) $rawVal;
                }

                $row[$measureName] = $this->formatMetricNumber($measureName, $val);
            }

            $rows[] = $row;
        }

        $definitions = [];
        foreach ($measures as $measureName) {
            $metric = $registry->getMetric($measureName);
            if ($metric === null) {
                continue;
            }

            if ($metric->kpiCode !== null && isset(self::KPI_DEFINITIONS[$metric->kpiCode])) {
                $definitions[$measureName] = self::KPI_DEFINITIONS[$metric->kpiCode];
            } elseif ($metric->label !== null) {
                $definitions[$measureName] = $metric->label;
            }
        }

        $completeness = $this->buildCompleteness($query, $filters->period, $branchMap);

        $notes = [];
        if (! empty($args['channel'])) {
            $notes[] = 'channel filter not supported here';
        }

        $metricsPayload = [
            'table' => $table,
            'group_by' => $groupBy,
            'measures' => $measures,
            'rows' => $rows,
            'row_count' => count($rows),
        ];

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
