# Platform Restaurants — Architecture

> Multi-restaurant reporting platform. Each restaurant branch runs its own **larament POS**
> (on-premise, behind NAT). Branches push their data to this platform, which aggregates it into
> group dashboards, comparisons, targets, alerts and (paid) AI insights.
>
> Status: design. Date: 2026-10-29.
> POS-side counterpart: `D:\ai_rewrite_turbo_restaurant\larament\.plan\platform-connector-plan.md`

---

## 1. Requirements

### Functional (from the business brief)

| # | Area | Requirement | Plan file |
|---|---|---|---|
| 1 | لوحة التحكم الرئيسية | Total sales, net profit and margin per branch, day/week/month comparison, total expenses and purchases | `reports/01-group-overview-dashboard.md` |
| 2 | مقارنة الأداء | Sales, orders, avg ticket | `reports/02-sales-comparison.md` |
| 2 | | Profit and margins | `reports/03-profitability-comparison.md` |
| 2 | | Raw-material cost and waste % | `reports/04-food-cost-and-waste.md` |
| 2 | | Best / worst selling items | `reports/05-product-performance.md` |
| 2 | | Shifts and weekdays | `reports/06-shifts-weekdays-hours.md` |
| 2 | | Best / worst branch ranking | `reports/07-branch-ranking.md` |
| 2 | | Targets vs actual | `reports/08-targets-vs-actual.md` |
| 1 | | Expenses and purchases breakdown | `reports/09-expenses-and-purchases.md` |
| — | (platform need) | Data freshness, sync health per branch | `reports/10-branch-sync-health.md` |
| 3 | AI (paid) | Growth vs previous periods | `reports/11-ai-growth-analysis.md` |
| 3 | | Unusual sales drop / cost rise | `reports/12-ai-anomaly-detection.md` |
| 3 | | Each branch's contribution to group profit | `reports/13-ai-profit-contribution.md` |
| 3 | | Labor and raw-material cost as % of sales | `reports/14-ai-cost-ratios.md` |
| 3 | | Sales and needs forecasting | `reports/15-ai-forecasting.md` |
| 3 | | Items to reprice or discontinue | `reports/16-ai-menu-suggestions.md` |
| 3 | | Daily WhatsApp report | `reports/17-ai-daily-whatsapp-report.md` |
| 3 | | Alerts: below target, waste/expense spikes | `reports/18-ai-alerts.md` |
| 4 | الإدارة المركزية | Central account, per-branch managers, compare selected branches or whole group | `features/access-control.md` |
| 4 | | Sales and profit targets per branch | `features/targets-management.md` |

Shared definitions (period rules, KPI formulas, partial-data handling) live in
`reports/00-conventions.md`. **Every report must use those formulas — never re-define a KPI locally.**

### Non-functional

| Concern | Target |
|---|---|
| Scale (year 1) | 10 organizations, 50 branches, ~500 orders/branch/day → ~25k orders, ~125k order items per day |
| Freshness | Data visible ≤ 5 min after the POS event when the branch is online |
| Dashboard latency | p95 < 500 ms (reads only aggregate tables) |
| Data loss | Zero. Branch offline for days must catch up automatically |
| Correctness | Platform totals reconcile with the POS day checksum (`day_summary`) every day |
| Locale | Arabic UI (RTL), currency EGP, timezone per branch (default `Africa/Cairo`) |
| Security | POS never opens an inbound port; per-branch revocable token; tenant isolation on every query |

### Constraints

- Team knows Laravel + Filament + React/Inertia → stay on that stack, one modular monolith.
- The POS (larament) is installed per restaurant; upgrades roll out gradually, so the
  ingestion contract must be **versioned** and backwards compatible.

---

## 2. Stack (as installed)

| Layer | Choice | Version |
|---|---|---|
| Framework | Laravel | 13.x |
| **All UI** (analytics + back office) | FilamentPHP panels (Livewire, Chart.js charts via `ChartWidget`) | 5.11 |
| Starter kit leftovers | Inertia v3 + React 19 starter kit stays installed but unused for the product UI (may be removed later) | — |
| Auth | Laravel Fortify (starter kit) — 2FA + passkeys enabled | — |
| Routes in JS | Laravel Wayfinder | — |
| Database | **MySQL 8 / MariaDB ≥ 10.4** for now (local: XAMPP MariaDB 10.4 on 3306). SQL must be portable to both: use `INSERT … ON DUPLICATE KEY UPDATE` (Laravel `upsert`), version guards done in PHP, `json` columns (no `json`), no table partitioning yet. PostgreSQL remains the upgrade path when volume demands it | — |
| Queue / cache | `database` queue + cache now; Redis + Horizon in production | — |
| Realtime | Filament widget polling (`$pollingInterval`) for live dashboards; Reverb later if needed | — |
| Object storage | S3-compatible (raw ingest batches, generated PDFs) | — |
| AI | Anthropic API (Claude) for narratives; statistics computed in PHP/SQL (optional Python service for forecasting later) | — |
| WhatsApp | Meta WhatsApp Business Cloud API (template messages) | — |

### UI split

The whole product UI is **Filament** (decision 2026-10-29). Two panels:

- `admin` panel (`/admin`, existing `AdminPanelProvider`) — **platform operator**: organizations, restaurants, branches, pairing codes, ingestion monitor, rejections, reconciliation issues.
- `app` panel (`/app`, `AppPanelProvider`) — **organization users** (owner, analyst, branch manager):
  - *Reports* navigation group: reports 01–10 as Filament **pages** composed of widgets
    (`StatsOverviewWidget` for KPI cards, `ChartWidget` for charts, `TableWidget` / page tables for matrices).
  - *Management* group (role-gated): users & branch access, targets grid, branch sets, alert rules, recipients, expense-category mapping.
  - Scoping: no Filament tenancy switching — a user belongs to one organization; every query is scoped by `organization_id` + `AccessibleBranches`.
- Locale `ar` (Filament renders RTL automatically), timezone `Africa/Cairo`.
- Report filters (period, comparison, branches) live in a shared page trait and are kept in the URL query string (`#[Url]` Livewire properties) so views are shareable.

> The starter kit's Fortify registration is irrelevant to Filament panels (each panel has its own login).
> Users are created by operators / org owners; Filament panel registration stays off.

---

## 3. High-level design

```
 ┌──────── Branch A (larament POS, on-prem) ────────┐
 │ business services ──(same DB tx)──► sync_outbox  │
 │ platform:sync (every minute) ──► gzip JSON batch │──┐ HTTPS (outbound only)
 │ platform:heartbeat (every 5 min)                 │  │ Bearer <branch token>
 └──────────────────────────────────────────────────┘  │
 ┌──────── Branch B … N ────────────────────────────┐  │
 └──────────────────────────────────────────────────┘  ▼
┌───────────────────────────── Platform (this app) ───────────────────────────────┐
│  [Ingestion]   POST /api/v1/ingest/batches   POST /api/v1/heartbeat               │
│      │  auth branch token → idempotency (batch_uuid) → JSON-schema validate       │
│      │  store raw batch (S3) → ingest_batches row → 202 {accepted, rejected}       │
│      ▼                                                                             │
│  [Queue: ingest]  ProcessIngestBatch  → upsert raw tables (version-guarded)        │
│      ▼  dirty set {(branch, business_date)}                                        │
│  [Queue: aggregate] RecomputeBranchDay (unique, debounced 30 s)                    │
│      ▼  rebuild aggregate rows for that branch/day                                 │
│  event BranchDayUpdated ─┬─► Alerts engine (rules) ─► notifications ─► WhatsApp     │
│                          ├─► Reconciliation vs day_summary ─► resync request        │
│                          └─► Reverb broadcast → live dashboards                     │
│                                                                                     │
│  [Read side]  React analytics  ◄── MetricsQuery services ◄── aggregate tables       │
│  [AI module]  scheduled jobs: anomalies, forecasts, menu engineering, LLM narrative │
│  [Back office] Filament operator + manage panels                                    │
└─────────────────────────────────────────────────────────────────────────────────────┘
```

### Module layout (inside `app/`)

```
app/
  Domain/
    Tenancy/        Organization, Restaurant, Branch, BranchToken, pairing
    Ingestion/      controllers, DocumentValidator, handlers per doc type, ProcessIngestBatch
    Warehouse/      raw models (SalesOrder, Shift, Expense, …) — read-only outside Ingestion
    Metrics/        Aggregators (RecomputeBranchDay), MetricsQuery, KPI formulas (single source)
    Targets/        Target model, daily distribution
    Alerts/         rules, evaluator, notification channels
    AI/             anomalies, forecasting, menu engineering, narrative generator
    Messaging/      WhatsApp client, templates, recipients
  Filament/Admin/   operator panel resources/pages
  Filament/App/     org panel: Pages/Reports/*, Widgets/Reports/*, Resources (management)
  Filament/App/Concerns/HasReportFilters.php   shared filter state for report pages
```

Rule: **only `Domain/Ingestion` writes raw tables, only `Domain/Metrics` writes aggregate tables,
and every read path goes through `MetricsQuery`**, which applies the branch-access scope.

---

## 4. Ingestion contract (v1)

### 4.1 Transport

| Item | Value |
|---|---|
| Endpoint | `POST /api/v1/ingest/batches` |
| Auth | `Authorization: Bearer <branch token>` (Sanctum personal access token on `Branch`, ability `ingest`) |
| Headers | `Content-Encoding: gzip`, `Content-Type: application/json`, `Idempotency-Key: <batch_uuid>`, `X-Schema-Version: 1`, `X-Pos-Version: x.y.z` |
| Max body | 5 MB compressed, max 500 documents |
| Rate limit | 60 req/min per branch |
| Responses | `202` processed-later with per-doc result · `409` batch already received (returns stored result) · `401` bad/revoked token · `413` too large · `422` envelope invalid · `5xx` retry |

```json
// 202 response
{ "batch_uuid": "…", "accepted": ["<doc uuid>", "…"],
  "rejected": [ { "uuid": "…", "type": "order", "reason": "schema: items.0.unit_cost required" } ] }
```

### 4.2 Envelope

```json
{
  "schema_version": 1,
  "batch_uuid": "01930f2a-6c1e-7b4a-9c3e-2f1d8a7e5b10",
  "branch_uuid": "01930c11-…",
  "pos_version": "2.4.0",
  "sent_at": "2026-10-29T21:14:03+03:00",
  "seq_from": 18231,
  "seq_to": 18730,
  "documents": [ { "type": "order", "uuid": "…", "version": 18240, "op": "upsert",
                   "occurred_at": "…", "data": { } } ]
}
```

- `version` = POS outbox sequence number (monotonic per branch). Platform applies a document only
  if `version > stored source_version`.
- `op` ∈ `upsert | delete`. Delete documents carry no `data`.
- Money is a **decimal string** with 2 places (`"400.00"`), quantities 3 places. Never floats.
- Timestamps ISO-8601 with offset. `business_date` is `YYYY-MM-DD` in the branch's timezone and is
  **always sent by the POS** (the POS owns the business-day definition; restaurants work past midnight).

### 4.3 Document types (v1)

| type | Sent when (POS side) | `data` key fields |
|---|---|---|
| `order` | completed, cancelled, items changed after save, shift transferred, return status changed | number, shift_uuid, business_date, type, status, payment_status, return_status, created_at, cashier_uuid, driver_uuid, customer_hash, region, sub_total, discount, tax, service, total, profit, web_pos_diff, `items[]` {uuid, product_uuid, product_ref, quantity, price, unit_cost, item_discount, item_discount_type, total}, `payments[]` {method, amount, shift_uuid, paid_at} (no uuid — replaced with the parent) |
| `order_return` | return processed | order_uuid, shift_uuid, business_date, total_refund, reason, reverse_stock, `items[]` {order_item_uuid, quantity, refund_amount}, `refunds[]` {method, amount} |
| `shift` | started, ended | business_date, user_uuid, start_at, end_at, start_cash, end_cash, real_cash, losses_amount, has_deficit, closed |
| `expense` | created / updated / deleted | shift_uuid, business_date, expense_type_uuid, amount, notes |
| `purchase_invoice` | closed | business_date, closed_at, supplier_uuid, total, `items[]` {product_uuid, quantity, price, total} |
| `purchase_return` | closed | same as purchase_invoice |
| `waste` | closed | business_date, closed_at, total, `items[]` {product_uuid, quantity, price, total} |
| `stocktaking` | closed | business_date, closed_at, total, `items[]` {product_uuid, stock_quantity, real_quantity, price, total} |
| `inventory_day` | business day closed | date, `rows[]` {product_uuid, start_qty, incoming_qty, sales_qty, return_sales_qty, waste_qty, end_qty} |
| `day_summary` | business day closed | date, orders_count, completed_count, net_total, discount_total, cogs_total, expenses_total, waste_total (checksum computed by POS from its own tables) |
| `product` | created / updated / deleted | name, product_ref, category_uuid, type (manufactured/raw_material/consumable), unit, price, cost, legacy |
| `recipe` | product components changed | product_uuid, `components[]` {component_uuid, quantity} |
| `category`, `supplier` | created / updated / deleted | name (+ phone for supplier) |
| `expense_type` | created / updated / deleted | name, category (labor/rent/utilities/marketing/maintenance/raw_materials/other), avg_month_rate (monthly budget) |
| `staff` | created / updated / deleted | name, role (no email, no password) |

**Never transferred:** passwords, sessions, printer config, settings, dine tables, customer names /
phones / addresses. Customers appear only as `customer_hash = sha256(phone + branch_secret)`.

Each `(type, schema_version)` has a JSON schema in `resources/schemas/ingest/v1/<type>.json`;
validation errors reject that document only, never the whole batch.

### 4.4 Heartbeat (and platform → POS commands)

`POST /api/v1/heartbeat` every 5 min:

```json
{ "pos_version": "2.4.0", "pending_count": 12, "oldest_pending_at": "…",
  "failed_count": 0, "last_success_at": "…", "server_time": "…", "business_date_open": "2026-10-29" }
```

Response carries commands (the only channel platform → POS, no inbound port needed):

```json
{ "commands": [ { "type": "resync", "from": "2026-10-01", "to": "2026-10-07" },
                { "type": "rotate_token" } ],
  "min_supported_schema": 1 }
```

### 4.5 Pairing

1. Org owner creates a branch in the `app` panel (Management group) → platform shows a one-time **pairing code** (8 chars, 15 min TTL).
2. POS admin enters platform URL + code in POS settings → POS calls `POST /api/v1/pair {code, pos_version}`.
3. Platform returns `{branch_uuid, token, branch_secret, timezone}`; POS stores them encrypted (own
   `platform_connections` table, not the generic settings page) and queues catalog + 90 days of history.
   `branch_secret` salts `customer_hash`.
4. Tokens are revocable from the admin panel and the app panel (Management); revocation → POS gets `401` and shows "re-pair required".
5. Rotation: heartbeat command `rotate_token` → POS calls `POST /api/v1/pair/rotate` (authenticated with the
   current token) → new token returned, old one revoked after 10 minutes.

---

## 5. Processing pipeline

### 5.1 `IngestController` (synchronous, must stay < 300 ms)

1. Resolve branch from token; reject if branch suspended.
2. `ingest_batches` has `(branch_id, batch_uuid)`? → return `409` with stored result.
3. Decode gzip, validate envelope, validate each document against its schema.
4. Store raw body to S3 `ingest/{org}/{branch}/{yyyy}/{mm}/{batch_uuid}.json.gz`.
5. Insert `ingest_batches` (status `received`) + dispatch `ProcessIngestBatch` on queue `ingest`.
6. Update `branches.last_seen_at`, return `202`.

### 5.2 `ProcessIngestBatch` (queue `ingest`, per-branch ordering via `WithoutOverlapping(branch_id)`)

For each accepted document, inside one transaction per document:

MySQL/MariaDB version guard (portable, done in PHP):

```php
$existing = WhOrder::where('branch_id', $b)->where('uuid', $uuid)->lockForUpdate()->first(['id','source_version','business_date']);
if ($existing && $existing->source_version >= $doc['version']) return HandleResult::skipped();
// upsert parent (updateOrCreate on (branch_id, uuid)), replace children, collect dirty dates (old + new business_date)
```

- Child rows (items, payments, refunds) are **replaced** for that parent (delete + insert) only when the parent row was actually updated.
- `op = delete` → set `deleted_at`, bump `source_version`.
- **Dirty set**: add `(branch_id, new business_date)` and, if it changed (e.g. web order moved to the next shift), `(branch_id, old business_date)` as well.
- Reference documents (product, category, …) upsert into dimension tables; they dirty nothing except for `expense_type.category` changes, which dirty every day of the current and previous month for that branch.
- After the loop: dispatch `RecomputeBranchDay` for every dirty key; mark batch `processed`.

**No foreign keys between warehouse tables.** Documents may arrive in any order (an order before its
product). Joins use `(branch_id, uuid)`; missing dimensions display as "غير معروف" until they arrive.

### 5.3 `RecomputeBranchDay(branch_id, business_date)` (queue `aggregate`)

- `ShouldBeUnique` on `branch_id:date`, dispatched with 30 s delay → bursts collapse into one run.
- Deletes and rebuilds all aggregate rows for that branch/day with `INSERT … SELECT` from warehouse
  tables (one day of one branch = hundreds of rows; full rebuild is cheap and idempotent).
- Fires `BranchDayUpdated(branch_id, business_date)`.

### 5.4 Reconciliation

When `day_summary` arrives (or its day is recomputed), compare with `branch_daily_metrics`:
`orders_count`, `net_total`, `expenses_total`, `waste_total`. Any mismatch beyond 0.01 EGP →
`reconciliation_issues` row + enqueue a `resync` command for that day in the next heartbeat response.
After 3 failed resyncs → visible in the operator panel.

### 5.5 Retention

| Data | Retention |
|---|---|
| Raw batch files (S3) | 90 days |
| Warehouse tables | forever (partitioned monthly) |
| Aggregates | forever |
| `ingest_batches` rows | 1 year |

---

## 6. Data model

All warehouse and aggregate tables carry `organization_id` (denormalized) for tenant scoping and
indexing. Money columns are `decimal(14,2)`, quantities `decimal(14,3)`.

### 6.1 Layer A — Tenancy & control

```
organizations        id, name, slug, plan (basic|pro), ai_enabled bool, timezone, currency, created_at
restaurants          id, organization_id, name, logo_path            -- a brand / concept
branches             id, uuid, organization_id, restaurant_id, name, timezone, opened_on,
                     status (pending|active|suspended), pos_version, schema_version,
                     last_seen_at, last_batch_at, last_seq, pending_count, failed_count
branch_pairing_codes id, branch_id, code_hash, expires_at, used_at
personal_access_tokens  (Sanctum; tokenable = Branch, ability "ingest")
users                id, organization_id (null for platform operators), name, email, …
  + spatie/laravel-permission roles: operator, org_owner, org_analyst, branch_manager
user_branch_access   user_id, branch_id                   -- only for branch_manager
branch_sets          id, organization_id, user_id null, name   -- saved comparison groups
branch_set_members   branch_set_id, branch_id
targets              id, organization_id, branch_id, metric, period_month (date, 1st of month),
                     value, daily_distribution (weekday_weights|flat), created_by
calendar_events      id, organization_id null, date_from, date_to, name, kind (ramadan|eid|holiday|promo)
ingest_batches       id, branch_id, batch_uuid, schema_version, docs_count, accepted, rejected,
                     status, storage_path, received_at, processed_at   UNIQUE(branch_id, batch_uuid)
ingest_rejections    id, branch_id, batch_id, doc_type, doc_uuid, reason, created_at
reconciliation_issues id, branch_id, business_date, field, pos_value, platform_value, status, attempts
alert_rules / alerts / notification_recipients   → see reports/18-ai-alerts.md
ai_insights          id, organization_id, branch_id null, kind, period_start, period_end,
                     payload json, narrative_ar text, model, created_at
```

### 6.2 Layer B — Warehouse (mirror of POS documents)

Common columns: `id bigserial, organization_id, branch_id, uuid, source_version bigint, deleted_at, ingested_at`
+ `UNIQUE (branch_id, uuid)`. (No partitioning while on MySQL; index `(branch_id, business_date)`.)

```
wh_orders            number, shift_uuid, business_date, type, status, payment_status, return_status,
                     created_at_local timestamptz, hour_of_day smallint, weekday smallint,
                     cashier_uuid, driver_uuid, customer_hash, region,
                     sub_total, discount, tax, service, total, profit_pos, web_pos_diff,
                     cogs  -- computed at ingest = Σ items.quantity × unit_cost
wh_order_items       order_uuid, product_uuid, product_ref, quantity, price, unit_cost,
                     item_discount, item_discount_type, total
wh_payments          order_uuid, method, amount, shift_uuid, paid_at
wh_order_returns     order_uuid, shift_uuid, business_date, total_refund, reason, reverse_stock
wh_order_return_items return_uuid, order_item_uuid, quantity, refund_amount
wh_refunds           return_uuid, method, amount
wh_shifts            business_date, user_uuid, start_at, end_at, start_cash, end_cash, real_cash,
                     losses_amount, has_deficit, closed
wh_expenses          shift_uuid, business_date, expense_type_uuid, amount, notes
wh_purchases         kind (invoice|return), business_date, closed_at, supplier_uuid, total
wh_purchase_items    purchase_uuid, product_uuid, quantity, price, total
wh_wastes            business_date, closed_at, total
wh_waste_items       waste_uuid, product_uuid, quantity, price, total
wh_stocktakings      business_date, closed_at, total
wh_stocktaking_items stocktaking_uuid, product_uuid, stock_quantity, real_quantity, price, total
wh_inventory_days    date, product_uuid, start_qty, incoming_qty, sales_qty, return_sales_qty, waste_qty, end_qty
wh_day_summaries     date, orders_count, completed_count, net_total, discount_total, cogs_total,
                     expenses_total, waste_total
-- dimensions
dim_products         name, product_ref, category_uuid, type, unit, price, cost, legacy
dim_recipes          product_uuid, component_uuid, quantity
dim_categories       name
dim_suppliers        name, phone
dim_expense_types    name, category, avg_month_rate
dim_staff            name, role
catalog_items        organization_id, product_ref, canonical_name   -- cross-branch item identity
```

### 6.3 Layer C — Aggregates (the only tables dashboards read)

```
agg_branch_daily        PK(branch_id, business_date)
  organization_id, restaurant_id, weekday,
  orders_count, cancelled_count, cancelled_value,
  gross_sales, discounts, tax, service, net_sales,
  returns_amount, net_sales_after_returns,
  cogs, returned_cogs_recovered, gross_profit,
  expenses_total, labor_cost, rent_cost, other_expenses,
  waste_cost, inventory_variance_cost,
  purchases_total, purchase_returns_total,
  net_profit,
  sales_by_type json, sales_by_method json,
  shifts_count, cash_deficit_total,
  is_day_closed bool, is_reconciled bool, computed_at

agg_product_daily       PK(branch_id, business_date, product_uuid)
  organization_id, category_uuid, product_ref,
  qty_sold, gross_sales, item_discounts, net_sales, cogs, gross_profit,
  returns_qty, waste_qty, waste_cost

agg_shift               PK(branch_id, shift_uuid)
  organization_id, business_date, weekday, start_at, end_at, duration_minutes, cashier_uuid,
  orders_count, net_sales, gross_profit, avg_ticket, cancelled_count, cash_deficit

agg_branch_hourly       PK(branch_id, business_date, hour)
  organization_id, weekday, orders_count, net_sales

agg_expense_daily       PK(branch_id, business_date, expense_type_uuid)
  organization_id, category, amount

agg_purchase_daily      PK(branch_id, business_date, supplier_uuid, kind)
  organization_id, amount, invoices_count
```

KPI formulas over these columns are defined once in `reports/00-conventions.md` and implemented once
in `App\Domain\Metrics\Kpi`. Weekly / monthly / custom periods are `SUM` over daily rows — no extra
period tables (50 branches × 365 days = 18k rows/year in `agg_branch_daily`).

**Accounting rule:** purchases are *not* subtracted from profit. They become stock and reach the P&L
through `cogs` when sold (and through `waste_cost` / `inventory_variance_cost` when lost).
`purchases_total` is displayed for cash-flow information only.

---

## 7. Read side

- `MetricsQuery` builder: `MetricsQuery::for($user)->branches($ids|$set|'all')->period($from, $to)->compareTo('previous'|'same_last_year'|'same_weekday_last_week')`.
  It always intersects requested branches with `AccessibleBranches::for($user)`.
- Report pages are Filament pages; each section is a widget receiving the page filters; heavy widgets use `$isLazy = true`.
- Cache: per `(org, query hash)`, 60 s TTL, busted by `BranchDayUpdated` for that org.
- Live: widgets on "today" views poll every 60 s (`$pollingInterval`).

---

## 8. AI module (paid plan)

Two layers, never mixed:

1. **Deterministic analytics** (PHP/SQL, unit-tested): growth decomposition, robust anomaly scores,
   contribution analysis, cost ratios, forecasting (seasonal baseline + Holt-Winters),
   menu-engineering classification. Output: structured JSON stored in `ai_insights.payload`.
2. **Narrative** (LLM via Anthropic API): receives *only* the computed JSON and writes the Arabic text
   (`ai_insights.narrative_ar`). The model never computes numbers; every number in the text must
   come from the payload (validated by a post-check that all numerals in the text appear in the payload).

Gate: `organizations.ai_enabled` + `plan = pro`. Non-AI orgs still get deterministic alerts for
targets (rule-based), but not anomaly/forecast/suggestions/WhatsApp narrative.

---

## 9. Security & tenancy

- Single database; every tenant table has `organization_id`; Eloquent global scope `OrganizationScope`
  on all Domain models; `MetricsQuery` additionally applies branch access.
- Branch token: Sanctum, ability `ingest` only; stored hashed; rotate via heartbeat command.
- Request signing (optional v1.1): `X-Signature: sha256=HMAC(body, branch_secret)`.
- Pest arch test: no controller may query `wh_*` / `agg_*` tables except through `MetricsQuery`.
- Audit log for target changes, user access changes, token issue/revoke.

---

## 10. Observability

- Horizon dashboards for `ingest` and `aggregate` queues; alert if `ingest` wait > 2 min.
- Per-branch: `last_seen_at`, `last_batch_at`, `pending_count`, `failed_count`, reconciliation status
  (operator panel + `reports/10-branch-sync-health.md`).
- Metrics: batches/min, docs/min, rejection rate, recompute duration p95.

---

## 11. Delivery phases

| Phase | Scope | Exit criteria |
|---|---|---|
| 0 | Postgres locally, tenancy models, operator panel, pairing, ingestion API + schemas, warehouse tables | A paired larament branch backfills 3 months and its totals reconcile |
| 1 | Aggregates, reports 01–10, access control, targets | Group owner and a branch manager each see correct, scoped dashboards |
| 2 | Alerts engine (rule-based) + WhatsApp delivery | Below-target and branch-offline alerts reach WhatsApp |
| 3 | AI reports 11–16 + daily WhatsApp narrative (17) + AI alerts (18) | Daily report delivered for 2 weeks with no numeric mismatch |

---

## 12. Decisions & trade-offs

| Decision | Alternative | Why |
|---|---|---|
| Push from POS (outbox) | Platform pulls from POS | POS is behind NAT; push survives offline; no inbound attack surface |
| Document snapshots at business events | Row-level CDC | Final state only, fewer messages, no cross-table ordering problems |
| Version-guarded upsert | Event log replay | Simple, idempotent, late duplicates harmless |
| Full-day recompute | Incremental counters | Always correct after edits/returns/moves; cheap at this scale |
| Single DB + `organization_id` | DB per tenant | Cross-branch queries are the product; operations stay simple |
| Postgres | MySQL | Partitioning, `FILTER`, conditional upsert, `json` |
| Modular monolith | Microservices | Small team, one deploy; modules keep boundaries |

### Revisit when

- > 500 branches or aggregate queries > 1 s → move warehouse to ClickHouse/TimescaleDB, keep Postgres for control.
- Forecast accuracy matters commercially → move forecasting to a Python service (statsforecast/Prophet).
- POS fleet has mixed versions → keep v1 schemas and add v2 side by side (`X-Schema-Version`).
