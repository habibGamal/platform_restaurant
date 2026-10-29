<?php

namespace App\Jobs;

use App\Domain\Metrics\Contracts\BranchDayAggregator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched by ingestion for every dirty (branch, business_date):
 *   RecomputeBranchDay::dispatch($branchId, '2026-10-29')->delay(now()->addSeconds(30));
 * Unique per branch/date so bursts collapse into one run.
 */
class RecomputeBranchDay implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function __construct(
        public int $branchId,
        public string $businessDate,
    ) {
        $this->onQueue('aggregate');
    }

    public function uniqueId(): string
    {
        return $this->branchId.':'.$this->businessDate;
    }

    public function handle(BranchDayAggregator $aggregator): void
    {
        $aggregator->recompute($this->branchId, $this->businessDate);
    }
}
