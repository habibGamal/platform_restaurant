<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Metrics\Cache\MetricsCacheVersion;
use App\Events\BranchDayUpdated;

final class BumpMetricsCacheVersion
{
    public function handle(BranchDayUpdated $event): void
    {
        MetricsCacheVersion::bump($event->organizationId);
    }
}
