<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics\Cache;

use App\Domain\Metrics\Cache\MetricsCache;
use App\Domain\Metrics\KpiValues;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\ReportFilters;
use App\Events\BranchDayUpdated;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class MetricsCacheTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    private CarbonImmutable $fixedToday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = TwoBranchesTwoWeeks::create();
        $this->fixedToday = CarbonImmutable::parse('2026-10-16');
        config(['metrics.cache_ttl' => 60]);
        config(['metrics.cache_live_ttl' => 15]);
    }

    public function test_cache_hit_returns_same_result_without_requerying(): void
    {
        $filters = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-09',
        ], $this->fixedToday);

        $q = MetricsQuery::for($this->fixture->owner, $filters);

        // 1st call: Miss, executes query
        $result1 = MetricsCache::remember($q, 'totals', [], fn () => $q->totals());
        $this->assertInstanceOf(KpiValues::class, $result1);
        $this->assertGreaterThan(0, $result1->orders_count);

        // Count queries on second call
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // 2nd call: Hit, should return identical result with 0 database queries
        $result2 = MetricsCache::remember($q, 'totals', [], fn () => $q->totals());

        $this->assertSame(0, $queryCount, 'Cache hit should not trigger any database queries.');
        $this->assertInstanceOf(KpiValues::class, $result2);
        $this->assertSame($result1->orders_count, $result2->orders_count);
        $this->assertSame($result1->net_sales, $result2->net_sales);
        $this->assertSame($result1->gross_profit, $result2->gross_profit);
        $this->assertSame($result1->net_profit, $result2->net_profit);
    }

    public function test_different_users_with_different_access_produce_different_keys_and_do_not_share_cache(): void
    {
        $filters = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-09',
        ], $this->fixedToday);

        // Owner sees Branch A and Branch B
        $qOwner = MetricsQuery::for($this->fixture->owner, $filters);
        // Manager A sees only Branch A
        $qManager = MetricsQuery::for($this->fixture->managerA, $filters);

        $keyOwner = MetricsCache::key($qOwner, 'totals');
        $keyManager = MetricsCache::key($qManager, 'totals');

        $this->assertNotSame($keyOwner, $keyManager, 'Two users with different branch access must never share cache keys.');

        $ownerTotals = MetricsCache::remember($qOwner, 'totals', [], fn () => $qOwner->totals());
        $managerTotals = MetricsCache::remember($qManager, 'totals', [], fn () => $qManager->totals());

        // Manager A's totals only reflect Branch A, while Owner reflects Branch A + Branch B
        $this->assertNotSame($ownerTotals->orders_count, $managerTotals->orders_count);
        $this->assertGreaterThan($managerTotals->orders_count, $ownerTotals->orders_count);
    }

    public function test_filter_change_causes_cache_miss(): void
    {
        $filtersWeek1 = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-09',
        ], $this->fixedToday);

        $filtersWeek2 = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-10',
            'to' => '2026-10-16',
        ], $this->fixedToday);

        $q1 = MetricsQuery::for($this->fixture->owner, $filtersWeek1);
        $q2 = MetricsQuery::for($this->fixture->owner, $filtersWeek2);

        $key1 = MetricsCache::key($q1, 'totals');
        $key2 = MetricsCache::key($q2, 'totals');

        $this->assertNotSame($key1, $key2);

        $computeCount = 0;
        MetricsCache::remember($q1, 'totals', [], function () use (&$computeCount, $q1) {
            $computeCount++;

            return $q1->totals();
        });

        MetricsCache::remember($q2, 'totals', [], function () use (&$computeCount, $q2) {
            $computeCount++;

            return $q2->totals();
        });

        $this->assertSame(2, $computeCount, 'Changing filters must cause a cache miss and recomputation.');
    }

    public function test_branch_day_updated_invalidates_cache_via_version_bump(): void
    {
        $filters = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-09',
        ], $this->fixedToday);

        $q = MetricsQuery::for($this->fixture->owner, $filters);
        $keyBefore = MetricsCache::key($q, 'totals');

        $computeCalls = 0;
        $res1 = MetricsCache::remember($q, 'totals', [], function () use (&$computeCalls, $q) {
            $computeCalls++;

            return $q->totals();
        });
        $this->assertSame(1, $computeCalls);

        // 2nd call: cache hit - compute closure is not executed
        $callsBefore = $computeCalls;
        $res2 = MetricsCache::remember($q, 'totals', [], function () use (&$computeCalls, $q) {
            $computeCalls++;

            return $q->totals();
        });
        $this->assertSame($callsBefore, $computeCalls);

        // Dispatch BranchDayUpdated for the fixture organization
        BranchDayUpdated::dispatch(
            $this->fixture->organization->id,
            $this->fixture->branchA->id,
            '2026-10-05'
        );

        // Key must have changed due to bumped version
        $keyAfter = MetricsCache::key($q, 'totals');
        $this->assertNotSame($keyBefore, $keyAfter);

        // 3rd call: cache miss because version was bumped
        $res3 = MetricsCache::remember($q, 'totals', [], function () use (&$computeCalls, $q) {
            $computeCalls++;

            return $q->totals();
        });
        $this->assertSame(2, $computeCalls);
    }

    public function test_ttl_zero_disables_cache(): void
    {
        config(['metrics.cache_ttl' => 0]);

        $filters = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-09',
        ], $this->fixedToday);

        $q = MetricsQuery::for($this->fixture->owner, $filters);

        $computeCalls = 0;
        MetricsCache::remember($q, 'totals', [], function () use (&$computeCalls, $q) {
            $computeCalls++;

            return $q->totals();
        });

        MetricsCache::remember($q, 'totals', [], function () use (&$computeCalls, $q) {
            $computeCalls++;

            return $q->totals();
        });

        $this->assertSame(2, $computeCalls, 'TTL 0 must disable caching and recompute on every call.');
    }

    public function test_ttl_for_distinguishes_live_vs_historical_periods(): void
    {
        config(['metrics.cache_ttl' => 60]);
        config(['metrics.cache_live_ttl' => 15]);

        // 1. Past historical period: full TTL (60s)
        $pastFilters = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2020-01-01',
            'to' => '2020-01-07',
        ], $this->fixedToday);
        $this->assertSame(60, MetricsCache::ttlFor($pastFilters));

        // 2. Preset today: live TTL (15s)
        $todayFilters = ReportFilters::fromArray(['preset' => 'today'], CarbonImmutable::today('Africa/Cairo'));
        $this->assertSame(15, MetricsCache::ttlFor($todayFilters));

        // 3. Hourly totals method: live TTL (15s)
        $this->assertSame(15, MetricsCache::ttlFor($pastFilters, 'hourlyTotals'));

        // 4. Live TTL set to 0 bypasses cache for live periods while keeping historical cached
        config(['metrics.cache_live_ttl' => 0]);
        $this->assertSame(0, MetricsCache::ttlFor($todayFilters));
        $this->assertSame(0, MetricsCache::ttlFor($pastFilters, 'hourlyTotals'));
        $this->assertSame(60, MetricsCache::ttlFor($pastFilters));
    }

    public function test_round_trip_for_all_query_methods(): void
    {
        $filters = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-09',
            'compare' => 'previous_period',
        ], $this->fixedToday);

        $q = MetricsQuery::for($this->fixture->owner, $filters);

        // A. totals()
        $totals = MetricsCache::remember($q, 'totals', [], fn () => $q->totals());
        $cachedTotals = MetricsCache::remember($q, 'totals', [], fn () => $q->totals());
        $this->assertInstanceOf(KpiValues::class, $cachedTotals);
        $this->assertSame($totals->orders_count, $cachedTotals->orders_count);
        $this->assertSame($totals->net_sales, $cachedTotals->net_sales);

        // B. byBranch()
        $byBranch = MetricsCache::remember($q, 'byBranch', [], fn () => $q->byBranch());
        $cachedByBranch = MetricsCache::remember($q, 'byBranch', [], fn () => $q->byBranch());
        $this->assertIsArray($cachedByBranch);
        $this->assertArrayHasKey($this->fixture->branchA->id, $cachedByBranch);
        $this->assertInstanceOf(KpiValues::class, $cachedByBranch[$this->fixture->branchA->id]);
        $this->assertSame(
            $byBranch[$this->fixture->branchA->id]->orders_count,
            $cachedByBranch[$this->fixture->branchA->id]->orders_count
        );

        // C. series() perBranch = false
        $series = MetricsCache::remember($q, 'series', [Granularity::day, null, false], fn () => $q->series(Granularity::day, null, false));
        $cachedSeries = MetricsCache::remember($q, 'series', [Granularity::day, null, false], fn () => $q->series(Granularity::day, null, false));
        $this->assertIsArray($cachedSeries);
        $this->assertArrayHasKey('2026-10-03', $cachedSeries);
        $this->assertInstanceOf(KpiValues::class, $cachedSeries['2026-10-03']);
        $this->assertSame($series['2026-10-03']->net_sales, $cachedSeries['2026-10-03']->net_sales);

        // D. series() perBranch = true
        $seriesBranch = MetricsCache::remember($q, 'series', [Granularity::day, null, true], fn () => $q->series(Granularity::day, null, true));
        $cachedSeriesBranch = MetricsCache::remember($q, 'series', [Granularity::day, null, true], fn () => $q->series(Granularity::day, null, true));
        $this->assertIsArray($cachedSeriesBranch);
        $this->assertArrayHasKey($this->fixture->branchA->id, $cachedSeriesBranch);
        $this->assertInstanceOf(KpiValues::class, $cachedSeriesBranch[$this->fixture->branchA->id]['2026-10-03']);
        $this->assertSame(
            $seriesBranch[$this->fixture->branchA->id]['2026-10-03']->net_sales,
            $cachedSeriesBranch[$this->fixture->branchA->id]['2026-10-03']->net_sales
        );

        // E. comparisonTotals() with comparison present
        $compTotals = MetricsCache::remember($q, 'comparisonTotals', [], fn () => $q->comparisonTotals());
        $cachedCompTotals = MetricsCache::remember($q, 'comparisonTotals', [], fn () => $q->comparisonTotals());
        $this->assertInstanceOf(KpiValues::class, $cachedCompTotals);
        $this->assertSame($compTotals->orders_count, $cachedCompTotals->orders_count);

        // F. comparisonTotals() with comparison null
        $filtersNoComp = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-09',
            'compare' => 'none',
        ], $this->fixedToday);
        $qNoComp = MetricsQuery::for($this->fixture->owner, $filtersNoComp);
        $nullComp = MetricsCache::remember($qNoComp, 'comparisonTotals', [], fn () => $qNoComp->comparisonTotals());
        $cachedNullComp = MetricsCache::remember($qNoComp, 'comparisonTotals', [], fn () => $qNoComp->comparisonTotals());
        $this->assertNull($nullComp);
        $this->assertNull($cachedNullComp);

        // G. hourlyTotals()
        $hourly = MetricsCache::remember($q, 'hourlyTotals', [$filters->period, '14:00'], fn () => $q->hourlyTotals($filters->period, '14:00'));
        $cachedHourly = MetricsCache::remember($q, 'hourlyTotals', [$filters->period, '14:00'], fn () => $q->hourlyTotals($filters->period, '14:00'));
        $this->assertIsArray($cachedHourly);
        $this->assertArrayHasKey('orders', $cachedHourly);
        $this->assertArrayHasKey('net_sales', $cachedHourly);
        $this->assertSame($hourly['orders'], $cachedHourly['orders']);
        $this->assertSame($hourly['net_sales'], $cachedHourly['net_sales']);
    }
}
