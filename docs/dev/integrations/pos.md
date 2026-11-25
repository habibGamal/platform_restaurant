# POS -> Platform Integration Contract (v1)

This document is the canonical HTTP and schema contract between a decentralized **`larament`** POS branch installation and **`platform-restaurants`**. Every field, limit, rate limit, and status code below is verified against `routes/api.php`, `app/Http/Controllers/Api/V1/*`, `app/Domain/Ingestion/Validation/DocumentRules.php`, `app/Providers/IngestApiServiceProvider.php`, and `app/Domain/Tenancy/PairingCodeService.php`.

---

## 1. Authentication & Route Surface

Defined in `routes/api.php` under `/api/v1`:

| Method | Path | Middleware | Purpose |
|---|---|---|---|
| `POST` | `/api/v1/pair` | `throttle:ingest-pair` | Redeem an 8-char pairing code and receive a branch token |
| `POST` | `/api/v1/pair/rotate` | `auth:sanctum`, `ability:ingest`, `EnsureBranchActive` | Rotate the branch's Sanctum bearer token |
| `POST` | `/api/v1/ingest/batches` | `auth:sanctum`, `ability:ingest`, `EnsureBranchActive`, `throttle:ingest-batches` | Submit a JSON or gzip-compressed batch of POS documents |
| `POST` | `/api/v1/heartbeat` | `auth:sanctum`, `ability:ingest`, `EnsureBranchActive` | Report POS telemetry and pull pending platform commands |

### Rate Limits (`IngestApiServiceProvider`)

| Limiter | Applied to | Limit | Key |
|---|---|---|---|
| `ingest-pair` | `POST /api/v1/pair` | 10 req/min | `$request->ip()` |
| `ingest-batches` | `POST /api/v1/ingest/batches` | 60 req/min | `$request->user()?->getKey() ?? $request->ip()` |

### `EnsureBranchActive` Middleware

- Rejects requests whose authenticated `Branch` has `status === 'suspended'` with **`403 Forbidden`**: `{"message": "الفرع معلق"}`.
- Controllers also verify `$request->user('sanctum') instanceof Branch`, returning `401 Unauthenticated.` otherwise.

---

## 2. Pairing & Token Rotation

### 2.1 `POST /api/v1/pair`

1. An operator or organization manager generates a pairing code for a branch via `PairingCodeService::generate()`.
   - Alphabet: `23456789ABCDEFGHJKLMNPQRSTUVWXYZ` (8 characters, no ambiguous `0/O/1/I`).
   - Stored as `sha256($code)` in `branch_pairing_codes` with a **15-minute TTL**.
   - Generating a new code invalidates all prior unused codes for that branch.
2. POS sends:
   ```json
   {
     "code": "A8X2K9LP",
     "pos_version": "1.0.4"
   }
   ```
   (`pos_version` is optional; if present and non-empty, updates `branches.pos_version`.)
3. `PairingCodeService::redeem($code)` normalizes `strtoupper(trim($code))`, locks the unused unexpired row with `lockForUpdate()`, sets `used_at = now()`, transitions `branches.status = 'active'`, deletes prior Sanctum tokens via `Branch::issueIngestToken()`, and creates a new Sanctum token named `'pos'` with ability `['ingest']`.
4. **Responses:**
   - **`200 OK`**:
     ```json
     {
       "branch_uuid": "018f2d50-93c4-7221-a15d-8b3d6812822f",
       "token": "1|plaintext_sanctum_token...",
       "branch_secret": "40_char_secret_for_customer_hash",
       "timezone": "Africa/Cairo"
     }
     ```
   - **`422 Unprocessable Entity`** (invalid, already used, or expired code):
     ```json
     {
       "message": "رمز الربط غير صالح أو منتهي"
     }
     ```

### 2.2 `POST /api/v1/pair/rotate`

- Authenticated with the current Bearer token (`ability:ingest`).
- Calls `Branch::issueIngestToken()` inside a transaction with `lockForUpdate()`, **immediately deleting all previous Sanctum tokens** for that branch and returning **`200 OK`**:
  ```json
  {
    "token": "2|new_plaintext_sanctum_token..."
  }
  ```

---

## 3. Heartbeat & Platform -> POS Commands (`POST /api/v1/heartbeat`)

Called periodically by the POS (every 5 minutes) to report queue health and retrieve commands. Because the POS is behind NAT, this response is the only platform-to-POS channel.

### Request Body (`HeartbeatController::store`)

| Field | Validation | Stored on `branches` |
|---|---|---|
| `pos_version` | `required \| string` | `pos_version` |
| `pending_count` | `nullable \| integer` | `pending_count` (clamped `>= 0`) |
| `oldest_pending_at` | `nullable \| date` | `oldest_pending_at` |
| `failed_count` | `nullable \| integer` | `failed_count` (clamped `>= 0`) |
| `last_success_at` | `nullable \| date` | Accepted (telemetry) |
| `server_time` | `nullable \| date` | Accepted (telemetry) |
| `business_date_open` | `nullable \| date` | Accepted (telemetry) |

Also updates `branches.last_heartbeat_at = now()` and `branches.last_seen_at = now()`.

### Response (`200 OK`)

Fetches undelivered `branch_commands` (`whereNull('delivered_at')->orderBy('id')`), marks them `delivered_at = now()`, and returns:

```json
{
  "commands": [
    { "type": "resync", "from": "2026-10-25", "to": "2026-10-25" },
    { "type": "rotate_token" }
  ],
  "min_supported_schema": 1
}
```

- `resync`: queued automatically by `Reconciler` (up to 3 attempts) or manually from Data Health when POS `day_summary` diverges from platform aggregates.
- `rotate_token`: instructs the POS to call `POST /api/v1/pair/rotate`.

---

## 4. Batch Ingestion (`POST /api/v1/ingest/batches`)

### 4.1 Transport, Limits & Bounded Gzip

`IngestBatchController::store()` accepts raw UTF-8 JSON or gzip-compressed JSON (`Content-Encoding: gzip`).

| Constraint | Limit | Error Response |
|---|---|---|
| Raw request body size | `5,242,880` bytes (5 MB) | `413 Payload Too Large` |
| Decompressed gzip size | `5,242,880` bytes (5 MB) | `413 Payload Too Large` |
| `documents` array count | `500` items | `413 Payload Too Large` |

- Gzip decompression checks the `\x1f\x8b` header and minimum 18-byte length, then streams via `zlib.inflate` in 8 KB chunks up to `MAX_BODY_BYTES + 1` bytes to prevent gzip bombs. Malformed gzip or invalid JSON returns **`422`**.

### 4.2 Top-Level Envelope

```json
{
  "schema_version": 1,
  "batch_uuid": "c0a80101-8b2c-493e-b87e-e21a4f000001",
  "branch_uuid": "018f2d50-93c4-7221-a15d-8b3d6812822f",
  "pos_version": "1.0.4",
  "sent_at": "2026-11-01T15:30:00+02:00",
  "seq_from": 1001,
  "seq_to": 1045,
  "documents": []
}
```

Envelope validation rules:
- `schema_version`: `required | integer | in:1`
- `batch_uuid`: `required | string | uuid`
- `branch_uuid`: `required | string` (must equal authenticated `$branch->uuid`, else `403 Forbidden`)
- `pos_version`: `required | string`
- `sent_at`: `required | string`
- `seq_from`: `required | integer`
- `seq_to`: `required | integer`
- `documents`: `present | array`

### 4.3 HTTP Responses & Idempotency

Before processing documents, the controller acquires `Branch::where('id', $branch->id)->lockForUpdate()->first()` and checks `ingest_batches` for `(branch_id, batch_uuid)`:

| Status | Meaning | Response Shape |
|---|---|---|
| **`202 Accepted`** | Raw payload persisted to `local` disk (`storage/app/private/ingest/{org_id}/{branch_id}/{YYYY}/{MM}/{batch_uuid}.{json\|json.gz}`), `ingest_batches` + `ingest_rejections` written, `ProcessIngestBatch` dispatched after commit. | `{"batch_uuid": "...", "accepted": ["uuid1"], "rejected": [{"uuid": "...", "type": "...", "reason": "..."}]}` |
| **`409 Conflict`** | `(branch_id, batch_uuid)` already exists. Returns the stored `accepted`/`rejected` result; if `status !== 'processed'`, re-dispatches `ProcessIngestBatch`. | `{"batch_uuid": "...", "accepted": [...], "rejected": [...]}` |
| **`401 Unauthorized`** | Missing or invalid Bearer token. | `{"message": "Unauthenticated."}` |
| **`403 Forbidden`** | Branch is `suspended` or `branch_uuid` does not match the token's branch. | `{"message": "..."}` |
| **`413 Payload Too Large`** | Compressed body > 5 MB, decompressed body > 5 MB, or `count(documents) > 500`. | `{"message": "..."}` |
| **`422 Unprocessable`** | Malformed gzip, invalid JSON, or invalid envelope fields. | `{"message": "...", "errors": {...}}` |
| **`500 Internal Error`** | Storage write failure or queue dispatch failure. | `{"message": "..."}` |

---

## 5. Common Document Structure (`DocumentRules::common()`)

Validation failures on individual documents reject **only that document** (recorded in `rejected[]` and `ingest_rejections`), never the entire batch.

| Field | Rule | Notes |
|---|---|---|
| `type` | `required \| string \| in:DocumentRules::TYPES` | One of the 16 types below |
| `uuid` | `required \| string \| size:36` | Document UUID string (36 chars) |
| `version` | `required \| integer \| min:1` | Monotonic POS outbox sequence; platform skips if `stored source_version >= version` |
| `op` | `required \| string \| in:upsert,delete` | `delete` soft-deletes existing warehouse/dimension rows (`deleted_at = now()`) |
| `occurred_at` | `required \| date` | Event timestamp |
| `data` | `exclude_if:op,delete \| required \| array` | Omitted on `delete` |

All numeric monetary/quantity fields are strings validated against `DocumentRules::DECIMAL_REGEX = '/^-?\d+(\.\d+)?$/'` (money 2 dp, quantities 3 dp). `business_date` (`YYYY-MM-DD`) is always owned and sent by the POS in branch local time (`Africa/Cairo` by default).

**Never transferred:** user passwords, sessions, printer configs, POS settings, dine tables, or customer PII (customers appear only as `customer_hash`).

---

## 6. All 16 Document Types (`DocumentRules::typeRules()`)

> [!NOTE]
> Categorical strings (`order.type`, `order.status`, `product.type`, `expense_type.category`, `staff.role`) are validated as `'string'` at ingest rather than strict enum `Rule::in(...)` so new POS values do not break batch ingestion.

### 1. `order`
- `data.number`: `required_if:op,upsert | string`
- `data.shift_uuid`: `required_if:op,upsert | string`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.type`: `required_if:op,upsert | string` (e.g. `dine_in`, `takeaway`, `delivery`, `companies`, `talabat`, `web_delivery`, `web_takeaway`)
- `data.status`: `required_if:op,upsert | string` (e.g. `pending`, `processing`, `out_for_delivery`, `completed`, `cancelled`)
- `data.payment_status`: `required_if:op,upsert | string`
- `data.return_status`: `nullable | string`
- `data.created_at`: `required_if:op,upsert | date`
- `data.cashier_uuid`: `nullable | string`
- `data.driver_uuid`: `nullable | string`
- `data.customer_hash`: `nullable | string`
- `data.region`: `nullable | string`
- `data.sub_total`, `discount`, `tax`, `service`, `total`, `profit`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.web_pos_diff`: `exclude_unless:op,upsert | required_if:data.type,web_delivery,web_takeaway | nullable | regex:DECIMAL_REGEX`
- `data.items`: `required_if:op,upsert | array`
  - `*.uuid`, `*.product_uuid`: `required_if:op,upsert | string`
  - `*.product_ref`, `*.item_discount_type`: `nullable | string`
  - `*.quantity`, `*.price`, `*.unit_cost`, `*.item_discount`, `*.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.payments`: `present_if:op,upsert | array`
  - `*.method`: `required_if:op,upsert | string`
  - `*.amount`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `*.shift_uuid`: `nullable | string`
  - `*.paid_at`: `nullable | date`

### 2. `order_return`
- `data.order_uuid`: `required_if:op,upsert | string`
- `data.shift_uuid`: `nullable | string`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.total_refund`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.reason`: `nullable | string`
- `data.reverse_stock`: `nullable | boolean`
- `data.items`: `required_if:op,upsert | array` (`*.order_item_uuid` string; `*.quantity`, `*.refund_amount` decimal)
- `data.refunds`: `present_if:op,upsert | array` (`*.method` string; `*.amount` decimal)

### 3. `shift`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.user_uuid`: `required_if:op,upsert | string`
- `data.start_at`: `required_if:op,upsert | date`
- `data.end_at`: `nullable | date`
- `data.start_cash`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.end_cash`, `real_cash`, `losses_amount`: `nullable | regex:DECIMAL_REGEX`
- `data.has_deficit`, `closed`: `nullable | boolean`

### 4. `expense`
- `data.shift_uuid`: `nullable | string`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.expense_type_uuid`: `required_if:op,upsert | string`
- `data.amount`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.notes`: `nullable | string`

### 5. `purchase_invoice` & 6. `purchase_return`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.closed_at`: `nullable | date`
- `data.supplier_uuid`: `required_if:op,upsert | string`
- `data.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.items`: `required_if:op,upsert | array` (`*.product_uuid` string; `*.quantity`, `*.price`, `*.total` decimal)

### 7. `waste`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.closed_at`: `nullable | date`
- `data.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.items`: `required_if:op,upsert | array` (`*.product_uuid` string; `*.quantity`, `*.price`, `*.total` decimal)

### 8. `stocktaking`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.closed_at`: `nullable | date`
- `data.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.items`: `required_if:op,upsert | array` (`*.product_uuid` string; `*.stock_quantity`, `*.real_quantity`, `*.price`, `*.total` decimal)

### 9. `inventory_day`
- `data.date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.rows`: `present_if:op,upsert | array` (`*.product_uuid` string; `*.start_qty`, `*.incoming_qty`, `*.sales_qty`, `*.return_sales_qty`, `*.waste_qty`, `*.end_qty` decimal)

### 10. `day_summary`
- `data.date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.orders_count`, `completed_count`: `required_if:op,upsert | integer`
- `data.net_total`, `discount_total`, `cogs_total`, `expenses_total`, `waste_total`: `required_if:op,upsert | regex:DECIMAL_REGEX`

### 11. `product`
- `data.name`: `required_if:op,upsert | string`
- `data.product_ref`, `category_uuid`, `unit`: `nullable | string`
- `data.type`: `required_if:op,upsert | string` (e.g. `manufactured`, `raw_material`, `consumable`)
- `data.price`, `cost`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.legacy`: `nullable | boolean`

### 12. `recipe`
- `data.product_uuid`: `required_if:op,upsert | string`
- `data.components`: `present_if:op,upsert | array` (`*.component_uuid` string; `*.quantity` decimal)

### 13. `category`
- `data.name`: `required_if:op,upsert | string`

### 14. `supplier`
- `data.name`: `required_if:op,upsert | string`
- `data.phone`: `nullable | string`

### 15. `expense_type`
- `data.name`: `required_if:op,upsert | string`
- `data.category`: `required_if:op,upsert | string` (e.g. `labor`, `rent`, `utilities`, `marketing`, `maintenance`, `raw_materials`, `other`)
- `data.avg_month_rate`: `nullable | regex:DECIMAL_REGEX`

### 16. `staff`
- `data.name`: `required_if:op,upsert | string`
- `data.role`: `required_if:op,upsert | string`
