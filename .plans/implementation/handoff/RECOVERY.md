# Connector and ingestion recovery

## Platform

Run migrations before using the new API. Run queue workers for both `ingest` and
`aggregate`, and run Laravel's scheduler every minute. The scheduler invokes
`ingest:recover` to redispatch received or abandoned batches and pending durable
dirty-date records. `ingest:recover --sync` can rebuild pending aggregates directly.

Batch receipt is serialized by branch; duplicate UUIDs return the stored public
response. Accepted document indexes are persisted internally so a rejected recipe
cannot be processed merely because its product shares the same UUID.

Warehouse writes and dirty-date generations commit together. Aggregate workers
capture generations before rebuilding and acknowledge only those generations.
Updates arriving during a rebuild remain pending. Batch claims and recomputation
fences prevent superseded workers from acknowledging newer work. MySQL row locks
serialize the warehouse mutation/ownership check and the aggregate rebuild itself;
correctness does not depend solely on a PHP timeout or an expiring cache lock.

The overview cannot provide sales-after-returns comparisons through an hourly
cutoff until the source contract supplies a trustworthy return event timestamp.
It displays that comparison as unavailable; hourly order count and average ticket
comparisons remain available.

## POS

Pairing credentials are persisted before initial backfill because the remote
pairing code is single-use. Catalog and history progress is durable and retried
by `platform:sync`. `PlatformConnection::active()` remains a lookup without backfill
side effects. Each backfill step holds the connection row lock through ownership
validation, outbox writes, and its checkpoint. A crashed step rolls back and can be
reclaimed; an expired claimant cannot write after another owner takes over.

Keep the POS scheduler enabled. It runs sync every minute, heartbeat every five
minutes, and pruning daily. Old pending batches and batches referenced by retained
outbox rows are preserved for recovery.

## Orders rejected for missing web_pos_diff

Deploy the platform validation fix first and restart its queue workers. This field
is required only for web_delivery/web_takeaway upserts; other types may omit it or
send null. No migration or POS code change is needed.

Rejected POS rows have their batch_id cleared and remain unsent. They retry in new
batches after backoff, or stop after ten attempts. On the POS connection page,
use "إعادة محاولة الفاشل" followed by "مزامنة الآن" for stopped rows. Pending rows
still in backoff retry automatically (up to sixty minutes).

To immediately requeue only unsent orders affected by this error, use POS Tinker:

```php
App\Models\SyncOutbox::where('doc_type', 'order')
    ->whereNull('sent_at')
    ->where('last_error', 'like', '%web pos diff%')
    ->update(['failed_at' => null, 'attempts' => 0, 'next_attempt_at' => null]);
```

Then run `php artisan platform:sync` on the POS. Do not delete ingestion history:
old batch UUIDs retain their original responses, and old ingest_rejections remain
historical records even after a successful resend.

## Aggregate failure: duration_minutes out of range

Some imported shifts have end_at earlier than start_at by multiple days or months.
Carbon produces a negative duration, which cannot fit agg_shift.duration_minutes
(unsigned integer). A same-day reversed clock is still handled as a legacy
overnight shift; a reversed calendar date now leaves duration null and logs the
branch/shift/timestamps. Source timestamps and sales are preserved. No migration
or POS resend is required to restore aggregation. Correct source POS timestamps
separately before expecting accurate historical shift durations.

After deploying the fix, restart the existing aggregate workers to load the new
code. With an unmanaged terminal worker, stop and restart its queue:work command;
queue:restart alone does not launch a replacement process. Then run on the platform:

```bash
php artisan queue:retry --queue=aggregate
php artisan ingest:recover
```

Validation: 48 aggregation, ShiftsWeekdays report and ingestion recovery tests pass
on platform_test_12, including long reversed timestamps, previous-day invalid ends,
normal and legacy overnight shifts, zero duration, multiple days and missing dates.
Targeted Pint and PHPStan pass.

## Validation boundaries

Cross-app verification uses a transient CLI HTTP-kernel bridge, real POS builders,
isolated SQLite POS data, and `platform_test_10`. It exercises pairing, rotation,
gzip batching, ingestion, metrics rebuild, overview values, and tenant boundaries.
It does not exercise an actual network server, TLS, browser assets, or deployment.

A separate two-process MySQL test held a row lock beyond a deliberately short lease,
verified competing ownership was blocked, killed a worker during its transaction,
and verified rollback and recovery. Its dedicated database was removed afterward.

External website callers must provide `X-Api-Key`; management callers must provide
`X-Management-Key`. The POS branch client already sends its configured `WEB_API_KEY`.
The external callers were not located or changed as part of these two repositories.
