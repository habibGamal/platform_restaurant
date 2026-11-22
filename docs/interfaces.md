# Interfaces

This document catalogs every external interface exposed or consumed by **`platform-restaurants`**, including HTTP REST APIs, the Artisan command-line interface, scheduled jobs, and external third-party integrations.

---

## Overview

| Interface | Consumers | Auth Mechanism | Canonical Definition |
|---|---|---|---|
| **Ingress REST API (`/api/v1/*`)** | Branch POS Terminals (`larament`) | Bearer Token (Sanctum `ability:ingest`) | [`routes/api.php`](../routes/api.php) |
| **App Management Panel (`/app`)** | Organization Owners, Managers, Analysts | Session Cookie (`auth:sanctum` / web session) | [`AppPanelProvider.php`](../app/Providers/Filament/AppPanelProvider.php) |
| **System Admin Panel (`/admin`)** | Platform Super Administrators | Session Cookie (`auth:sanctum` / web session) | [`AdminPanelProvider.php`](../app/Providers/Filament/AdminPanelProvider.php) |
| **Artisan Command Line** | System Operators, Cron Schedulers | Host Shell Access | [`app/Console/Commands`](../app/Console/Commands) |

---

## Conventions

- **Base URL:** Production: `https://api.platform.example.com` / Local: `http://localhost:8000`.
- **API Versioning:** Prefixed in URL path: `/api/v1/`.
- **Authentication Header:** Standard HTTP Authorization header: `Authorization: Bearer <sanctum-token>`.
- **Content Types:** `application/json` default. Batch ingestion accepts and prioritizes `Content-Encoding: gzip`.
- **Rate Limits:**
  - Pairing endpoint: 10 attempts per minute per IP (`throttle:ingest-pair`).
  - Batch ingestion: 120 submissions per minute per branch (`throttle:ingest-batches`).
- **Standard Error Envelope:**
  ```json
  {
    "message": "The given data was invalid.",
    "errors": {
      "batch_id": ["The batch_id field must be a valid UUID."]
    }
  }
  ```

---

## Endpoints by Area

### POS Terminal Connectivity & Ingestion

| Method | Path | Purpose | Auth / Permission | Handler | Feature |
|---|---|---|---|---|---|
| `POST` | `/api/v1/pair` | Redeem 8-digit code to pair POS terminal | None (`throttle:ingest-pair`) | [`PairController::pair`](../app/Http/Controllers/Api/V1/PairController.php) | [POS Pairing & Heartbeat](features/pos-pairing-and-heartbeat.md) |
| `POST` | `/api/v1/pair/rotate` | Rotate active Sanctum token for branch | `auth:sanctum`, `ability:ingest` | [`PairController::rotate`](../app/Http/Controllers/Api/V1/PairController.php) | [POS Pairing & Heartbeat](features/pos-pairing-and-heartbeat.md) |
| `POST` | `/api/v1/ingest/batches` | Submit compressed POS transactional batch | `auth:sanctum`, `ability:ingest` | [`IngestBatchController::store`](../app/Http/Controllers/Api/V1/IngestBatchController.php) | [Batch Ingestion Pipeline](features/batch-ingestion.md) |
| `POST` | `/api/v1/heartbeat` | Send terminal telemetry & pull commands | `auth:sanctum`, `ability:ingest` | [`HeartbeatController::store`](../app/Http/Controllers/Api/V1/HeartbeatController.php) | [POS Pairing & Heartbeat](features/pos-pairing-and-heartbeat.md) |
| `GET` | `/api/user` | Fetch authenticated user profile | `auth:sanctum` | Closure in [`routes/api.php`](../routes/api.php#L10) | [Tenancy & Access Control](features/tenancy-and-access-control.md) |
| `GET` | `/docs/{file?}` | Serve technical documentation markdown and assets | Web session / public | Closure in [`routes/web.php`](../routes/web.php) | [Development Guide](development.md) |

### Sample Ingestion Request Envelope

```http
POST /api/v1/ingest/batches HTTP/1.1
Host: api.platform.example.com
Authorization: Bearer 1|abcdef123456789...
Content-Type: application/json
Content-Encoding: gzip

{
  "batch_id": "a93c72b1-6a20-4e4b-97c3-37968db814d2",
  "branch_id": 4,
  "sent_at": "2026-11-02T14:30:00Z",
  "documents": [
    {
      "type": "order",
      "id": "pos-ord-90412",
      "version": 1,
      "is_deleted": false,
      "payload": {
        "order_number": "1042",
        "business_date": "2026-11-02",
        "opened_at": "2026-11-02T14:15:00Z",
        "closed_at": "2026-11-02T14:28:10Z",
        "channel": "dine_in",
        "net_total": 85.50,
        "tax_total": 12.83,
        "items": [
          {
            "product_id": 14,
            "quantity": 2,
            "unit_price": 35.00,
            "total_price": 70.00
          }
        ]
      }
    }
  ]
}
```

---

## Command-Line Interface

| Command | Purpose | Mutates Data? | Implementing Class |
|---|---|---|---|
| `php artisan ingest:recover` | Reclaims orphaned batches from crashed workers | Yes (resets batch statuses) | [`RecoverIngestBatchesCommand`](../app/Console/Commands/RecoverIngestBatchesCommand.php) |
| `php artisan metrics:rebuild` | Recalculates historical daily aggregates for date ranges | Yes (upserts aggregate tables) | [`RebuildMetricsCommand`](../app/Console/Commands/RebuildMetricsCommand.php) |
| `php artisan menu:build-intelligence` | Precomputes profiles, stats, affinities, rankings, combos | Yes (upserts menu intelligence tables) | [`BuildMenuIntelligenceCommand`](../app/Console/Commands/BuildMenuIntelligenceCommand.php) |
| `php artisan docs:serve` | Starts local documentation server and launches browser | No | [`ServeDocsCommand`](../app/Console/Commands/ServeDocsCommand.php) |
| `php artisan ai:eval` | Runs evaluation benchmark dataset against AI assistant | No (read-only evaluation) | [`AiEval`](../app/Console/Commands/AiEval.php) |
| `php artisan model:prune` | Purges expired `AiRunEvent` telemetry logs | Yes (deletes expired rows) | Built-in Laravel command |

---

## Scheduled Tasks (Cron)

Defined in [`routes/console.php`](../routes/console.php) and executed via `php artisan schedule:run`:

| Schedule | Task | Purpose |
|---|---|---|
| `* * * * *` (every minute) | `artisan ingest:recover` | Reclaim abandoned batch leases from crashed workers |
| `0 0 * * *` (daily at 00:00) | `artisan model:prune` | Purge telemetry and audit logs older than retention limit |
| `0 4 * * *` (daily at 04:00) | `ClassifyItemRoles` job | Compute BCG menu engineering quadrants for all organizations |
| `30 4 * * *` (daily at 04:30) | `BuildMenuIntelligence` job | Nightly menu intelligence build (profiles, stats, affinities, combos) |

---

## Events, Webhooks and Messages

| Name | Direction | Payload | Delivery Guarantees | Source Reference |
|---|---|---|---|---|
| **POS Heartbeat Command** | Outbound (in response to heartbeat) | `command`, `target_date`, `parameters` | At-least-once with terminal acknowledgement | [`HeartbeatController`](../app/Http/Controllers/Api/V1/HeartbeatController.php) |
| **Dirty Date Registration** | Internal (Outbox pattern) | `branch_id`, `date`, `version` | Transactional durability in `ingest_dirty_dates` | [`DirtyDateOutbox`](../app/Domain/Ingestion/Support/DirtyDateOutbox.php) |
| **AI Stream Progress (SSE)** | Outbound (Server-Sent Events) | `step`, `agent`, `status`, `content` | Real-time browser stream via Livewire | [`Assistant`](../app/Filament/App/Pages/Assistant.php) |

---

## Third-Party Integrations

| Service | Used For | Credentials (Setting Names Only) | Failure Behavior | Feature |
|---|---|---|---|---|
| **OpenAI / Azure OpenAI** | Language model inference for Assistant | `OPENAI_API_KEY`, `AZURE_OPENAI_API_KEY` | Graceful timeout error; user notified in chat | [AI Assistant](features/ai-assistant.md) |
| **Postmark / Resend** | Transactional invitation emails | `POSTMARK_API_KEY`, `RESEND_API_KEY` | Logged to queue error log; retried with exponential backoff | [Tenancy & Access Control](features/tenancy-and-access-control.md) |
| **Slack Webhooks** | Operational failure alerts | `LOG_SLACK_WEBHOOK_URL` | Non-blocking exception logged locally if webhook fails | [Operations Guide](operations.md) |
