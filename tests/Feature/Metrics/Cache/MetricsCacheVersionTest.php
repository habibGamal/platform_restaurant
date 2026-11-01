<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics\Cache;

use App\Domain\Metrics\Cache\MetricsCacheVersion;
use App\Events\BranchDayUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MetricsCacheVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_version_is_zero(): void
    {
        $orgId = 101;
        Cache::forget("metrics:version:{$orgId}");

        $this->assertSame(0, MetricsCacheVersion::for($orgId));
    }

    public function test_bump_increments_version(): void
    {
        $orgId = 102;
        Cache::forget("metrics:version:{$orgId}");

        $this->assertSame(0, MetricsCacheVersion::for($orgId));

        MetricsCacheVersion::bump($orgId);
        $this->assertSame(1, MetricsCacheVersion::for($orgId));

        MetricsCacheVersion::bump($orgId);
        $this->assertSame(2, MetricsCacheVersion::for($orgId));
    }

    public function test_bump_is_scoped_per_organization(): void
    {
        $org1 = 201;
        $org2 = 202;
        Cache::forget("metrics:version:{$org1}");
        Cache::forget("metrics:version:{$org2}");

        MetricsCacheVersion::bump($org1);

        $this->assertSame(1, MetricsCacheVersion::for($org1));
        $this->assertSame(0, MetricsCacheVersion::for($org2));
    }

    public function test_works_with_array_and_database_stores(): void
    {
        foreach (['array', 'database'] as $store) {
            $orgId = 300 + ($store === 'array' ? 1 : 2);
            $cache = Cache::store($store);
            $cache->forget("metrics:version:{$orgId}");

            // Directly verify key name used
            $this->assertSame(0, (int) $cache->get("metrics:version:{$orgId}", 0));

            // Set default store temporarily to test MetricsCacheVersion
            config(['cache.default' => $store]);
            $this->assertSame(0, MetricsCacheVersion::for($orgId));
            MetricsCacheVersion::bump($orgId);
            $this->assertSame(1, MetricsCacheVersion::for($orgId));

            MetricsCacheVersion::bump($orgId);
            $this->assertSame(2, MetricsCacheVersion::for($orgId));
        }
    }

    public function test_branch_day_updated_event_bumps_version_via_listener(): void
    {
        $orgId = 401;
        Cache::forget("metrics:version:{$orgId}");
        $this->assertSame(0, MetricsCacheVersion::for($orgId));

        BranchDayUpdated::dispatch($orgId, 10, '2026-10-15');

        $this->assertSame(1, MetricsCacheVersion::for($orgId));

        BranchDayUpdated::dispatch($orgId, 11, '2026-10-16');

        $this->assertSame(2, MetricsCacheVersion::for($orgId));
    }
}
