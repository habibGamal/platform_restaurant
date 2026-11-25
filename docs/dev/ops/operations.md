# Operations & Troubleshooting

---

## 1. Health Checks & Monitoring

| Signal | Where to Check | Healthy State |
|---|---|---|
| **HTTP liveness** | `GET /up` | HTTP `200 OK` |
| **Branch sync & completeness** | `/app/reports/data-health` | Active branches `online` (heartbeat < 10 min), 0 open `reconciliation_issues`, minimal `ingest_rejections` |
| **Application logs** | `storage/logs/laravel.log` | No recurring errors or `FencedWorkerException` storms |
| **Failed jobs** | `php artisan queue:failed` | Empty `failed_jobs` table |

---

## 2. Background Jobs Inventory

| Job / Command | Queue | Retries / Policy | Failure Surface |
|---|---|---|---|
| `ProcessIngestBatch` | `ingest` | `tries = 0`, `maxExceptions = 3`, `timeout = 60` | `ingest_batches.status = 'failed'`, `failed_jobs`, `DataHealth` |
| `RecomputeBranchDay` | `aggregate` | `tries = 0`, `maxExceptions = 3`, `uniqueFor = 120` | `ingest_dirty_dates`, `failed_jobs` |
| `DeleteBranchFiles` | `ingest` | `tries = 5`, `backoff = 60` | `failed_jobs` |
| `RunAssistantQuestion` | `assistant` | `tries = 1`, `timeout = 900`, `uniqueFor = 960` | `ai_run_events` error event / chat error message |
| `ClassifyItemRoles` | `assistant` | `timeout = 600`, `uniqueFor = 900` | `laravel.log` |
| `BuildMenuIntelligence` | `assistant` | `maxExceptions = 1`, `timeout = 900`, `uniqueFor = 3600` | `menu_intelligence_builds`, `failed_jobs` |
| `php artisan ingest:recover` | Cron (1 min) | `withoutOverlapping()` | CLI/cron output |

---

## 3. Routine Operator Commands

```bash
# Recover stalled batches and dirty dates (add --sync to rebuild aggregates inline)
php artisan ingest:recover --limit=100 --stale-seconds=90

# Rebuild aggregates for specific branches and dates
php artisan metrics:rebuild --branch=3 --from=2026-10-01 --to=2026-10-31

# Rebuild Menu Intelligence for an organization (or all eligible orgs without args)
php artisan menu:build-intelligence 1 --sync

# Inspect failed queue jobs (review before retrying)
php artisan queue:failed
php artisan queue:retry <job-id>
```

---

## 4. Troubleshooting Matrix

| Symptom | Likely Cause | Fix |
|---|---|---|
| POS gets `409 Conflict` on batch upload | Idempotent replay of an existing `batch_uuid` | Expected; POS treats returned `accepted`/`rejected` as authoritative (unfinished batches are re-dispatched by the platform). |
| POS gets `401 Unauthorized` | Token rotated or branch deleted | Re-pair branch with a fresh 8-char code from `/app/branches`. |
| POS gets `403 Forbidden` | Branch `suspended` or envelope `branch_uuid` mismatch | Check branch status and POS connection config before re-activating. |
| Recent orders missing from reports | `ingest` or `aggregate` worker stopped, or batches stuck | Verify workers on `--queue=ingest` and `--queue=aggregate`; run `php artisan ingest:recover`. |
| Branch excluded from Branch Ranking | Missing past days or unreconciled `closed` days | Inspect `/app/reports/data-health` completeness calendar and open `reconciliation_issues`; trigger **طلب إعادة مزامنة** if needed. |
| Assistant shows `"المساعد مش متاح دلوقتي"` or times out | `assistant` queue worker not running | Start `php artisan queue:work assistant --queue=assistant --timeout=900 --tries=1`. |
| Assistant says monthly budget exceeded | Org reached `monthly_budget_egp` in `ai_settings` | Increase budget in `/app/management/ai-settings`. |
| Assistant says menu intelligence not ready | `BuildMenuIntelligence` has not finished for period | Run `php artisan menu:build-intelligence <org_id> --sync`. |
