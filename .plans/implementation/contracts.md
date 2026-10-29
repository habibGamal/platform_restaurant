# Implementation Contracts (read before any platform task)

Shared facts every implementation task relies on. Design docs: `.plans/00-architecture.md`,
`.plans/reports/00-conventions.md`. If this file and a design doc disagree, **this file wins**
(it reflects decisions made during implementation).

## Stack facts
- Laravel 13, Filament 5.11, PHP 8.5. **Tests are PHPUnit classes** (Pest is NOT installed): feature tests extend `Tests\TestCase` with `RefreshDatabase`; run with `php artisan test <path>` or `php vendor/bin/phpunit <path>`. **All UI is Filament** (React/Inertia starter kit pages are unused).
- DB: MariaDB 10.4 locally (MySQL 8 compatible SQL only). No Postgres features: no `ON CONFLICT`, no `FILTER`, no `date_trunc`, no `jsonb`. Use `DB::table()->upsert()` / `insert()`, `SUM(CASE WHEN … THEN … ELSE 0 END)`, `DATE_FORMAT`.
- Locale `ar` (RTL), timezone `Africa/Cairo`, currency EGP.
- **Tests run on MySQL**: always `DB_DATABASE=<your assigned db> php artisan test …` (each agent gets its own db: `platform_test_1` … `platform_test_12`). Never run tests against `platform_restaurants` (dev db).

## Shared schema (source of truth = these migrations)
| Migration | Tables |
|---|---|
| tenancy (task PA) | organizations, restaurants, branches, branch_pairing_codes, user_branch_access, branch_sets, branch_set_members, spatie permission tables, personal_access_tokens |
| `2026_11_02_000001_create_ingest_control_tables` | ingest_batches, ingest_rejections, reconciliation_issues, branch_commands |
| `2026_11_02_000002_create_warehouse_tables` | wh_orders, wh_order_items, wh_payments, wh_order_returns, wh_order_return_items, wh_refunds, wh_shifts, wh_expenses, wh_purchases (+items), wh_wastes (+items), wh_stocktakings (+items), wh_inventory_days (+rows), wh_day_summaries |
| `2026_11_02_000003_create_dimension_tables` | dim_products, dim_recipes, dim_categories, dim_suppliers, dim_expense_types, dim_staff, catalog_items |
| `2026_11_02_000004_create_aggregate_tables` | agg_branch_daily, agg_product_daily, agg_shift, agg_branch_hourly, agg_expense_daily, agg_purchase_daily |
| `2026_11_02_000005_create_targets_and_calendar_tables` | targets, target_daily, calendar_events, scoring_profiles |

Models: `App\Models\Warehouse\*` (Wh*, Dim*), `App\Models\Aggregates\Agg*` (read models — composite keys, write with `DB::table`),
`App\Models\{IngestBatch, IngestRejection, ReconciliationIssue, BranchCommand, Target, TargetDaily, CalendarEvent, ScoringProfile, CatalogItem}`.
Do not change a shared migration; if a task needs a new column, add a **new** migration in that task and report it.

## Shared code contracts
| Contract | File | Implemented by |
|---|---|---|
| `DocumentHandler`, `HandleResult`, `DocumentHandlerRegistry` | `app/Domain/Ingestion/Contracts/*` | warehouse-handlers task (PB) — binds `DocumentHandlerRegistry` |
| `BranchDayAggregator::recompute(int $branchId, string $businessDate): void` | `app/Domain/Metrics/Contracts/BranchDayAggregator.php` | aggregates task (PF1) — binds the interface |
| `RecomputeBranchDay` job (queue `aggregate`, unique per branch:date) | `app/Jobs/RecomputeBranchDay.php` | done — ingestion dispatches it with 30 s delay |
| `BranchDayUpdated` event | `app/Events/BranchDayUpdated.php` | fired by PF1 after a recompute |
| `Weekday::of(Carbon): int` (0 = Saturday … 6 = Friday) | `app/Support/Weekday.php` | done |
| Period domain (static API): `PeriodResolver::resolve(PeriodPreset, CarbonImmutable $today, ?from, ?to, ?string $nowTime): Period`, `PeriodResolver::defaultComparison(PeriodPreset)`, `PeriodResolver::comparison(Period, ComparisonMode, ?PeriodPreset): ?Period`; `Period{from,to,cutoffTime; days(), contains(), dates(), shift(), equals(), toArray()}`; `Granularity::auto(Period)`, `->bucketStart(date)`; `ReportFilters{preset, period, comparisonMode, comparison, branchIds, branchSetId, restaurantId, likeForLike, channel, granularity}` + `ReportFilters::fromArray(array $query, CarbonImmutable $today, ?string $nowTime)`, `->toArray()` | `app/Domain/Metrics/Period/*` | done (PX) |
| `AccessibleBranches::ids(User): int[]`, `::query(User): Builder` | `app/Domain/Tenancy/AccessibleBranches.php` | task PA |

## POS document payloads (exact `data` keys, schema_version 1)

Money = decimal string `"400.00"`, quantity = decimal string `"1.500"`, timestamps ISO-8601 with offset, dates `Y-m-d`.
Every document: `{type, uuid, version (int), op ('upsert'|'delete'), occurred_at, data (null when op=delete)}`.

| type | data keys | warehouse target |
|---|---|---|
| `order` | number, shift_uuid, business_date, type, status, payment_status, return_status, created_at, cashier_uuid, driver_uuid, customer_hash, region, sub_total, discount, tax, service, total, profit, web_pos_diff, items[] {uuid, product_uuid, product_ref, quantity, price, unit_cost, item_discount, item_discount_type, total}, payments[] {method, amount, shift_uuid, paid_at} | wh_orders (+ items, payments). Ingest computes `cogs` = Σ quantity × unit_cost, `items_discount` = Σ item_discount, `hour_of_day` and `weekday` from created_at (branch tz) — weekday of the **business_date** |
| `order_return` | order_uuid, shift_uuid, business_date, total_refund, reason, reverse_stock, items[] {order_item_uuid, quantity, refund_amount}, refunds[] {method, amount} | wh_order_returns (+ items, refunds). Ingest computes `returned_cogs` = Σ item.quantity × unit_cost of the referenced wh_order_items row (0 if not yet known) |
| `shift` | business_date, user_uuid, start_at, end_at, start_cash, end_cash, real_cash, losses_amount, has_deficit, closed | wh_shifts |
| `expense` | shift_uuid, business_date, expense_type_uuid, amount, notes | wh_expenses |
| `purchase_invoice` / `purchase_return` | business_date, closed_at, supplier_uuid, total, items[] {product_uuid, quantity, price, total} | wh_purchases (kind invoice/return) + items |
| `waste` | business_date, closed_at, total, items[] {product_uuid, quantity, price, total} | wh_wastes + items |
| `stocktaking` | business_date, closed_at, total, items[] {product_uuid, stock_quantity, real_quantity, price, total} | wh_stocktakings (+ `variance_cost` = Σ (stock_quantity − real_quantity) × price) + items |
| `inventory_day` | date, rows[] {product_uuid, start_qty, incoming_qty, sales_qty, return_sales_qty, waste_qty, end_qty} | wh_inventory_days (business_date = date) + rows; HandleResult dayClosed = true |
| `day_summary` | date, orders_count, completed_count, net_total, discount_total, cogs_total, expenses_total, waste_total | wh_day_summaries; dayClosed = true |
| `product` | name, product_ref, category_uuid, type, unit, price, cost, legacy | dim_products |
| `recipe` (uuid = product uuid) | product_uuid, components[] {component_uuid, quantity} | dim_recipes (replace all rows of that product) |
| `category` | name | dim_categories |
| `supplier` | name, phone | dim_suppliers |
| `expense_type` | name, category, avg_month_rate | dim_expense_types |
| `staff` | name, role | dim_staff |

Dirty dates: transaction documents dirty their `business_date` (+ the previously stored one if it changed).
`order_return` dirties its own business_date. `expense_type` changing `category` dirties every date of the current
and previous month for that branch. Other dimension docs dirty nothing.

## Ingestion HTTP contract (task PD) — matches the POS connector
- `POST /api/v1/pair` (no auth) body `{code, pos_version}` → 200 `{branch_uuid, token, branch_secret, timezone}`; 422 invalid/expired code.
- `POST /api/v1/pair/rotate` (Bearer, ability ingest) → 200 `{token}`; old token revoked.
- `POST /api/v1/ingest/batches` (Bearer, ability ingest; body may be gzip with `Content-Encoding: gzip`) → 202 `{batch_uuid, accepted:[uuid], rejected:[{uuid, type, reason}]}`; 409 same body for a known batch_uuid; 401; 413 (> 5 MB or > 500 docs); 422 envelope invalid.
- `POST /api/v1/heartbeat` (Bearer) body `{pos_version, pending_count, oldest_pending_at, failed_count, last_success_at, server_time, business_date_open}` → 200 `{commands:[{type:'resync', from, to} | {type:'rotate_token'}], min_supported_schema: 1}`.

## Filament layout
- `admin` panel (`/admin`, operators) — `app/Filament/Admin/**`.
- `app` panel (`/app`, organization users) — `app/Filament/App/**`; navigation groups: `التقارير` (reports 01–10, in order), `الإدارة` (management).
- Report page slugs: `reports/overview`, `reports/sales`, `reports/profitability`, `reports/food-cost`, `reports/products`, `reports/shifts`, `reports/ranking`, `reports/targets`, `reports/expenses`, `reports/data-health`.

## House rules for every agent
- Only edit files your brief assigns to you; other agents work in the same tree at the same time.
- No state-changing git commands. The orchestrator commits.
- Run commands synchronously; finish with the report contract in your brief.
- Format with `php vendor/bin/pint <your files>`; `php vendor/bin/phpstan analyse --memory-limit=1G` must not report errors in your files.
