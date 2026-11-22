# 02. Configuration, Environment, and Deployment Guide

This guide details configuration settings, environment variables, infrastructure prerequisites, queue processing rules, scheduler commands, and deployment operations for `platform-restaurants`.

---

## 1. Environment Variables & Configuration Defaults

Configuration values fall back to defaults specified in `config/` when environment variables are omitted. The table below delineates values defined in [`.env.example`](../../.env.example) from default fallback values hardcoded in [`config/app.php`](../../config/app.php) and related configuration files. **No secret values, production credentials, or real customer keys are included.**

| Variable Name | `.env.example` Setting | `config/` Default Fallback | Purpose & Architectural Context |
|---|---|---|---|
| `APP_NAME` | `Laravel` | `'Laravel'` (`config/app.php`) | Brand name displayed in emails, headers, and logs. |
| `APP_ENV` | `local` | `'production'` (`config/app.php`) | Runtime environment. Defaults to `production` for safety if unset. |
| `APP_KEY` | *(empty string)* | `null` (`config/app.php`) | 32-character AES key for cookie encryption and encrypted model columns (`branches.branch_secret`). Generated via `php artisan key:generate`. |
| `APP_DEBUG` | `true` | `false` (`config/app.php`) | Toggles detailed stack traces. Must remain `false` in production. |
| `APP_URL` | `http://localhost:8000` | `'http://localhost'` (`config/app.php`) | Base application domain for absolute URL generation. |
| `APP_LOCALE` | `ar` | `'en'` (`config/app.php`) | Primary UI language. Hardened to Arabic (`ar`) via `.env.example`. |
| `APP_FALLBACK_LOCALE`| `en` | `'en'` (`config/app.php`) | Fallback language when a translation key is missing. |
| `APP_TIMEZONE` | `Africa/Cairo` | `'Africa/Cairo'` (`config/app.php`) | Business timezone. Financial and day calculations operate under `Africa/Cairo`. |
| `DB_CONNECTION` | `mysql` | `'mysql'` (`config/database.php`) | Primary database driver. Tests configure a dedicated MySQL test database. |
| `DB_HOST` | *(commented 127.0.0.1)* | `'127.0.0.1'` | Database server hostname. |
| `DB_PORT` | *(commented 3306)* | `'3306'` | Database server port. |
| `DB_DATABASE` | *(commented laravel)* | `'laravel'` (MySQL/MariaDB) | Primary database schema name. |
| `DB_USERNAME` | *(commented root)* | `'root'` (MySQL/MariaDB) | Database connection user. |
| `DB_PASSWORD` | *(commented empty)* | `''` | Database connection password. |
| `QUEUE_CONNECTION` | `database` | `'sync'` (`config/queue.php`) | Queue backend driver (`database` or `redis`). Must support transactions and visibility timeouts. |
| `CACHE_STORE` | `database` | `'database'` (`config/cache.php`) | Cache and rate-limiting driver. |
| `SESSION_DRIVER` | `database` | `'database'` (`config/session.php`) | User session storage driver. |
| `SESSION_LIFETIME` | `120` | `120` minutes | Session inactivity timeout in minutes. |
| `FILESYSTEM_DISK` | `local` | `'local'` (`config/filesystems.php`) | Primary filesystem disk. Root configured as `storage/app/private`. |

`ProcessIngestBatch::getLeaseDuration()` reads the configuration key `ingest.lease_duration_seconds` and otherwise uses its 30-second class default. No environment-variable mapping for that key is defined in this repository.

---

## 2. Infrastructure & System Prerequisites

- **PHP Version**: `^8.3` (required by [`composer.json`](../../composer.json)).
- **Mandatory PHP Extensions**:
  - `pdo_mysql`: Database operations.
  - `zlib`: Decompressing streaming gzip batch payloads via `stream_filter_append('zlib.inflate')` and `gzdecode`.
  - Consult Composer's platform requirements and the selected database driver for the complete extension list.
  - `mbstring`: UTF-8 Arabic text manipulation and regex matching.
  - `openssl`: Laravel encryption support; Sanctum stores token hashes, while `branches.branch_secret` is an encrypted model attribute.
- **Database Server**: MySQL or MariaDB; check the deployment's actual server version and compatibility. The configured MySQL defaults use `utf8mb4` and `utf8mb4_unicode_ci`.
- **Testing Database**: Defined in [`phpunit.xml`](../../phpunit.xml) as a MySQL database:
  ```xml
  <env name="DB_CONNECTION" value="mysql"/>
  <env name="DB_DATABASE" value="platform_testing"/>
  ```
  The test suite does not use SQLite in-memory; it relies on an accessible MySQL test schema.
- **Local Storage Permissions**: The web server user (`www-data` or `nginx`) must possess read/write permissions over `storage/app/private/ingest/`, `storage/framework/`, and `bootstrap/cache/`.

---

## 3. Initial Setup & Startup Sequence

When provisioning a new instance of `platform-restaurants`, execute the following sequential steps:

```bash
# 1. Install Composer dependencies (exclude dev dependencies in production)
composer install --no-dev --optimize-autoloader

# 2. Generate application encryption key
php artisan key:generate

# 3. Execute all sequential schema migrations
php artisan migrate --force

# 4. Seed essential system roles, permissions, and operator accounts
php artisan db:seed --class=RolesAndPermissionsSeeder --force

# 5. (Optional / Development) Seed demo organization, restaurants, and branches
php artisan db:seed --force

# 6. Build optimized caches for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:cache-components
```

---

## 4. Scheduled Background Tasks (Cron)

The system relies on the Laravel Scheduler running once every minute. Add the standard cron entry to the hosting server's crontab:

```crontab
* * * * * cd /path/to/platform-restaurants && php artisan schedule:run >> /dev/null 2>&1
```

### Registered Schedules ([`routes/console.php`](../../routes/console.php))

```php
Schedule::command('ingest:recover')->everyMinute()->withoutOverlapping();
```

- **Execution Frequency**: Every minute.
- **Concurrency Guard**: `withoutOverlapping()`.
- **Target Command**: [`RecoverIngestBatchesCommand.php`](../../app/Console/Commands/RecoverIngestBatchesCommand.php).
- **Function**:
  1. Detects batches stuck in `received` status for longer than 30 seconds and redispatches `ProcessIngestBatch`.
  2. Detects batches stuck in `processing` status whose leases expired (`lease_expires_at <= now()`) and re-queues them.
  3. Scans `ingest_dirty_dates` for dirty business dates whose aggregate recomputation failed or stalled and invokes [`DirtyDateOutbox.php`](../../app/Domain/Ingestion/Support/DirtyDateOutbox.php).

---

## 5. Queue Worker Configuration

The platform divides asynchronous processing into two distinct queues with strict ordering and serialization requirements:

1. **`ingest` Queue**: Processes incoming JSON documents, writes to mirrored warehouse tables (`wh_*`), and records dirty dates into the outbox.
2. **`aggregate` Queue**: Recomputes daily aggregates (`agg_*`) per branch and business date.

A third, separate **`assistant` connection and queue** runs the AI assistant: one job per question ([`RunAssistantQuestion.php`](../../app/Jobs/RunAssistantQuestion.php), timeout 900 s, never retried) and the item-role classification ([`ClassifyItemRoles.php`](../../app/Jobs/ClassifyItemRoles.php)). It has its own connection in [`config/queue.php`](../../config/queue.php) so its long `retry_after` (`ASSISTANT_QUEUE_RETRY_AFTER`, default 960 s) does not slow down retries of the other queues. If no worker serves it, the chat page shows «المساعد مش متاح دلوقتي» after about 1000 s. Locally, `php artisan dev` starts an `assistant-queue` listener for it.

### Supervisord Configuration Example

```ini
[program:platform-ingest-worker]
process_name=%(program_name)s_%(process_num)02d
environment=DB_QUEUE_RETRY_AFTER="180"
command=php /path/to/platform-restaurants/artisan queue:work --queue=ingest --sleep=3 --tries=3 --max-time=3600 --timeout=90
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/platform-ingest.log

[program:platform-aggregate-worker]
process_name=%(program_name)s_%(process_num)02d
environment=DB_QUEUE_RETRY_AFTER="180"
command=php /path/to/platform-restaurants/artisan queue:work --queue=aggregate --sleep=3 --tries=3 --max-time=3600 --timeout=120
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/platform-aggregate.log

[program:platform-assistant-worker]
process_name=%(program_name)s_%(process_num)02d
environment=ASSISTANT_QUEUE_RETRY_AFTER="960"
command=php /path/to/platform-restaurants/artisan queue:work assistant --queue=assistant --timeout=900 --tries=1
autostart=true
autorestart=true
stopwaitsecs=960
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/platform-assistant.log
```

> [!IMPORTANT]
> These examples assume the database queue driver. Set its retry-after interval above the maximum effective job/worker timeout; the repository's default is 90 seconds and is not safely larger than the example timeouts. When using another queue backend, configure its own visibility/retry interval. Job-specific timeout settings can override worker settings; see [`ProcessIngestBatch.php`](../../app/Jobs/ProcessIngestBatch.php), [`RecomputeBranchDay.php`](../../app/Jobs/RecomputeBranchDay.php), and [`config/queue.php`](../../config/queue.php). Restart workers after configuration changes and test with the chosen backend.

---

## 6. Raw Storage Layout & Disk Root

Raw incoming batch payloads are persisted directly to the local storage disk prior to queue processing.

### Physical Filesystem Path
In [`config/filesystems.php`](../../config/filesystems.php), the `local` disk root is configured as:
```php
'root' => storage_path('app/private'),
```
Therefore, files written with `Storage::disk('local')->put($storagePath, $rawContent)` reside at:
```
storage/app/private/ingest/{organization_id}/{branch_id}/{YYYY}/{MM}/{batch_uuid}.{json|json.gz}
```

- **Example**: `storage/app/private/ingest/1/4/2026/11/7f3a9e01-52d3-4a11-8931-10c017d84fbb.json.gz`
- **Audit Retention**: Raw files are retained for historical auditing and manual data replay unless a branch is permanently deleted.
- **Branch Deletion Purge**: When a branch is deleted, [`DeleteBranchFiles.php`](../../app/Jobs/DeleteBranchFiles.php) is dispatched to permanently remove `ingest/{org_id}/{branch_id}` under `storage/app/private`.
