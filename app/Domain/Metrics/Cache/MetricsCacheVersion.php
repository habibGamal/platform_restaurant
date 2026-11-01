<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Cache;

use Illuminate\Support\Facades\Cache;

final class MetricsCacheVersion
{
    public static function for(int $organizationId): int
    {
        return (int) Cache::get("metrics:version:{$organizationId}", 0);
    }

    public static function bump(int $organizationId): void
    {
        $key = "metrics:version:{$organizationId}";

        if (! Cache::has($key)) {
            Cache::forever($key, 1);

            return;
        }

        $incremented = Cache::increment($key);
        if ($incremented === false) {
            Cache::forever($key, ((int) Cache::get($key, 0)) + 1);
        }
    }
}
