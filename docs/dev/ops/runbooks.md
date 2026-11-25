# Operational Runbooks

Consolidated operational procedures and verified incident resolutions.

---

## 1. Queue Contention, Stalled Batches & Deleted Branches

**Context:** `ProcessIngestBatch` and `RecomputeBranchDay` use `tries = 0` and `maxExceptions = 3` because Laravel counts `WithoutOverlapping` / cache-lock middleware releases against `tries`.

- **Recovery:** Prefer `php artisan ingest:recover` over blind `queue:retry` on older serialized job payloads, because `ingest:recover` dispatches fresh jobs with current retry/fencing settings while checking `lease_expires_at` and `ingest_dirty_dates` generations.
- **Deleted branches:** `RecomputeBranchDay` exits cleanly if the branch row no longer exists (and foreign keys prevent stale warehouse writes).
- **Worker restarts:** Always restart long-lived workers after deploying queue/job fixes; `queue:restart` signals workers to exit after their current job, and Supervisor/systemd must respawn them.

---

## 2. Requeueing Orders Rejected for `web_pos_diff`

**Symptom:** Older POS orders failed validation (`web_pos_diff`) before the platform rule was scoped strictly to `web_delivery` and `web_takeaway` upserts.

**Procedure:**
1. Confirm the platform is running the updated `DocumentRules::typeRules('order')` and queue workers have been restarted.
2. On the **POS (`larament`)** side, rejected rows have `batch_id` cleared in `sync_outbox` and retry with backoff up to 10 attempts. For stopped rows, use **"إعادة محاولة الفاشل"** then **"مزامنة الآن"** on the POS connection page, or requeue in POS Tinker:
   ```php
   App\Models\SyncOutbox::where('doc_type', 'order')
       ->whereNull('sent_at')
       ->where('last_error', 'like', '%web pos diff%')
       ->update(['failed_at' => null, 'attempts' => 0, 'next_attempt_at' => null]);
   ```
3. Run `php artisan platform:sync` on the POS. **Never delete `ingest_batches` or `ingest_rejections`** — old batch UUIDs retain idempotent history.

---

## 3. Shift `duration_minutes` Out-of-Range Aggregate Failure

**Symptom:** Historical shifts imported with `end_at < start_at` across calendar dates caused unsigned `agg_shift.duration_minutes` overflow on MariaDB/MySQL.

**Handling in `BranchDayRebuilder`:**
- Same-day reversed clocks are handled as legacy overnight shifts.
- Multi-day reversed calendar dates leave `agg_shift.duration_minutes = null` and log the branch/shift/timestamps while preserving sales and shift totals.

**Recovery after worker restart:**
```bash
php artisan queue:retry --queue=aggregate
php artisan ingest:recover
```

Correct the underlying timestamps in the source POS separately if accurate historical shift durations are required.

---

## 4. Historical POS Shift-Date Corruption (`ON UPDATE CURRENT_TIMESTAMP`)

**Root cause:** On MariaDB, `shifts.start_at` expanded to `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, causing backfill updates to overwrite `start_at` and `business_date` with the current timestamp.

**Resolution (POS side):**
1. Apply POS migration `2026_10_31_235959_preserve_shift_start_at` to remove the implicit `ON UPDATE`.
2. Preview and apply `platform:repair-shift-dates` on the POS (e.g. `--apply --from-created-at` only after verifying `created_at` is trustworthy on that dataset; original rows are backed up under POS private local disk `repairs/shift-dates/`).
3. Corrected shifts, orders, returns, expenses, and both old/new `day_summary` rows are re-queued to the platform outbox, where `ProcessIngestBatch` + `DirtyDateOutbox` update warehouse rows and rebuild both old and new dates without deleting the branch.

---

## 5. Branch Deletion Procedure & Guarantees

Deleting a branch through `/admin` or `/app` (single or bulk action):
1. Acquires a row lock on `branches` (`Branch::delete()`) — the same lock used by `Branch::issueIngestToken()` so stale pairing/rotation cannot issue a token after deletion.
2. Revokes all Sanctum tokens (`Branch::deleting`).
3. Cascades in-database across all **29** `wh_*`, `dim_*`, and `agg_*` tables (`2026_11_02_000009_cascade_branch_owned_data.php`) plus control tables (`ingest_batches`, `ingest_dirty_dates`, `ingest_recompute_fences`, `ingest_rejections`, `reconciliation_issues`, `branch_commands`, `branch_pairing_codes`, `user_branch_access`, `branch_set_members`, `targets`).
4. Dispatches `DeleteBranchFiles` (`queue = ingest`, `afterCommit`, `tries = 5`) to delete `storage/app/private/ingest/{organization_id}/{branch_id}`. Inspect `failed_jobs` if storage cleanup fails.
5. Leaves `Organization`, `Restaurant`, `User` accounts, `catalog_items`, and `branch_sets` intact. Remote POS local data is untouched, and its next request fails with `401`.

---

## 6. Creating & Managing Tenant Users (`/admin/users`)

Platform operators provision organization accounts in `/admin` -> **المستخدمون** (`/admin/users`) -> **إنشاء مستخدم**:
1. Select the **Organization** (cannot be changed after creation).
2. Enter name, email, and password (`>= 8` chars; leave blank on edit to preserve existing password).
3. Choose role:
   - `org_owner` (**مالك**) — full org access (create first owner here).
   - `org_analyst` (**محلل**) — org-wide analytics & assistant access.
   - `branch_manager` (**مدير فرع**) — select authorized branches from that organization.
4. Saving updates the user, role, and `user_branch_access` transactionally (no email is sent; users sign in at `/app/login`).
5. **Protections:** Operator accounts are excluded from this resource, tenant users cannot access `/admin`, foreign branch IDs are rejected, and the organization's last `org_owner` cannot be deleted or demoted without adding a replacement owner first.
