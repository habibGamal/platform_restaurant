# 04. Ingestion API, Envelope Schema, and Document Validation Rules

This document specifies the exact contract for `POST /api/v1/ingest/batches`, including payload size constraints, gzip safety guards, HTTP response codes, envelope validation, and validation rules for all 16 POS document types.

---

## 1. Endpoint & Transport Protocol

- **Route**: `POST /api/v1/ingest/batches`
- **Controller**: [`IngestBatchController::store()`](../../app/Http/Controllers/Api/V1/IngestBatchController.php)
- **Middleware**: `auth:sanctum`, `ability:ingest`, [`EnsureBranchActive`](../../app/Http/Controllers/Api/V1/Middleware/EnsureBranchActive.php), `throttle:ingest-batches` (60 requests per minute).
- **Transport Compression**: Supports both raw UTF-8 JSON and gzip-compressed JSON (`Content-Encoding: gzip`).

---

## 2. Ingestion Limits & Gzip Safety Guard

To defend against Denial-of-Service and memory exhaustion (gzip bombs), the controller enforces strict limits:

| Constraint | Limit | HTTP Error if Exceeded |
|---|---|---|
| Raw HTTP Request Body | `5,242,880` bytes (5 MB) | `413 Payload Too Large` |
| Decompressed Stream Size | `5,242,880` bytes (5 MB) | `413 Payload Too Large` |
| Document Count per Batch | `500` documents | `413 Payload Too Large` |

### Bounded Decompression Engine ([`IngestBatchController::decompressGzip()`](../../app/Http/Controllers/Api/V1/IngestBatchController.php))
Rather than reading the entire decompressed payload into memory at once, the platform streams decompression through a bounded buffer:
- Checks the 2-byte magic header `\x1f\x8b`.
- Appends `zlib.inflate` stream filter to an in-memory stream (`php://temp`).
- Reads in 8 KB chunks with a sentinel boundary ($5\text{ MB} + 1\text{ byte}$).
- Returns `status: 'oversized'` immediately if reading exceeds 5 MB.
- Returns `status: 'malformed'` if gzip parsing encounters corrupt data.

---

## 3. Envelope Schema Specification

The top-level JSON envelope must conform to the following schema:

```json
{
  "schema_version": 1,
  "batch_uuid": "c0a80101-8b2c-493e-b87e-e21a4f000001",
  "branch_uuid": "018f2d50-93c4-7221-a15d-8b3d6812822f",
  "pos_version": "1.0.4",
  "sent_at": "2026-11-01T15:30:00+02:00",
  "seq_from": 1001,
  "seq_to": 1045,
  "documents": [ ... ]
}
```

### Envelope Validation Rules ([`IngestBatchController`](../../app/Http/Controllers/Api/V1/IngestBatchController.php))
- `schema_version`: `required | integer | in:1`
- `batch_uuid`: `required | string | uuid`
- `branch_uuid`: `required | string` (must match authenticated `$branch->uuid`, otherwise `403 Forbidden`)
- `pos_version`: `required | string`
- `sent_at`: `required | string`
- `seq_from`: `required | integer`
- `seq_to`: `required | integer`
- `documents`: `present | array` (count $\le 500$)

---

## 4. HTTP Status Codes & Error Envelope

| Status Code | Reason | Response Body Structure |
|---|---|---|
| **`202 Accepted`** | Batch received, verified, raw file persisted to disk (`storage/app/private/ingest/...`), and queued for warehouse insertion. Can contain partial document rejections. | `{"batch_uuid": "...", "accepted": ["uuid1", ...], "rejected": [{"uuid": "...", "type": "...", "reason": "..."}]}` |
| **`401 Unauthorized`** | Missing, malformed, or invalid Bearer token. | `{"message": "Unauthenticated."}` |
| **`403 Forbidden`** | Branch is suspended, or envelope `branch_uuid` does not match the token's authenticated branch. | The message depends on the cause. |
| **`409 Conflict`** | **Idempotent replay**: A batch with that `batch_uuid` already exists for the branch. The stored result is returned; an unfinished batch may be dispatched again for processing. | `{"batch_uuid": "...", "accepted": [...], "rejected": [...]}` |
| **`413 Payload Too Large`** | Body exceeds 5 MB, decompressed body exceeds 5 MB, or documents array count > 500. | `{"message": "حجم الحزمة يتجاوز الحد المسموح به..."}` |
| **`422 Unprocessable`** | Malformed JSON syntax, invalid gzip stream, or envelope fields failed validation. | `{"message": "فشل التحقق من صحة غلاف الحزمة.", "errors": {...}}` |
| **`500 Internal Error`** | Disk failure writing raw batch file to `storage/app/private/ingest/...` or queue dispatch failure. | `{"message": "حدث خطأ أثناء حفظ الحزمة."}` |

---

## 5. Common Document Envelope

Every element in the `documents` array must satisfy the common schema defined in [`DocumentRules::common()`](../../app/Domain/Ingestion/Validation/DocumentRules.php):

| Field | Validation Rules | Description |
|---|---|---|
| `type` | `required \| string \| in:DocumentRules::TYPES` | One of the 16 supported document types. |
| `uuid` | `required \| string \| size:36` | Document identifier. This rule checks string length, not UUID syntax or version. |
| `version` | `required \| integer \| min:1` | Monotonic sequential version (POS outbox row ID). Used for the version guard. |
| `op` | `required \| string \| in:upsert,delete` | Mutation operation type. |
| `occurred_at` | `required \| date` | Timestamp when the event occurred in the POS. |
| `data` | `exclude_if:op,delete \| required \| array` | Payload dictionary containing document-specific attributes. Omitted on delete. |

### Global Numeric Rule
All monetary and quantity decimals in payloads are validated via the strict regex:
```php
public const DECIMAL_REGEX = '/^-?\d+(\.\d+)?$/';
```

---

## 6. Comprehensive Field Rules for All 16 Document Types

> [!NOTE]
> In [`DocumentRules`](../../app/Domain/Ingestion/Validation/DocumentRules.php), categorical fields such as `order.type`, `order.status`, `product.type`, `expense_type.category`, and `staff.role` are validated strictly with `'string'` (or `'required_if:op,upsert', 'string'`). They do **not** enforce an enum rule (such as `Rule::in(...)`) at the ingestion validation layer. Values shown below in parentheses represent standard domain values produced by the POS, not exhaustive validation constraints enforced by the platform schema.

### 1. `order`
- `data.number`: `required_if:op,upsert | string`
- `data.shift_uuid`: `required_if:op,upsert | string`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.type`: `required_if:op,upsert | string` (common POS values: `dine_in`, `takeaway`, `delivery`, `companies`, `talabat`, `web_delivery`, `web_takeaway`, `drive_thru`, `online`)
- `data.status`: `required_if:op,upsert | string` (common POS values: `pending`, `processing`, `completed`, `cancelled`)
- `data.payment_status`: `required_if:op,upsert | string`
- `data.return_status`: `nullable | string`
- `data.created_at`: `required_if:op,upsert | date`
- `data.cashier_uuid`: `nullable | string`
- `data.driver_uuid`: `nullable | string`
- `data.customer_hash`: `nullable | string`
- `data.region`: `nullable | string`
- `data.sub_total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.discount`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.tax`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.service`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.profit`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.web_pos_diff`: `exclude_unless:op,upsert | required_if:data.type,web_delivery,web_takeaway | nullable | regex:DECIMAL_REGEX`
- `data.items`: `required_if:op,upsert | array`
  - `data.items.*.uuid`: `required_if:op,upsert | string`
  - `data.items.*.product_uuid`: `required_if:op,upsert | string`
  - `data.items.*.product_ref`: `nullable | string`
  - `data.items.*.quantity`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.price`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.unit_cost`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.item_discount`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.item_discount_type`: `nullable | string`
  - `data.items.*.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.payments`: `present_if:op,upsert | array`
  - `data.payments.*.method`: `required_if:op,upsert | string` (e.g. `cash`, `card`, `talabat_card`)
  - `data.payments.*.amount`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.payments.*.shift_uuid`: `nullable | string`
  - `data.payments.*.paid_at`: `nullable | date`

### 2. `order_return`
- `data.order_uuid`: `required_if:op,upsert | string`
- `data.shift_uuid`: `nullable | string`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.total_refund`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.reason`: `nullable | string`
- `data.reverse_stock`: `nullable | boolean`
- `data.items`: `required_if:op,upsert | array`
  - `data.items.*.order_item_uuid`: `required_if:op,upsert | string`
  - `data.items.*.quantity`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.refund_amount`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.refunds`: `present_if:op,upsert | array`
  - `data.refunds.*.method`: `required_if:op,upsert | string`
  - `data.refunds.*.amount`: `required_if:op,upsert | regex:DECIMAL_REGEX`

### 3. `shift`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.user_uuid`: `required_if:op,upsert | string`
- `data.start_at`: `required_if:op,upsert | date`
- `data.end_at`: `nullable | date`
- `data.start_cash`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.end_cash`: `nullable | regex:DECIMAL_REGEX`
- `data.real_cash`: `nullable | regex:DECIMAL_REGEX`
- `data.losses_amount`: `nullable | regex:DECIMAL_REGEX`
- `data.has_deficit`: `nullable | boolean`
- `data.closed`: `nullable | boolean`

### 4. `expense`
- `data.shift_uuid`: `nullable | string`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.expense_type_uuid`: `required_if:op,upsert | string`
- `data.amount`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.notes`: `nullable | string`

### 5. `purchase_invoice`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.closed_at`: `nullable | date`
- `data.supplier_uuid`: `required_if:op,upsert | string`
- `data.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.items`: `required_if:op,upsert | array`
  - `data.items.*.product_uuid`: `required_if:op,upsert | string`
  - `data.items.*.quantity`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.price`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`

### 6. `purchase_return`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.closed_at`: `nullable | date`
- `data.supplier_uuid`: `required_if:op,upsert | string`
- `data.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.items`: `required_if:op,upsert | array`
  - `data.items.*.product_uuid`: `required_if:op,upsert | string`
  - `data.items.*.quantity`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.price`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`

### 7. `waste`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.closed_at`: `nullable | date`
- `data.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.items`: `required_if:op,upsert | array`
  - `data.items.*.product_uuid`: `required_if:op,upsert | string`
  - `data.items.*.quantity`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.price`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`

### 8. `stocktaking`
- `data.business_date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.closed_at`: `nullable | date`
- `data.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.items`: `required_if:op,upsert | array`
  - `data.items.*.product_uuid`: `required_if:op,upsert | string`
  - `data.items.*.stock_quantity`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.real_quantity`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.price`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.items.*.total`: `required_if:op,upsert | regex:DECIMAL_REGEX`

### 9. `inventory_day`
- `data.date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.rows`: `present_if:op,upsert | array`
  - `data.rows.*.product_uuid`: `required_if:op,upsert | string`
  - `data.rows.*.start_qty`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.rows.*.incoming_qty`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.rows.*.sales_qty`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.rows.*.return_sales_qty`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.rows.*.waste_qty`: `required_if:op,upsert | regex:DECIMAL_REGEX`
  - `data.rows.*.end_qty`: `required_if:op,upsert | regex:DECIMAL_REGEX`

### 10. `day_summary`
- `data.date`: `required_if:op,upsert | date_format:Y-m-d`
- `data.orders_count`: `required_if:op,upsert | integer`
- `data.completed_count`: `required_if:op,upsert | integer`
- `data.net_total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.discount_total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.cogs_total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.expenses_total`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.waste_total`: `required_if:op,upsert | regex:DECIMAL_REGEX`

### 11. `product`
- `data.name`: `required_if:op,upsert | string`
- `data.product_ref`: `nullable | string`
- `data.category_uuid`: `nullable | string`
- `data.type`: `required_if:op,upsert | string` (e.g. `manufactured`, `raw_material`, `consumable`)
- `data.unit`: `nullable | string`
- `data.price`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.cost`: `required_if:op,upsert | regex:DECIMAL_REGEX`
- `data.legacy`: `nullable | boolean`

### 12. `recipe`
- `data.product_uuid`: `required_if:op,upsert | string`
- `data.components`: `present_if:op,upsert | array`
  - `data.components.*.component_uuid`: `required_if:op,upsert | string`
  - `data.components.*.quantity`: `required_if:op,upsert | regex:DECIMAL_REGEX`

### 13. `category`
- `data.name`: `required_if:op,upsert | string`

### 14. `supplier`
- `data.name`: `required_if:op,upsert | string`
- `data.phone`: `nullable | string`

### 15. `expense_type`
- `data.name`: `required_if:op,upsert | string`
- `data.category`: `required_if:op,upsert | string` (e.g. `labor`, `rent`, `utilities`, `supplies`, `other`)
- `data.avg_month_rate`: `nullable | regex:DECIMAL_REGEX` (monthly budget rate used for overhead allocation)

### 16. `staff`
- `data.name`: `required_if:op,upsert | string`
- `data.role`: `required_if:op,upsert | string` (e.g. `cashier`, `manager`, `waiter`, `kitchen`, `driver`)
