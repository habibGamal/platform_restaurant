<?php

namespace App\Jobs;

use App\Domain\Ingestion\Exceptions\FencedWorkerException;
use App\Domain\Ingestion\Support\DirtyDateOutbox;
use App\Domain\Metrics\Aggregation\BranchDayRebuilder;
use App\Domain\Metrics\Contracts\BranchDayAggregator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Dispatched by ingestion for every dirty (branch, business_date):
 *   RecomputeBranchDay::dispatch($branchId, '2026-10-29')->delay(now()->addSeconds(30));
 * Unique per branch/date so bursts collapse into one run.
 */
class RecomputeBranchDay implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        $lockToken = DirtyDateOutbox::acquireRecomputeLock($this->branchId, $this->businessDate);
        if ($lockToken === null) {
            $this->release(5);

            return;
        }

        try {
            $snapshot = DirtyDateOutbox::getSnapshot($this->branchId, $this->businessDate);

            $fenceToken = DirtyDateOutbox::claimRecomputeFence($this->branchId, $this->businessDate);

            if ($aggregator instanceof BranchDayRebuilder) {
                $aggregator->recompute($this->branchId, $this->businessDate, $fenceToken);
            } else {
                $aggregator->recompute($this->branchId, $this->businessDate);
            }

            DirtyDateOutbox::markRecomputed($this->branchId, $this->businessDate, $snapshot);

            if (DirtyDateOutbox::hasPendingUpdates($this->branchId, $this->businessDate)) {
                try {
                    static::dispatch($this->branchId, $this->businessDate)->delay(now()->addSeconds(30));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        } catch (FencedWorkerException $e) {
            report($e);

            return;
        } finally {
            DirtyDateOutbox::releaseRecomputeLock($this->branchId, $this->businessDate, $lockToken);
        }
    }
}
