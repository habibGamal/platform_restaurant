# Operations and Troubleshooting

This operational manual covers health signals, monitoring indicators, background queue supervision, routine administrative tasks, incident response runbooks, and disaster recovery procedures for **`platform-restaurants`**.

---

## Health and Monitoring

| Signal | Location / Method | Healthy State |
|---|---|---|
| **HTTP Health Endpoint** | `GET /up` | HTTP `200 OK` (Laravel default health check) |
| **Ingestion Sync Freshness** | App Panel: `/app/reports/data-health` | Average batch sync latency < 60 seconds; 0 failed batches |
| **Application Logs** | `storage/logs/laravel.log` (or Papertrail / CloudWatch) | No repetitive `CRITICAL` or `EMERGENCY` entries |
| **Queue Backlog** | `php artisan queue:monitor redis:ingest,redis:aggregates` | Total queue backlog < 50 jobs; latency < 5s |
| **Heartbeat Disconnections** | App Panel: `/app/reports/overview` | All active branches report `last_seen_at` within last 5 minutes |

---

## Background Work

| Job / Schedule | Trigger | Retries | Safe to Re-run? | Failure Visible Where |
|---|---|---|---|---|
| [`ProcessIngestBatch`](../app/Jobs/ProcessIngestBatch.php) | Incoming batch HTTP submission | 3 retries with backoff | **Yes:** Idempotent via version guard | `failed_jobs` table & `DataHealth` report |
| [`RecomputeBranchDay`](../app/Jobs/RecomputeBranchDay.php) | `DirtyDateOutbox` trigger | 3 retries | **Yes:** Deterministic aggregation overwrite | `failed_jobs` table & error logs |
| [`RunAssistantQuestion`](../app/Jobs/RunAssistantQuestion.php) | User query submitted in `/app/assistant` | 1 attempt (no retry) | **Yes:** Read-only analysis | Chat interface displays error toast |
| [`ClassifyItemRoles`](../app/Jobs/ClassifyItemRoles.php) | Scheduled daily at 04:00 | 2 retries | **Yes:** Preserves manual overrides | `laravel.log` & Item Roles page |
| [`BuildMenuIntelligence`](../app/Jobs/BuildMenuIntelligence.php) | Scheduled daily at 04:30 or delayed ingest hook | 2 retries | **Yes:** Deterministic rebuild | `menu_intelligence_builds` & `failed_jobs` |
| [`DeleteBranchFiles`](../app/Jobs/DeleteBranchFiles.php) | Branch deletion in Admin Panel | 3 retries | **Yes:** Idempotent file unlinking | `failed_jobs` table |
| `artisan ingest:recover` | Scheduled every minute | Unlimited | **Yes:** State-checked lease recovery | Output in cron stdout / system logs |
| `artisan model:prune` | Scheduled daily at 00:00 | 1 attempt | **Yes:** Time-bounded record pruning | Application log |

---

## Routine Tasks

| Task | Frequency | Execution Command / Procedure |
|---|---|---|
| **Clear Application Caches** | After code releases or config edits | `php artisan optimize:clear && php artisan config:cache` |
| **Prune Old AI Run Events** | Scheduled daily | `php artisan model:prune --model="App\Models\AiRunEvent"` |
| **Rebuild Branch Aggregates** | Upon manual data correction | `php artisan metrics:rebuild --branch={id} --from={date} --to={date}` |
| **Rebuild Menu Intelligence** | On demand / catalog updates | `php artisan menu:build-intelligence {organization_id} --sync` |
| **Rotate Branch Sanctum Token** | Quarterly or upon security request | Request via POS terminal: `POST /api/v1/pair/rotate` |
| **Database Snapshot Backup** | Every 6 hours | Automated RDS/database snapshot or `mysqldump` |

---

## Runbooks

### Runbook 1: Ingestion Batch Queue Accumulating (POS Sync Lagging)

1. **Confirm:** Check queue depth via `php artisan queue:monitor redis:ingest`. If pending jobs exceed 500, a backlog is active.
2. **Diagnose:**
   - Are queue workers running? Check with `supervisorctl status`.
   - Is the database experiencing locks? Check `SHOW FULL PROCESSLIST;` for lock waits on `wh_orders`.
   - Are incoming payloads unusually large? Check `ingest_batches` recent payload sizes.
3. **Fix:**
   - If workers crashed: Run `supervisorctl restart platform-worker:*`.
   - If worker count is insufficient: Increase worker replicas in supervisor configuration from 4 to 8.
   - If database lock contention is present: Terminate blocking long-running analytical queries.
4. **Verify:** Monitor `queue:monitor` until pending count decreases steadily toward zero.
5. **Escalate:** If Redis runs out of memory (`OOM command not allowed`), notify Infrastructure Engineer to resize Redis instance.

### Runbook 2: Daily Aggregates Out of Sync with POS Day Summary

1. **Confirm:** Navigate to `/app/reports/data-health` and observe rows flagged with red "Discrepancy" badges in the Reconciliation table.
2. **Diagnose:**
   - Note the difference between raw calculated sales and POS reported `wh_day_summaries`.
   - Check if orders are still pending in the `ingest` queue for that date.
   - Check if cashier made manual adjustments or offline returns on the physical POS.
3. **Fix:**
   - Trigger a day recompute:
     ```bash
     php artisan metrics:rebuild --branch={branch_id} --from={date} --to={date}
     ```
   - If raw orders were omitted by the POS, click **طلب إعادة المزامنة (Request Resync)** on the Data Health page; this queues a `resync_day` command delivered on the next POS heartbeat.
4. **Verify:** Refresh `/app/reports/data-health`; confirm status changes to green `Matched`.

### Runbook 3: AI Assistant Queries Timing Out or Failing

1. **Confirm:** Open `/app/assistant` and submit a test prompt; observe if chat returns error toast or hangs indefinitely.
2. **Diagnose:**
   - Check if worker is running on the dedicated queue: `php artisan queue:monitor redis:assistant`.
   - Inspect the organization's monthly token usage in `/app/management/ai-settings`.
   - Inspect `storage/logs/laravel.log` for HTTP `429 Too Many Requests` or `401 Unauthorized` from the LLM provider.
3. **Fix:**
   - If token budget depleted: Increase the organization budget in AI Settings.
   - If API key invalid: Re-enter provider credentials in AI Settings.
   - If worker dead: Restart the assistant worker via `supervisorctl restart platform-assistant:*`.
4. **Verify:** Submit a simple question (*"What were total sales today?"*) and verify live work log streams to completion.

---

## Troubleshooting Index

| Symptom | Affected Subsystem | Resolution Guide |
|---|---|---|
| Pairing code expired or invalid | POS Onboarding | [POS Pairing Troubleshooting](features/pos-pairing-and-heartbeat.md#troubleshooting) |
| Batch rejected with `413 Payload Too Large` | Ingestion Ingress | [Batch Ingestion Troubleshooting](features/batch-ingestion.md#troubleshooting) |
| `FencedWorkerException` in worker logs | Concurrency & Leases | [Crash Recovery Troubleshooting](features/crash-recovery-and-fencing.md#troubleshooting) |
| Reports show zero sales for past days | Aggregation Engine | [Warehouse & Aggregates Troubleshooting](features/warehouse-and-aggregates.md#troubleshooting) |
| Like-for-like comparison empty | Metrics Engine | [Metrics Query Troubleshooting](features/metrics-query-and-kpis.md#troubleshooting) |
| Empty report tables or Livewire errors | Reporting Suite | [Analytics Reports Troubleshooting](features/analytics-reports.md#troubleshooting) |
| Target distribution shows uniform weights | Targets Planning | [Targets & Ranking Troubleshooting](features/targets-and-ranking.md#troubleshooting) |
| User receives 403 Forbidden on `/admin` | Access Control | [Tenancy Troubleshooting](features/tenancy-and-access-control.md#troubleshooting) |
| Monthly AI token budget exceeded | AI Assistant | [AI Assistant Troubleshooting](features/ai-assistant.md#troubleshooting) |
| Menu items classified incorrectly as Dogs | Menu Engineering | [Menu Engineering Troubleshooting](features/menu-engineering-and-roles.md#troubleshooting) |

---

## Data Management

- **Backups:** Perform automated daily snapshots of the primary relational database. Maintain 30-day retention for production snapshots.
- **Log Rotation:** Laravel's daily logging channel is configured to retain 14 days of logs ([`config/logging.php`](../config/logging.php)).
- **Telemetry Retention:** The `AiRunEvent` table is pruned daily via `php artisan model:prune` to prevent unlimited growth of transient reasoning logs.
- **Data Purging on Branch Deletion:** Handled via cascading database foreign keys and background file unlinking ([`DeleteBranchFiles`](../app/Jobs/DeleteBranchFiles.php)).
