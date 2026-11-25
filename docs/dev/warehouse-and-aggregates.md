# Warehouse & Aggregation Engine

This page covers how `ProcessIngestBatch` safely writes mirrored POS records into `wh_*` / `dim_*` tables, tracks dirty dates through a durable outbox, rebuilds `agg_*` read models via `BranchDayRebuilder`, and reconciles against POS day summaries.

---

## 1. Concurrency, Leases & Fencing (`ProcessIngestBatch`)

`ProcessIngestBatch` (`app/Jobs/ProcessIngestBatch.php`, queue `ingest`, `timeout = 60`, `tries = 0`, `maxExceptions = 3`) uses three layers of concurrency protection:

1. **Per-branch queue serialization:** `WithoutOverlapping("branch-{$branchId}")` releases competing jobs after 10 seconds with `expireAfter` matching the active queue connection's `retry_after` (`ProcessIngestBatch::getRetryAfterForConnection()`). Because Laravel counts middleware releases as attempts, `$tries = 0` allows unlimited lock-wait releases while `$maxExceptions = 3` bounds real failures.
2. **Database batch lease:** `claimBatch()` locks the `ingest_batches` row (`lockForUpdate()`), checks `status` and `lease_expires_at` (`config('ingest.lease_duration_seconds', 30)`), generates a fresh `claim_token` UUID, and increments `fencing_token`.
3. **Per-document fencing check:** Before handling each document (and before recording rejections or marking the batch `processed`), the job re-locks the batch row and asserts `claim_token` and `fencing_token`. A superseded worker throws `FencedWorkerException` and aborts without writing dirty dates or updating `branches.last_seq`.

---

## 2. Document Handling & Version Guard (`AbstractDocumentHandler`)

- **Version guard:** Parent warehouse and dimension tables enforce `UNIQUE(branch_id, uuid)` and `source_version`. Any document whose `version <= stored source_version` returns `HandleResult::skipped()`.
- **Upserts & child replacement:** On `op = 'upsert'`, `OrderHandler` computes `cogs = Σ(quantity * unit_cost)`, `items_discount = Σ item_discount`, `hour_of_day`, and `weekday` (`0=Sat..6=Fri` of `business_date`). `OrderReturnHandler` computes `returned_cogs`. `StocktakingHandler` computes `variance_cost = Σ(stock_quantity - real_quantity) * price`. Child collections (`wh_order_items`, `wh_payments`, `wh_order_return_items`, `wh_refunds`, `wh_purchase_items`, `wh_waste_items`, `wh_stocktaking_items`, `wh_inventory_day_rows`) are deleted and re-inserted atomically for that `(branch_id, parent_uuid)`.
- **Soft deletes:** On `op = 'delete'`, existing parent rows get `deleted_at = now()` and bumped `source_version`; queries filter `whereNull('deleted_at')`.
- **Dirty dates:** If an order's `business_date` changes (e.g. moved across shifts), both old and new `business_date` values are marked dirty. Dimension changes dirty nothing except `expense_type.category` changes, which dirty every day of the current and previous month for that branch.
- **Menu intelligence trigger:** When `product`, `category`, `recipe`, or back-dated (`< today`) `order`/`order_return`/`waste` documents are applied, `ProcessIngestBatch` dispatches `ClassifyItemRoles` (5-min delay) and/or `BuildMenuIntelligence` (debounced by `config('menu.intelligence.build_delay_minutes', 15)`) for AI-enabled Pro organizations.

---

## 3. Durable Dirty Date Outbox & Recomputation (`RecomputeBranchDay`)

```mermaid
sequenceDiagram
  autonumber
  participant Ingest as ProcessIngestBatch
  participant Outbox as ingest_dirty_dates
  participant Job as RecomputeBranchDay (aggregate)
  participant Rebuilder as BranchDayRebuilder
  participant Recon as Reconciler

  Ingest->>Outbox: DirtyDateOutbox::record() in same DB tx
  Ingest->>Job: Dispatch unique(branch_id:date) delayed 30s
  Job->>Outbox: acquireRecomputeLock() + claimRecomputeFence()
  Job->>Rebuilder: Delete & rebuild all 6 agg_* tables
  Rebuilder->>Recon: Compare vs wh_day_summaries (tolerance 0.01)
  Rebuilder->>Outbox: BranchDayUpdated event (clears MetricsCache)
  Job->>Outbox: markRecomputed(snapshot); re-dispatch if newer generation arrived
```

- `DirtyDateOutbox::record()` increments `generation` inside the same database transaction as the `wh_*` write.
- `RecomputeBranchDay` (`queue = aggregate`, `ShouldBeUniqueUntilProcessing`, `uniqueFor = 120`, `tries = 0`, `maxExceptions = 3`) is dispatched with a 30-second delay so burst updates collapse into one rebuild.
- Deleted branches exit cleanly without recreating fences or re-queueing.
- `BranchDayRebuilder` deletes and re-inserts all 6 `agg_*` tables for `(branch_id, business_date)` inside a transaction, handling negative/reversed shift durations gracefully (same-day reversed clocks are treated as overnight shifts; multi-day reversed calendar timestamps leave `duration_minutes = null` and log a warning).

---

## 4. POS Summary Reconciliation (`Reconciler`)

When `wh_day_summaries` exists for `(branch_id, business_date)`, `Reconciler` compares four metrics against `BranchDayRebuilder` outputs with **`0.01` EGP tolerance**:

- `orders_count` (`wh_day_summaries.completed_count` vs `agg_branch_daily.orders_count`)
- `net_total` (`wh_day_summaries.net_total` vs `agg_branch_daily.net_sales`)
- `expenses_total` (`wh_day_summaries.expenses_total` vs `agg_branch_daily.expenses_total`)
- `waste_total` (`wh_day_summaries.waste_total` vs `agg_branch_daily.waste_cost`)

On mismatch:
1. Upserts `reconciliation_issues` (`status = 'open'` / `'resync_requested'`) and increments `attempts`.
2. If `attempts < 3` and no undelivered `resync` command exists in `branch_commands`, queues `{type: 'resync', from: date, to: date}` for the next POS heartbeat.
3. At `attempts >= 3`, marks the issue `'failed'` for operator inspection in Data Health (`/app/reports/data-health`).

Note: Bounded resync cannot resolve documents rejected at ingress (`ingest_rejections`) until the underlying POS data/validation issue is fixed.

---

## 5. Automated Crash Recovery (`ingest:recover`)

Scheduled every minute in `routes/console.php`:

```bash
php artisan ingest:recover --limit=100 --stale-seconds=90
# Run dirty-date rebuilds synchronously in the CLI process:
php artisan ingest:recover --sync
```

`RecoverIngestBatchesCommand` re-dispatches:
1. `ingest_batches` stuck in `received` older than 30 seconds.
2. `ingest_batches` stuck in `processing` with expired `lease_expires_at <= now()` (or stale `updated_at` when `lease_expires_at` is null).
3. `ingest_dirty_dates` in `pending` / `failed` or stale `dispatched` state via `DirtyDateOutbox::recover()`.
