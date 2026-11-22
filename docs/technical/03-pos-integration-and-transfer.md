# 03. POS Pairing, Authentication, Heartbeat, and Transfer Pipeline

This document explains the end-to-end integration between decentralized Point of Sale (POS) nodes (`larament`) and the central reporting platform (`platform-restaurants`), covering branch pairing, token rotation, API authentication, rate limiting, the heartbeat command protocol, and outbox synchronization.

---

## 1. POS Pairing Lifecycle

Connecting a decentralized POS installation to the central platform requires an explicit two-step cryptographic handshake:

```
Central Platform (/app)                   Decentralized POS (larament)
       |                                                |
1. Admin generates 8-char pairing code                  |
   (BranchPairingCode created, expires in 15 min)       |
       |                                                |
       |----- (Admin shares Code & Platform URL) ------>|
       |                                                |
       |                                    2. User enters Code & URL in POS
       |                                       POST /api/v1/pair
       |<-------------------------------------- Headers: X-Schema-Version: 1
       |                                       Body: {code: "...", pos_version: "1.0.0"}
3. PairingCodeService::redeem($code)                    |
   - Validates code & 15-minute expiration              |
   - Sets branch.status = 'active'                      |
   - Deletes prior Sanctum tokens                       |
   - Issues new Sanctum token (ability: 'ingest')       |
   - HTTP 200 Response:                                 |
     {branch_uuid, token, branch_secret, timezone}      |
       |----------------------------------------------->|
       |                                    4. PairingService::pair() stores
       |                                       PlatformConnection record
       |                                       Schedules initial 90-day backfill
```

### Endpoints & Source Implementation

| Stage | Platform Handler | Paired POS Sender |
|---|---|---|
| Code Generation | [`PairingCodeService::generate()`](../../app/Domain/Tenancy/PairingCodeService.php) | Triggered via UI in `larament:app/Filament/Pages/PlatformSettings.php` |
| Handshake | [`PairController::pair()`](../../app/Http/Controllers/Api/V1/PairController.php) | `larament:app/Sync/PlatformClient.php` (`PlatformClient::pair()`) |
| Local Persistence | N/A (platform database) | `larament:app/Sync/PairingService.php` (`PairingService::pair()`) |

### Pairing Request Payload
- **Endpoint**: `POST /api/v1/pair`
- **Rate Limit**: 10 requests per minute by client IP (`throttle:ingest-pair`).
- **Headers**:
  - `Accept`: `application/json`
  - `X-Schema-Version`: `1`
  - `X-Pos-Version`: `<pos_version>` (e.g. `1.0.0`)
- **Body**:
  ```json
  {
    "code": "A8X2K9LP",
    "pos_version": "1.0.4"
  }
  ```

### Pairing Response Payload (`HTTP 200 OK`)
```json
{
  "branch_uuid": "018f2d50-93c4-7221-a15d-8b3d6812822f",
  "token": "1|qW4e...plaintext_sanctum_token...",
  "branch_secret": "e4f8a901c27b...",
  "timezone": "Africa/Cairo"
}
```

If the pairing code is expired (after 15 minutes), already redeemed, or invalid, the platform responds with `HTTP 422 Unprocessable Entity`:
```json
{
  "message": "رمز الربط غير صالح أو منتهي"
}
```

---

## 2. API Authentication & Token Rotation

### Authentication Middleware
All authenticated endpoints under [`routes/api.php`](../../routes/api.php) (`/api/v1/pair/rotate`, `/api/v1/ingest/batches`, `/api/v1/heartbeat`) enforce a three-tier middleware stack:

1. `auth:sanctum`: Verifies the Bearer token in the `Authorization` header against the `personal_access_tokens` table.
2. `ability:ingest`: Ensures the token tokenable possesses the specific `ingest` ability granted in [`Branch::issueIngestToken()`](../../app/Models/Branch.php).
3. [`EnsureBranchActive`](../../app/Http/Controllers/Api/V1/Middleware/EnsureBranchActive.php): Rejects a branch whose status is `SUSPENDED` with HTTP 403. It does not itself require `ACTIVE`; the controllers also verify the authenticated principal is a `Branch`.

### Token Rotation Flow
Token rotation can be triggered automatically via a heartbeat command or manually:

1. **POS Action**: POS sends `POST /api/v1/pair/rotate` using its current active Bearer token.
2. **Platform Action**: [`PairController::rotate()`](../../app/Http/Controllers/Api/V1/PairController.php) executes `Branch::issueIngestToken()`, which acquires a pessimistic row lock (`lockForUpdate()`), deletes all previous Sanctum tokens for this branch, and generates a new plaintext token.
3. **Response (`HTTP 200 OK`)**:
   ```json
   {
     "token": "2|new_plaintext_sanctum_token..."
   }
   ```
4. **POS Update**: POS updates its local `platform_connections.token` column and invalidates its memory cache via `larament:app/Sync/HeartbeatService.php` (`PlatformConnection::flushCache()`).

---

## 3. Rate Limiting & Throttles

Configured in [`IngestApiServiceProvider`](../../app/Providers/IngestApiServiceProvider.php):

| Throttle Name | Route | Limit | Key Resolution |
|---|---|---|---|
| `ingest-pair` / `ingest.pair` | `POST /api/v1/pair` | 10 per minute | `$request->ip()` |
| `ingest-batches` / `ingest.batches` | `POST /api/v1/ingest/batches` | 60 per minute | `$request->user()?->getKey() ?? $request->ip()` |

When a limit is exceeded, Laravel returns standard `HTTP 429 Too Many Requests` with a `Retry-After` header.

---

## 4. Heartbeat Protocol & Bidirectional Commands

The POS sends a periodic heartbeat to notify the platform of its operational health and retrieve pending remote commands.

### Heartbeat Request Payload
- **Endpoint**: `POST /api/v1/heartbeat`
- **Frequency**: Every 5 minutes via POS scheduler (`larament:routes/console.php`: `Schedule::command('platform:heartbeat')->everyFiveMinutes()`).
- **Body**:
  ```json
  {
    "pos_version": "1.0.4",
    "pending_count": 12,
    "oldest_pending_at": "2026-11-01T13:45:00+02:00",
    "failed_count": 0,
    "last_success_at": "2026-11-01T15:30:12+02:00",
    "server_time": "2026-11-01T15:35:00+02:00",
    "business_date_open": "2026-11-01"
  }
  ```

### Platform Heartbeat Processing ([`HeartbeatController::store()`](../../app/Http/Controllers/Api/V1/HeartbeatController.php))
1. Validates schema and extracts telemetry (`pos_version`, `pending_count`, `oldest_pending_at`, `failed_count`, `last_success_at`, `server_time`, `business_date_open`).
2. Updates `branches` table columns: `pos_version`, `pending_count`, `failed_count`, `oldest_pending_at`, `last_heartbeat_at = now()`, `last_seen_at = now()`.
3. Queries undelivered commands from `branch_commands` where `branch_id = $branch->id AND delivered_at IS NULL` ordered by `id ASC`.
4. Updates fetched commands to `delivered_at = now()`.
5. Returns commands array and `min_supported_schema: 1`.

Commands are marked delivered before the response is processed by the POS. There is no POS execution acknowledgement in this exchange; a lost response or handler failure can leave a command marked delivered without completion.

### Heartbeat Response Payload (`HTTP 200 OK`)
```json
{
  "commands": [
    {
      "type": "resync",
      "from": "2026-10-25",
      "to": "2026-10-25"
    }
  ],
  "min_supported_schema": 1
}
```

### Supported Remote Commands

| Command Type | Payload Fields | Purpose | POS Handler Implementation |
|---|---|---|---|
| `resync` | `from: "YYYY-MM-DD"`, `to: "YYYY-MM-DD"` | Instructs POS to re-queue all documents for the given date range into the outbox. Created when reconciliation detects discrepancies. | `larament:app/Sync/HeartbeatService.php` invokes `BackfillService::queueRange($from, $to)`. |
| `rotate_token` | *(none)* | Forces the POS to call `/api/v1/pair/rotate` and update its Bearer token immediately. | `larament:app/Sync/HeartbeatService.php` |
Other command types are ignored by the current POS heartbeat handler; `pause` and `resume` are not implemented there.

---

## 5. POS Outbound Transfer Engine (`larament`)

### 1. Document Capture via Eloquent Observers
Whenever a transactional model in the POS is created, updated, or deleted, `larament:app/Sync/SyncOutboxObserver.php` intercepts the event and writes an entry to `sync_outbox`.

- Closed entity gating: `PurchaseInvoice`, `ReturnPurchaseInvoice`, `Waste`, and `Stocktaking` are only enqueued once `closed_at !== null`.
- Cascading order trigger: Modifying an `OrderItem` or `Payment` enqueues the parent `Order`. An `OrderReturn` enqueues both the return and the original order.

### 2. Batch Construction & Deduplication (`larament:app/Sync/BatchSender.php`)
1. **Batch Assembly**: Picks up to 500 due rows (`sent_at IS NULL AND failed_at IS NULL AND next_attempt_at <= now()`) locked with `lockForUpdate()`.
2. **Coalescing**: If multiple mutations for the same `(doc_type, doc_uuid)` exist in the same batch, the POS coalesces them, selecting the row with the highest sequence ID as the authoritative version.
3. **Payload Construction**: Invokes the corresponding builder from `larament:app/Sync/BuilderRegistry.php`. If the record was hard-deleted locally, the operation is converted to `op: 'delete'` with `data: null`.
4. **Compression**: Encodes the envelope to JSON and compresses it using `gzencode($json, 6)`.
5. **Transport**: Issues a POST request to `/api/v1/ingest/batches` with:
   - Header `Content-Type`: `application/json`
   - Header `Content-Encoding`: `gzip`
   - Header `Idempotency-Key`: `$batchUuid`
   - Header `X-Schema-Version`: `1`

### 3. POS Retry & Exponential Backoff Sequence
When a network error or HTTP 429/5xx error occurs, the POS does not drop the batch. It recalculates `next_attempt_at` for each row using the sequence defined in `larament:app/Sync/BatchSender.php`:

$$\text{Backoff Minutes} = [1 \to 2 \to 5 \to 15 \to 30 \to 60]$$

- If attempts reach `10`, the row is marked `failed_at = now()`.
- On `HTTP 401`: Token was revoked; the connection status transitions to `revoked`.
- On `HTTP 413` (Payload Too Large): The batch is split; if already a single record, backoff is applied.
- On `HTTP 202` / `HTTP 409`: The response `accepted` and `rejected` arrays are inspected. Accepted rows are marked `sent_at = now()`; rejected rows have their error logged and are released back to the outbox with incremented attempts.
