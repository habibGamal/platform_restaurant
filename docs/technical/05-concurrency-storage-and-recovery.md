# 05. Concurrency, Distributed Leases, Fencing, Outbox, and Recovery

This document details the distributed systems mechanics implemented in `platform-restaurants`, including batch idempotency, version guards, worker leases, fencing tokens, the durable dirty date outbox, and automated crash recovery.

---

## 1. Idempotency Architecture

The ingestion pipeline enforces idempotency at two independent levels:

```
[ Incoming HTTP Batch ]
          |
          v
+--------------------------------------------------------+
| 1. Batch-Level Idempotency Check (Pessimistic Lock)    |
|    - Table: ingest_batches                             |
|    - Condition: (branch_id, batch_uuid) exists         |
+--------------------------------------------------------+
     |                                 |
 [ Exists ]                      [ New Batch ]
     |                                 |
     v                                 v
 Return HTTP 409 Conflict        Validate, write raw batch to disk
 with cached result payload      (storage/app/private/ingest/...),
 (No duplicate queue jobs)       create batch record, and
                                 dispatch ProcessIngestBatch
                                       |
                                       v
                     +-----------------------------------+
                     | 2. Document-Level Version Guard   |
                     |    - Tables: wh_*, dim_*          |
                     |    - Condition:                   |
                     |      source_version >= version    |
                     +-----------------------------------+
                          |                 |
                      [ True ]          [ False ]
                          |                 |
                          v                 v
                     Skip document      Apply upsert/delete,
                     (HandleResult::    update source_version,
                      skipped())        record dirty dates
```

### 1. Batch Idempotency ([`IngestBatchController`](../../app/Http/Controllers/Api/V1/IngestBatchController.php))
Before checking documents, the controller acquires an exclusive row lock on the branch:
```php
Branch::where('id', $branch->id)->lockForUpdate()->first();
```
It queries `ingest_batches` for an existing record matching `(branch_id, batch_uuid)`.
- If found, it reads the stored `result` JSON column and immediately returns `HTTP 409 Conflict`.
- If the previous batch was still pending or failed, it ensures the existing batch is redispatched.
- The existing raw payload is reused; an unfinished batch can be redispatched, so multiple job dispatches are possible. Locks and fencing protect processing rather than promising exactly-once dispatch.

### 2. Document-Level Version Guard ([`AbstractDocumentHandler`](../../app/Domain/Ingestion/Handlers/Concerns/AbstractDocumentHandler.php))
Each parent warehouse table (`wh_orders`, `wh_shifts`, `wh_expenses`, etc.) and dimension table (`dim_products`, `dim_categories`, etc.) possesses a unique index on `(branch_id, uuid)` and tracks `source_version`.
- The POS supplies `version` as the primary key of its local `sync_outbox` sequence.
- When processing document mutations:
  ```php
  if ($existing !== null && (int) $existing->getAttribute('source_version') >= $version) {
      return HandleResult::skipped();
  }
  ```
- Any mutation arriving out of order or replayed from an earlier outbox dump is discarded safely without corrupting newer state.

---

## 2. Upserts, Soft-Deletes, and Child Row Replacement

Warehouse handlers extend [`AbstractDocumentHandler`](../../app/Domain/Ingestion/Handlers/Concerns/AbstractDocumentHandler.php), implementing unified lifecycle semantics:

### Upsert Operations (`op: 'upsert'`)
1. Detects date changes: Extracts `oldBusinessDate` from the existing record and `newBusinessDate` from the payload. If the date moved (e.g. order edited across day boundaries), both dates are marked dirty.
2. Updates parent: Sets `organization_id`, `branch_id`, `uuid`, `source_version`, `ingested_at = now()`, and `deleted_at = null`.
3. Replaces child collections: Child tables (`wh_order_items`, `wh_payments`, `wh_purchase_items`, `wh_inventory_day_rows`) do not use primary keys matching the POS. Instead, the handler executes `replaceChildren()`:
   - Wipes previous child rows: `ChildModel::where('branch_id', $branch->id)->where('parent_uuid', $parent->uuid)->delete();`
   - Re-inserts all current child rows from the payload array in bulk.

### Delete Operations (`op: 'delete'`)
1. If the parent does not exist in the warehouse, the operation is skipped.
2. If it exists, the handler marks the parent's business date dirty.
3. Sets `deleted_at = now()`, updates `source_version = $version`, and updates `ingested_at = now()`.
4. Mirrored warehouse queries always enforce `whereNull('deleted_at')`.

---

## 3. Worker Leases, Concurrency, and Fencing Tokens

To prevent split-brain processing, duplicate warehouse updates, and race conditions between concurrent workers, [`ProcessIngestBatch`](../../app/Jobs/ProcessIngestBatch.php) enforces a three-tier concurrency guard:

### 1. Branch Queue Overlapping Middleware
```php
public function middleware(): array
{
    return [
        (new WithoutOverlapping("branch-{$branchId}"))
            ->expireAfter($retryAfter)
            ->releaseAfter(10),
    ];
}
```
Only one queue worker is permitted to process batches for a given branch at any instant. Competing jobs are released back to the queue after 10 seconds.

### 2. Distributed Leases & Atomic Claims ([`ProcessIngestBatch`](../../app/Jobs/ProcessIngestBatch.php))
When `ProcessIngestBatch` boots:
1. Opens a database transaction and executes `IngestBatch::where('id', $batchId)->lockForUpdate()`.
2. Inspects `status`: If `processed` or `failed`, aborts.
3. If already `processing`, checks `lease_expires_at`:
   - If `lease_expires_at > now()`, an active worker is still executing; the job aborts.
   - If `lease_expires_at <= now()`, the previous worker stalled or died; the lease is seized.
4. Generates a new `claim_token = Str::uuid()` and increments `fencing_token = fencing_token + 1`.
5. Sets `lease_expires_at = now() + 30 seconds`.

### 3. Fencing Token Verification & Abort
During document processing, every document handler execution runs inside a transaction that locks the batch record and validates the fencing token:
```php
$currentBatch = IngestBatch::where('id', $this->batchId)->lockForUpdate()->first();

if (! $currentBatch
    || $currentBatch->claim_token !== $claimToken
    || (int) $currentBatch->fencing_token !== $fencingToken
) {
    throw new FencedWorkerException("Worker fenced: lost ownership of batch {$this->batchId}.");
}

// Renew lease on heartbeat
$currentBatch->update([
    'lease_expires_at' => now()->addSeconds($leaseDuration),
    'heartbeat_at' => now(),
]);
```
- If a slow worker is superseded by the recovery command, its fencing token becomes stale.
- The slow worker immediately throws [`FencedWorkerException`](../../app/Domain/Ingestion/Exceptions/FencedWorkerException.php) and halts.
- Stale workers do not update sequences, do not mark batches processed, and do not trigger aggregate rebuilds.

---

## 4. Durable Dirty Dates Outbox (`ingest_dirty_dates`)

Directly dispatching aggregate recomputations from the ingestion controller or handler introduces data loss if queue dispatch fails or workers crash. `platform-restaurants` implements a transaction-coupled outbox pattern via [`DirtyDateOutbox`](../../app/Domain/Ingestion/Support/DirtyDateOutbox.php):

### Schema (`database/migrations/2026_11_02_000007_create_ingest_dirty_dates_table.php`)
- `ingest_batch_id`: Foreign key to `ingest_batches`.
- `branch_id`: Branch ID.
- `business_date`: Calendar date (`Y-m-d`) affected by the mutation.
- `generation`: Monotonic integer incremented on each mutation to guard against out-of-order dispatches.
- `status`: `pending` $\to$ `dispatched` $\to$ `processed` (or `failed`).
- `dispatch_attempts`: Counter for recovery retry attempts.

### Lifecycle Sequence

```
Database Transaction (Wh* Table Mutation)
  |
  +--> Warehouse Rows Upserted / Deleted
  |
  +--> DirtyDateOutbox::record($batchId, $branch, $dirtyDates)
       (Atomically upserts into ingest_dirty_dates with status='pending', generation=generation+1)
  |
Transaction Committed
  |
ProcessIngestBatch Finishes Envelope
  |
  +--> Atomically verifies fencing_token & marks batch 'processed'
  +--> Updates branches.last_seq = max(last_seq, seq_to)
  |
For each dirty date in ingest_dirty_dates:
  |
  +--> DirtyDateOutbox::markDispatched(batchId, branchId, date, generation)
  +--> RecomputeBranchDay::dispatch($branchId, $dateStr)->delay(now()->addSeconds(30))
```

The 30-second delay on `RecomputeBranchDay` allows bursts of successive batch arrivals (e.g. backfills or rapid shift closeouts) to collapse into a single aggregated recomputation via `ShouldBeUniqueUntilProcessing`.

---

## 5. Aggregation Fencing & Serialized Locking

Aggregation recomputation runs via [`RecomputeBranchDay`](../../app/Jobs/RecomputeBranchDay.php) on the `aggregate` queue.

### Recompute Lock & Fencing Tokens
1. **Cache Lock**: [`DirtyDateOutbox::acquireRecomputeLock()`](../../app/Domain/Ingestion/Support/DirtyDateOutbox.php) acquires an atomic cache lock `recompute-lock:{branch}:{date}` for 60 seconds. If locked, the job releases itself with a 5-second delay.
2. **Recompute Fence**: Queries table `ingest_recompute_fences` for `(branch_id, business_date)`:
   - Increments `in_progress_token = in_progress_token + 1`.
   - Passes `fenceToken` to [`BranchDayRebuilder::recompute()`](../../app/Domain/Metrics/Aggregation/BranchDayRebuilder.php).
3. **Database Guard**: Inside `BranchDayRebuilder`, row locking verifies:
   ```php
   if ($fenceToken < (int) $fence->committed_token) {
       throw new FencedWorkerException('Recompute worker has been superseded.');
   }
   ```
4. **Completion & Snapshot**:
   - `BranchDayRebuilder` deletes old `agg_*` rows for that `(branch_id, date)` and inserts newly calculated aggregates.
   - Commits `committed_token = fenceToken`.
   - Calls `DirtyDateOutbox::markRecomputed($branchId, $date, $snapshot)`.
   - If new dirty dates arrived while the recomputation was running (`hasPendingUpdates()`), dispatches another delayed `RecomputeBranchDay` job.

---

## 6. Automated Crash Recovery Command (`ingest:recover`)

Invoked automatically every minute via Laravel Scheduler ([`routes/console.php`](../../routes/console.php)) and executable manually via Artisan:

```bash
php artisan ingest:recover --limit=100 --stale-seconds=90
# Add --sync to recompute dirty dates synchronously; batches are still dispatched.
```

### Recovery Logic ([`RecoverIngestBatchesCommand`](../../app/Console/Commands/RecoverIngestBatchesCommand.php))
1. **Unstarted Received Batches**: Queries `ingest_batches` with `status = 'received'` and `received_at <= now() - 30 seconds`. Dispatches `ProcessIngestBatch` with the previously parsed accepted indices.
2. **Stalled Processing Batches**: Queries `ingest_batches` with `status = 'processing'` where `lease_expires_at <= now()`, or where the lease is null and the update is older than the stale cutoff. The cutoff comes from `--stale-seconds` or the active queue connection's retry interval. Dispatches recovery `ProcessIngestBatch`.
3. **Stalled Dirty Date Outbox**: Calls [`DirtyDateOutbox::recover()`](../../app/Domain/Ingestion/Support/DirtyDateOutbox.php):
   - Queries `ingest_dirty_dates` where `status IN ('pending', 'failed')` or `(status = 'dispatched' AND updated_at <= staleCutoff)`.
   - Dispatches `RecomputeBranchDay::dispatch($branchId, $businessDate)` for each abandoned date.
   - If `--sync` is passed, executes recomputations synchronously in the current CLI process.
