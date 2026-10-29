<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Fired by the BranchDayAggregator after agg_* rows for (branch, business date) were rebuilt. */
class BranchDayUpdated
{
    use Dispatchable;

    public function __construct(
        public int $organizationId,
        public int $branchId,
        public string $businessDate,
    ) {}
}
