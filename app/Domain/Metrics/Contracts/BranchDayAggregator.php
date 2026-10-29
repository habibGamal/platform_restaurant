<?php

namespace App\Domain\Metrics\Contracts;

/**
 * Rebuilds every agg_* row for one branch and one business date from the warehouse tables
 * (delete + insert, idempotent), then dispatches App\Events\BranchDayUpdated.
 * Implemented and bound by the aggregates task (App\Domain\Metrics\Aggregation\BranchDayRebuilder).
 */
interface BranchDayAggregator
{
    public function recompute(int $branchId, string $businessDate): void;
}
