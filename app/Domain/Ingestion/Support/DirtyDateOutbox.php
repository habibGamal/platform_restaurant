<?php

namespace App\Domain\Ingestion\Support;

use App\Domain\Metrics\Aggregation\BranchDayRebuilder;
use App\Domain\Metrics\Contracts\BranchDayAggregator;
use App\Jobs\RecomputeBranchDay;
use App\Models\Branch;
use App\Models\IngestDirtyDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DirtyDateOutbox
{
    /**
     * Persist dirty dates atomically into the durable outbox table.
     * Must be called within the same database transaction as warehouse mutations.
     *
     * @param  list<string>  $dirtyDates
     */
    public static function record(int $batchId, Branch $branch, array $dirtyDates): void
    {
        if (empty($dirtyDates)) {
            return;
        }

        $now = Carbon::now()->toDateTimeString();
        $uniqueDates = array_values(array_unique(array_filter(array_map('strval', $dirtyDates))));

        foreach ($uniqueDates as $dateStr) {
            DB::table('ingest_dirty_dates')->upsert([
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->id,
                'ingest_batch_id' => $batchId,
                'business_date' => $dateStr,
                'generation' => 1,
                'status' => 'pending',
                'recomputed_at' => null,
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['ingest_batch_id', 'branch_id', 'business_date'], [
                'status' => 'pending',
                'recomputed_at' => null,
                'last_error' => null,
                'updated_at' => $now,
                'generation' => DB::raw('ingest_dirty_dates.generation + 1'),
            ]);
        }
    }

    /**
     * Retrieve all unique business dates recorded for a batch.
     *
     * @return list<string>
     */
    public static function getBatchDates(int $batchId): array
    {
        /** @var list<string> $dates */
        $dates = DB::table('ingest_dirty_dates')
            ->where('ingest_batch_id', $batchId)
            ->pluck('business_date')
            ->map(fn ($d): string => (string) $d)
            ->unique()
            ->values()
            ->all();

        return $dates;
    }

    /**
     * Mark outbox row as dispatched when job is queued.
     */
    public static function markDispatched(int $batchId, int $branchId, string $businessDate, ?int $generation = null): void
    {
        $query = DB::table('ingest_dirty_dates')
            ->where('ingest_batch_id', $batchId)
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->where('status', '!=', 'processed');

        if ($generation !== null) {
            $query->where('generation', $generation);
        }

        $query->update([
            'status' => 'dispatched',
            'dispatched_at' => Carbon::now(),
            'dispatch_attempts' => DB::raw('dispatch_attempts + 1'),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Mark outbox row as failed if queue dispatch failed.
     */
    public static function markFailed(int $batchId, int $branchId, string $businessDate, string $error, ?int $generation = null): void
    {
        $query = DB::table('ingest_dirty_dates')
            ->where('ingest_batch_id', $batchId)
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->where('status', '!=', 'processed');

        if ($generation !== null) {
            $query->where('generation', $generation);
        }

        $query->update([
            'status' => 'failed',
            'last_error' => substr($error, 0, 1000),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Capture snapshot of uncompleted dirty dates for branch and business date.
     *
     * @return array<int, int> Map of [id => generation]
     */
    public static function getSnapshot(int $branchId, string $businessDate): array
    {
        /** @var array<int, int> $snapshot */
        $snapshot = DB::table('ingest_dirty_dates')
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->where('status', '!=', 'processed')
            ->pluck('generation', 'id')
            ->map(fn ($gen): int => (int) $gen)
            ->all();

        return $snapshot;
    }

    /**
     * Mark outbox rows processed conditionally only if current generation matches captured snapshot.
     * Empty snapshot clears nothing.
     *
     * @param  array<int, int>  $snapshot  Map of [id => generation]
     */
    public static function markRecomputed(int $branchId, string $businessDate, array $snapshot): int
    {
        if (empty($snapshot)) {
            return 0;
        }

        $now = Carbon::now();

        $byGen = [];
        foreach ($snapshot as $id => $gen) {
            $byGen[(int) $gen][] = (int) $id;
        }

        return (int) DB::transaction(function () use ($branchId, $businessDate, $byGen, $now): int {
            return (int) DB::table('ingest_dirty_dates')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->where('status', '!=', 'processed')
                ->where(function ($query) use ($byGen): void {
                    foreach ($byGen as $gen => $ids) {
                        $query->orWhere(function ($sub) use ($gen, $ids): void {
                            $sub->whereIn('id', $ids)
                                ->where('generation', $gen);
                        });
                    }
                })
                ->update([
                    'status' => 'processed',
                    'recomputed_at' => $now,
                    'updated_at' => $now,
                ]);
        });
    }

    /**
     * Mark candidate snapshot rows as dispatched, only if generation matches snapshot.
     *
     * @param  array<int, int>  $snapshot  Map of [id => generation]
     */
    public static function markSnapshotDispatched(array $snapshot, int $branchId, string $businessDate, ?Carbon $now = null): int
    {
        if (empty($snapshot)) {
            return 0;
        }

        $now = $now ?? Carbon::now();

        $byGen = [];
        foreach ($snapshot as $id => $gen) {
            $byGen[(int) $gen][] = (int) $id;
        }

        return (int) DB::table('ingest_dirty_dates')
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->where('status', '!=', 'processed')
            ->where(function ($query) use ($byGen): void {
                foreach ($byGen as $gen => $ids) {
                    $query->orWhere(function ($sub) use ($gen, $ids): void {
                        $sub->whereIn('id', $ids)
                            ->where('generation', $gen);
                    });
                }
            })
            ->update([
                'status' => 'dispatched',
                'dispatched_at' => $now,
                'dispatch_attempts' => DB::raw('dispatch_attempts + 1'),
                'updated_at' => $now,
            ]);
    }

    /**
     * Mark candidate snapshot rows as failed, only if generation matches snapshot.
     *
     * @param  array<int, int>  $snapshot  Map of [id => generation]
     */
    public static function markSnapshotFailed(array $snapshot, int $branchId, string $businessDate, string $error, ?Carbon $now = null): int
    {
        if (empty($snapshot)) {
            return 0;
        }

        $now = $now ?? Carbon::now();

        $byGen = [];
        foreach ($snapshot as $id => $gen) {
            $byGen[(int) $gen][] = (int) $id;
        }

        return (int) DB::table('ingest_dirty_dates')
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->where('status', '!=', 'processed')
            ->where(function ($query) use ($byGen): void {
                foreach ($byGen as $gen => $ids) {
                    $query->orWhere(function ($sub) use ($gen, $ids): void {
                        $sub->whereIn('id', $ids)
                            ->where('generation', $gen);
                    });
                }
            })
            ->update([
                'status' => 'failed',
                'last_error' => substr($error, 0, 1000),
                'updated_at' => $now,
            ]);
    }

    /**
     * Atomically increment and return the recomputation fence token.
     */
    public static function claimRecomputeFence(int $branchId, string $businessDate): int
    {
        return DB::transaction(function () use ($branchId, $businessDate): int {
            $now = Carbon::now();
            $fence = DB::table('ingest_recompute_fences')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->lockForUpdate()
                ->first();

            $nextToken = $fence !== null ? ((int) $fence->fence_token + 1) : 1;

            DB::table('ingest_recompute_fences')->upsert([
                'branch_id' => $branchId,
                'business_date' => $businessDate,
                'fence_token' => $nextToken,
                'committed_token' => $fence !== null ? (int) $fence->committed_token : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['branch_id', 'business_date'], [
                'fence_token' => $nextToken,
                'updated_at' => $now,
            ]);

            return $nextToken;
        });
    }

    /**
     * Check if fence token is current (has not been superseded by a higher committed token).
     */
    public static function isFenceCurrent(int $branchId, string $businessDate, int $fenceToken): bool
    {
        $fence = DB::table('ingest_recompute_fences')
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->first(['fence_token', 'committed_token']);

        if ($fence === null) {
            return true;
        }

        return $fenceToken >= (int) $fence->committed_token;
    }

    /**
     * Commit fence token when recomputation succeeds.
     */
    public static function commitRecomputeFence(int $branchId, string $businessDate, int $fenceToken): bool
    {
        $now = Carbon::now();
        $updated = DB::table('ingest_recompute_fences')
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->where('committed_token', '<=', $fenceToken)
            ->update([
                'committed_token' => $fenceToken,
                'updated_at' => $now,
            ]);

        return $updated > 0;
    }

    /**
     * Acquire exclusive recomputation lease lock for branch and business date.
     */
    public static function acquireRecomputeLock(int $branchId, string $businessDate, int $leaseSeconds = 60): ?string
    {
        $lockToken = (string) Str::uuid();

        return DB::transaction(function () use ($branchId, $businessDate, $leaseSeconds, $lockToken): ?string {
            $now = Carbon::now();
            $fence = DB::table('ingest_recompute_fences')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->lockForUpdate()
                ->first();

            if ($fence !== null && $fence->locked_until !== null) {
                $lockedUntil = Carbon::parse($fence->locked_until);
                if ($lockedUntil->gt($now)) {
                    return null;
                }
            }

            $newLockedUntil = $now->copy()->addSeconds($leaseSeconds);

            DB::table('ingest_recompute_fences')->upsert([
                'branch_id' => $branchId,
                'business_date' => $businessDate,
                'fence_token' => $fence !== null ? (int) $fence->fence_token : 0,
                'committed_token' => $fence !== null ? (int) $fence->committed_token : 0,
                'lock_token' => $lockToken,
                'locked_until' => $newLockedUntil,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['branch_id', 'business_date'], [
                'lock_token' => $lockToken,
                'locked_until' => $newLockedUntil,
                'updated_at' => $now,
            ]);

            return $lockToken;
        });
    }

    /**
     * Release recomputation lease lock.
     */
    public static function releaseRecomputeLock(int $branchId, string $businessDate, ?string $lockToken = null): void
    {
        $query = DB::table('ingest_recompute_fences')
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate);

        if ($lockToken !== null) {
            $query->where('lock_token', $lockToken);
        }

        $query->update([
            'lock_token' => null,
            'locked_until' => null,
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Check if any uncompleted dirty dates exist for branch and date.
     */
    public static function hasPendingUpdates(int $branchId, string $businessDate): bool
    {
        return DB::table('ingest_dirty_dates')
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->where('status', '!=', 'processed')
            ->exists();
    }

    /**
     * Atomically recover pending or stale outbox entries with concurrency fencing.
     */
    public static function recover(
        int $limit,
        \DateTimeInterface $staleCutoff,
        bool $sync = false,
        ?BranchDayAggregator $aggregator = null
    ): int {
        $now = Carbon::now();
        $recoveredCount = 0;

        DB::transaction(function () use ($limit, $staleCutoff, $now, $sync, $aggregator, &$recoveredCount): void {
            /** @var Collection<int, IngestDirtyDate> $candidateDates */
            $candidateDates = IngestDirtyDate::query()
                ->where(function ($q) use ($staleCutoff): void {
                    // Case 1: Pending rows whose batch is already processed or failed
                    $q->where(function ($sub): void {
                        $sub->where('ingest_dirty_dates.status', 'pending')
                            ->whereExists(function ($bQuery): void {
                                $bQuery->select(DB::raw(1))
                                    ->from('ingest_batches')
                                    ->whereColumn('ingest_batches.id', 'ingest_dirty_dates.ingest_batch_id')
                                    ->whereIn('ingest_batches.status', ['processed', 'failed']);
                            });
                    })
                    // Case 2: Stale pending rows
                        ->orWhere(function ($sub) use ($staleCutoff): void {
                            $sub->where('ingest_dirty_dates.status', 'pending')
                                ->where('ingest_dirty_dates.created_at', '<=', $staleCutoff);
                        })
                    // Case 3: Failed dispatch or recompute
                        ->orWhere('ingest_dirty_dates.status', 'failed')
                    // Case 4: Stale dispatched rows
                        ->orWhere(function ($sub) use ($staleCutoff): void {
                            $sub->where('ingest_dirty_dates.status', 'dispatched')
                                ->where('ingest_dirty_dates.dispatched_at', '<', $staleCutoff);
                        });
                })
                ->lockForUpdate()
                ->orderBy('id')
                ->limit($limit)
                ->get();

            if ($candidateDates->isEmpty()) {
                return;
            }

            // Group candidates by branch_id and business_date (Y-m-d)
            $grouped = $candidateDates->groupBy(function (IngestDirtyDate $item): string {
                $date = $item->business_date;
                $dateStr = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;

                return $item->branch_id.':'.$dateStr;
            });

            foreach ($grouped as $groupKey => $items) {
                /** @var IngestDirtyDate $first */
                $first = $items->first();
                $branchId = (int) $first->branch_id;
                $date = $first->business_date;
                $businessDate = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
                /** @var list<int> $ids */
                $ids = $items->pluck('id')->all();
                if (empty($ids)) {
                    continue;
                }

                /** @var array<int, int> $snapshot */
                $snapshot = $items->pluck('generation', 'id')->map(fn ($g): int => (int) $g)->all();

                if ($sync && $aggregator !== null) {
                    $fenceToken = static::claimRecomputeFence($branchId, $businessDate);
                    try {
                        if ($aggregator instanceof BranchDayRebuilder) {
                            $aggregator->recompute($branchId, $businessDate, $fenceToken);
                        } else {
                            $aggregator->recompute($branchId, $businessDate);
                        }
                        $marked = static::markRecomputed($branchId, $businessDate, $snapshot);
                        if ($marked > 0) {
                            $recoveredCount++;
                        }
                    } catch (Throwable $e) {
                        static::markSnapshotFailed($snapshot, $branchId, $businessDate, $e->getMessage(), $now);
                        report($e);
                    }
                } else {
                    static::markSnapshotDispatched($snapshot, $branchId, $businessDate, $now);

                    try {
                        RecomputeBranchDay::dispatch($branchId, $businessDate)->delay(now()->addSeconds(30));
                        $recoveredCount++;
                    } catch (Throwable $e) {
                        static::markSnapshotFailed($snapshot, $branchId, $businessDate, $e->getMessage(), $now);
                        report($e);
                    }
                }
            }
        });

        return $recoveredCount;
    }
}
