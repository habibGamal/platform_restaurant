<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Cache;

use App\Domain\Metrics\KpiValues;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

final class MetricsCache
{
    /**
     * Cache and return the result of a MetricsQuery method computation.
     *
     * @param  array<mixed>  $args
     */
    public static function remember(MetricsQuery $q, string $method, array $args, Closure $compute): mixed
    {
        $org = $q->user->organization;
        $timezone = $org ? $org->timezone : 'Africa/Cairo';
        $ttl = self::ttlFor($q->filters, $method, $timezone);

        if ($ttl <= 0) {
            return $compute();
        }

        $key = self::key($q, $method, $args);

        $cached = Cache::remember($key, $ttl, function () use ($compute) {
            return self::dehydrate($compute());
        });

        return self::rehydrate($cached);
    }

    /**
     * Compute TTL (in seconds) based on whether the period includes live data (today / intra-day).
     */
    public static function ttlFor(ReportFilters $filters, ?string $method = null, ?string $timezone = null): int
    {
        $defaultTtl = (int) config('metrics.cache_ttl', 60);
        if ($defaultTtl <= 0) {
            return 0;
        }

        $liveTtl = (int) config('metrics.cache_live_ttl', 15);

        if ($method === 'hourlyTotals') {
            return max(0, $liveTtl);
        }

        $tz = $timezone ?? 'Africa/Cairo';
        $today = CarbonImmutable::now($tz)->startOfDay();

        if ($filters->preset === PeriodPreset::today || $filters->period->contains($today) || $filters->period->to >= $today) {
            return max(0, $liveTtl);
        }

        if ($filters->comparison !== null && ($filters->comparison->contains($today) || $filters->comparison->to >= $today)) {
            return max(0, $liveTtl);
        }

        return $defaultTtl;
    }

    /**
     * Generate the deterministic cache key for a MetricsQuery invocation.
     *
     * @param  array<mixed>  $args
     */
    public static function key(MetricsQuery $q, string $method, array $args = []): string
    {
        $orgId = (int) ($q->user->organization_id ?? 0);
        $version = MetricsCacheVersion::for($orgId);

        $branchIds = $q->branchIds();
        sort($branchIds);

        $payload = [
            'org_id' => $orgId,
            'version' => $version,
            'branch_ids' => $branchIds,
            'filters' => $q->filters->toArray(),
            'period' => $q->filters->period->toArray(),
            'comparison' => $q->filters->comparison?->toArray(),
            'method' => $method,
            'args' => self::normalizeArg($args),
        ];

        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return "metrics:q:{$orgId}:v{$version}:{$method}:{$hash}";
    }

    /**
     * Dehydrate complex objects (like KpiValues and null) into safe serializable primitives/arrays.
     */
    public static function dehydrate(mixed $value): mixed
    {
        if ($value === null) {
            return ['__type' => 'null'];
        }

        if ($value instanceof KpiValues) {
            return [
                '__type' => 'kpi_values',
                'data' => get_object_vars($value),
            ];
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::dehydrate($v);
            }

            return $result;
        }

        return $value;
    }

    /**
     * Rehydrate cached primitives/arrays back into domain objects (e.g. KpiValues).
     */
    public static function rehydrate(mixed $value): mixed
    {
        if (is_array($value)) {
            if (isset($value['__type'])) {
                if ($value['__type'] === 'null') {
                    return null;
                }

                if ($value['__type'] === 'kpi_values' && isset($value['data']) && is_array($value['data'])) {
                    return KpiValues::fromRow($value['data']);
                }
            }

            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::rehydrate($v);
            }

            return $result;
        }

        return $value;
    }

    /**
     * Recursively normalize arguments for consistent hashing.
     */
    public static function normalizeArg(mixed $arg): mixed
    {
        if ($arg === null || is_scalar($arg)) {
            return $arg;
        }

        if ($arg instanceof UnitEnum) {
            return property_exists($arg, 'value') ? $arg->value : $arg->name;
        }

        if ($arg instanceof Period) {
            return $arg->toArray();
        }

        if ($arg instanceof CarbonInterface) {
            return $arg->toISOString();
        }

        if ($arg instanceof ReportFilters) {
            return $arg->toArray();
        }

        if (is_array($arg)) {
            $result = [];
            foreach ($arg as $k => $v) {
                $result[$k] = self::normalizeArg($v);
            }

            return $result;
        }

        if (is_object($arg) && method_exists($arg, 'toArray')) {
            return $arg->toArray();
        }

        return (string) $arg;
    }
}
