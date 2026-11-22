# 06. Warehouse, Dimensions, Aggregates, Lineage, and Reconciliation

This document explains the data modeling tiers of `platform-restaurants`, detailing the raw mirrored warehouse tables (`wh_*`), reference dimension tables (`dim_*`), precomputed aggregate tables (`agg_*`), the end-to-end data lineage, and the bounded reconciliation protocol.

---

## 1. Warehouse Architecture (`wh_*`)

The warehouse layer acts as a decentralized document mirror:
- **Foreign Key Independence**: Warehouse tables do not maintain foreign keys to each other. Because POS documents may arrive out of order (e.g. order returns arriving before the original order batch completes), records correlate dynamically on `(branch_id, uuid)`.
- **Branch Ownership Constraint**: All warehouse tables include `branch_id`, indexed and foreign-keyed to `branches.id` with `cascadeOnDelete`.
- **Primary / Child Model**: Parent records maintain `(branch_id, uuid)` as a unique key with a `source_version` column. Child records correlate on `(branch_id, parent_uuid)`.

### Core Warehouse Tables ([`2026_11_02_000002_create_warehouse_tables.php`](../../database/migrations/2026_11_02_000002_create_warehouse_tables.php))

| Table Name | Entity | Key Columns & Types | Description |
|---|---|---|---|
| `wh_orders` | Sales Orders | `uuid (char 36)`, `number (varchar 32)`, `type (varchar 32)`, `status (varchar 32)`, `business_date (date)`, `hour_of_day (tinyint)`, `weekday (tinyint)`, `sub_total (14,2)`, `discount (14,2)`, `tax (14,2)`, `service (14,2)`, `total (14,2)`, `profit_pos (14,2)`, `web_pos_diff (14,2)`, `items_discount (14,2)`, `cogs (14,2)` | Primary sales document. Completed orders drive `gross_sales`, `net_sales`, and `cogs`. Cancelled orders drive `cancelled_count` and `cancelled_value`. |
| `wh_order_items` | Order Line Items | `order_uuid (char 36)`, `product_uuid (char 36)`, `product_ref (varchar 64)`, `quantity (14,3)`, `price (14,2)`, `unit_cost (14,2)`, `item_discount (14,2)`, `total (14,2)` | Child lines for each order. Replaced atomically on order upsert. |
| `wh_payments` | Order Tender | `order_uuid (char 36)`, `method (varchar 32)`, `amount (14,2)`, `shift_uuid (char 36)`, `paid_at (timestamp)` | Tender records (cash, card, talabat_card, etc.) linked to orders. Drives `sales_by_method`. |
| `wh_order_returns` | Sales Returns | `uuid (char 36)`, `order_uuid (char 36)`, `business_date (date)`, `total_refund (14,2)`, `reverse_stock (boolean)`, `returned_cogs (14,2)` | Drives `returns_amount` (K05) and recovered COGS if `reverse_stock = true`. |
| `wh_order_return_items` | Return Items | `return_uuid (char 36)`, `order_item_uuid (char 36)`, `quantity (14,3)`, `refund_amount (14,2)` | Detail item refunds. |
| `wh_shifts` | Cashier Shifts | `uuid (char 36)`, `user_uuid (char 36)`, `business_date (date)`, `start_at`, `end_at`, `start_cash (14,2)`, `end_cash (14,2)`, `real_cash (14,2)`, `losses_amount (14,2)`, `has_deficit (bool)`, `closed (bool)` | Shift tracking and cash drawers. Cash deficit computed as `max(0, end_cash - real_cash)` on closed shifts. |
| `wh_expenses` | Cash Expenses | `uuid (char 36)`, `shift_uuid (char 36)`, `business_date (date)`, `expense_type_uuid (char 36)`, `amount (14,2)`, `notes (text)` | Direct expenses paid from drawer or branch accounts. |
| `wh_purchases` | Invoices / Returns | `uuid (char 36)`, `kind (varchar 16: invoice\|return)`, `supplier_uuid (char 36)`, `business_date (date)`, `closed_at`, `total (14,2)` | Inbound goods and supplier returns. Drives `purchases_total` and `purchase_returns_total`. |
| `wh_purchase_items` | Purchase Lines | `purchase_uuid (char 36)`, `product_uuid (char 36)`, `quantity (14,3)`, `price (14,2)`, `total (14,2)` | Line-item supplier purchase prices. |
| `wh_wastes` | Waste Records | `uuid (char 36)`, `business_date (date)`, `closed_at`, `total (14,2)` | Kitchen scrap, spoilage, and expired items. Drives `waste_cost` (K12). |
| `wh_waste_items` | Waste Lines | `waste_uuid (char 36)`, `product_uuid (char 36)`, `quantity (14,3)`, `price (14,2)`, `total (14,2)` | Details wasted items for Pareto waste ranking. |
| `wh_stocktakings` | Inventory Audits | `uuid (char 36)`, `business_date (date)`, `closed_at`, `total (14,2)`, `variance_cost (14,2)` | Physical counts vs theoretical stock. Drives `inventory_variance_cost` (K13). |
| `wh_stocktaking_items` | Audit Lines | `stocktaking_uuid (char 36)`, `product_uuid (char 36)`, `stock_quantity (14,3)`, `real_quantity (14,3)`, `price (14,2)`, `total (14,2)` | Discrepancies between system inventory and counted stock. |
| `wh_inventory_days` | Daily Stock Balance | `uuid (char 36)`, `business_date (date)` | Signals that the POS closed inventory for that business date (`is_day_closed = true`). |
| `wh_inventory_day_rows` | Item Stock Balances | `inventory_day_uuid (char 36)`, `business_date (date)`, `product_uuid (char 36)`, `start_qty (14,3)`, `incoming_qty (14,3)`, `sales_qty (14,3)`, `return_sales_qty (14,3)`, `waste_qty (14,3)`, `end_qty (14,3)` | Daily stock balance ledger per product. |
| `wh_day_summaries` | POS Day Close Summary | `uuid (char 36)`, `business_date (date)`, `orders_count`, `completed_count`, `net_total (14,2)`, `discount_total (14,2)`, `cogs_total (14,2)`, `expenses_total (14,2)`, `waste_total (14,2)` | High-level financial totals calculated locally on the POS machine at day close. Evaluated by [`Reconciler`](../../app/Domain/Metrics/Aggregation/Reconciler.php). |

---

## 2. Dimension Tables (`dim_*`)

Reference metadata mirrored from POS master data tables ([`2026_11_02_000003_create_dimension_tables.php`](../../database/migrations/2026_11_02_000003_create_dimension_tables.php)):

- **`dim_products`**: Product definitions with `name`, `product_ref`, `type`, `price`, `cost`, `unit`, and `legacy` flag. Indexed on `(organization_id, product_ref)`.
- **`dim_recipes`**: Bill of Materials (BOM) linking manufactured products to component raw materials: `product_uuid`, `component_uuid`, `quantity (14,4)`.
- **`dim_categories`**: Menu and inventory categories (`name`, `uuid`).
- **`dim_suppliers`**: Supplier contact records (`name`, `phone`, `uuid`).
- **`dim_expense_types`**: Expense category taxonomy:
  - `category`: Category string assigned by the POS (e.g. `labor`, `rent`, `utilities`, `supplies`, `other`).
  - `category_override`: Platform-level taxonomy override. When populated, takes precedence over the POS-sent category.
  - `avg_month_rate`: Budgeted monthly rate used by [`AllocatedCost`](../../app/Domain/Metrics/AllocatedCost.php) to prorate labor and rent overhead.
- **`dim_staff`**: Cashiers, waiters, drivers, and branch personnel (`name`, `role`).
- **`catalog_items`**: Enterprise master catalog mapped to `product_ref` across branches (`canonical_name`, `unit_factor`).

---

## 3. Aggregate Read Models (`agg_*`)

Report pages never query raw `wh_*` tables directly. Instead, all reporting queries execute against aggregate tables rebuilt per `(branch_id, business_date)`:

```
[ wh_orders, wh_order_returns, wh_expenses, wh_purchases, wh_wastes, wh_stocktakings ]
                                       |
                                       v
                    App\Domain\Metrics\Aggregation\BranchDayRebuilder
                                       |
        +------------------------------+------------------------------+
        |                              |                              |
        v                              v                              v
 agg_branch_daily              agg_product_daily                  agg_shift
 (Primary KPI rollups)         (Product sales/cogs/waste)         (Shift metrics & cash)
        |                              |                              |
        v                              v                              v
 agg_branch_hourly             agg_expense_daily              agg_purchase_daily
 (Heatmaps & peak hours)       (Categorized expenses)         (Supplier invoices/returns)
```

### Table Specifications ([`2026_11_02_000004_create_aggregate_tables.php`](../../database/migrations/2026_11_02_000004_create_aggregate_tables.php))

#### 1. `agg_branch_daily` (Primary Key: `branch_id, business_date`)
- `weekday`: Integer 0 (Saturday) through 6 (Friday).
- `orders_count`: Count of completed orders ($K01$).
- `cancelled_count` & `cancelled_value`: Total cancelled orders and monetary sum.
- `gross_sales`: Sum of completed order sub-totals ($K02$).
- `discounts`: Sum of order-level discounts and item discounts ($K03$).
- `tax` & `service`: Taxes and service charges.
- `net_sales`: Sum of `total - tax` across completed orders ($K04$).
- `returns_amount`: Sum of `wh_order_returns.total_refund` occurring on this business date ($K05$).
- `net_sales_after_returns`: $K06 = K04 - K05$.
- `cogs`: Sum of completed order item costs ($\sum \text{qty} \times \text{unit\_cost}$).
- `returned_cogs_recovered`: COGS of returned items where `reverse_stock = true`.
- `gross_profit`: $K09 = K06 - (\text{cogs} - \text{returned\_cogs\_recovered})$.
- `expenses_total`: Sum of `wh_expenses.amount` ($K11$).
- `labor_cost`: Sum of expenses with category `labor` ($K11a$).
- `rent_cost`: Sum of expenses with category `rent`.
- `other_expenses`: Operating expenses excluding labor and rent.
- `waste_cost`: Sum of `wh_wastes.total` ($K12$).
- `inventory_variance_cost`: Sum of `wh_stocktakings.variance_cost` ($K13$).
- `purchases_total` & `purchase_returns_total`: Inbound purchase invoices and returns.
- `net_profit`: $K14 = \text{gross\_profit} - \text{expenses\_total} - \text{waste\_cost} - \text{inventory\_variance\_cost}$.
- `sales_by_type`: JSON map: `{"delivery": {"orders": 12, "net_sales": "4300.00"}, ...}`.
- `sales_by_method`: JSON map: `{"cash": "12000.00", "card": "800.00", ...}`.
- `shifts_count`: Number of shifts recorded for the day.
- `cash_deficit_total`: Cumulative cash deficit across closed shifts ($K25$).
- `is_day_closed`: True if `wh_inventory_days` or `wh_day_summaries` exists.
- `is_reconciled`: True if POS `day_summary` matches computed aggregates within 0.01 tolerance.

#### 2. Sibling Aggregate Tables
- **`agg_product_daily`** (`branch_id, business_date, product_uuid`): Tracks `qty_sold`, `gross_sales`, `item_discounts`, `net_sales`, `cogs`, `gross_profit`, `returns_qty`, `waste_qty`, `waste_cost`.
- **`agg_shift`** (`branch_id, shift_uuid`): Tracks `start_at`, `end_at`, `duration_minutes`, `cashier_uuid`, `orders_count`, `net_sales`, `gross_profit`, `avg_ticket`, `cancelled_count`, `cash_deficit`.
- **`agg_branch_hourly`** (`branch_id, business_date, hour`): Tracks `orders_count` and `net_sales` for business hours 0 through 23.
- **`agg_expense_daily`** (`branch_id, business_date, expense_type_uuid`): Tracks categorized expenses.
- **`agg_purchase_daily`** (`branch_id, business_date, supplier_uuid, kind`): Tracks supplier purchases and returns.

---

## 4. Recomputation Engine ([`BranchDayRebuilder`](../../app/Domain/Metrics/Aggregation/BranchDayRebuilder.php))

When a dirty date is processed by [`RecomputeBranchDay`](../../app/Jobs/RecomputeBranchDay.php), the rebuilder executes the following steps inside a serial transaction:

1. **Purge**: Deletes existing rows for `(branch_id, business_date)` across all six `agg_*` tables.
2. **Warehouse Check**: Checks if raw records exist in `wh_orders`, `wh_order_returns`, `wh_expenses`, `wh_purchases`, `wh_wastes`, `wh_shifts`, or `wh_inventory_days`. If empty, commits the fence token and exits cleanly.
3. **Sales & Orders Aggregation**: Fetches all orders for the date. Separates completed from cancelled orders. Computes `gross_sales`, `discounts`, `tax`, `service`, `net_sales`, and `cogs`.
4. **Returns & COGS Recovery**: Fetches returns for the date. Calculates total refunds and recovered COGS.
5. **Categorized Expenses**: Joins `wh_expenses` with `dim_expense_types` (respecting `category_override`) to separate labor, rent, and other operational expenses.
6. **Waste & Inventory Discrepancy**: Sums `wh_wastes.total` and `wh_stocktakings.variance_cost`.
7. **Net Profit Formula**:
   $$\text{Gross Profit} = \text{Net Sales After Returns} - (\text{COGS} - \text{Recovered COGS})$$
   $$\text{Net Profit} = \text{Gross Profit} - \text{Expenses Total} - \text{Waste Cost} - \text{Inventory Variance Cost}$$
8. **JSON Breakdowns**: Generates `sales_by_type` by grouping orders on `wh_orders.type`. Queries `wh_payments` for completed orders to build `sales_by_method`.
9. **Reconciliation Check**: Invokes [`Reconciler::reconcile()`](../../app/Domain/Metrics/Aggregation/Reconciler.php).
10. **Bulk Insert**: Inserts newly calculated records into `agg_branch_daily`, `agg_product_daily`, `agg_shift`, `agg_branch_hourly`, `agg_expense_daily`, and `agg_purchase_daily`.
11. **Event Dispatch**: Dispatches [`BranchDayUpdated::dispatch()`](../../app/Domain/Metrics/Aggregation/BranchDayRebuilder.php).

---

## 5. POS Summary Reconciliation & Bounded Resync Protocol

The platform features an automated auditing mechanism comparing locally calculated POS day summaries against warehouse aggregates:

```
[ wh_day_summaries (POS) ]   VS   [ Computed Aggregates (Platform) ]
            |                                     |
            +------------------+------------------+
                               |
                   App\Domain\Metrics\Aggregation\Reconciler
                               |
            [ abs(POS - Platform) > 0.01 tolerance? ]
                               |
                +---------------+---------------+
                |                               |
            [ No ]                            [ Yes ]
                |                               |
        Mark existing issues             1. Create / Update row in
        as 'resolved'                       reconciliation_issues table
        agg_branch_daily.                2. Increment attempts (bounded to 3)
        is_reconciled = true             3. If attempts < 3 & no pending resync:
                                            Queue BranchCommand:
                                            {type: 'resync', payload: {from, to}}
                                            into branch_commands table
                                         4. If attempts >= 3:
                                            Mark issue status as 'failed'
                                                |
                                                v
                                         Heartbeat Delivery
                                         (POS retrieves resync command,
                                          re-queues outbox range)
```

### Tolerances & Evaluated Metrics ([`Reconciler`](../../app/Domain/Metrics/Aggregation/Reconciler.php))
A mismatch is flagged if the absolute difference exceeds **`0.01`**:
- `orders_count`: `abs(completed_count - ordersCount) > 0.01`
- `net_total`: `abs(net_total - netSales) > 0.01`
- `expenses_total`: `abs(expenses_total - expensesTotal) > 0.01`
- `waste_total`: `abs(waste_total - wasteCost) > 0.01`

### Bounded Resync Behavior & Operational Limitations
- **Conditional Trigger**: Reconciliation runs only if a `wh_day_summaries` record has been ingested from the POS for that business date.
- **Bounded Retry Attempts**: The system caps automated resync attempts at **3**. If the mismatch persists after 3 attempts (`$attempts >= 3`), the issue transitions to `failed` and no further resync commands are dispatched.
- **Deduplication**: If an undelivered `resync` command for the date is already pending in `branch_commands`, duplicate commands are not created.
- **Dependencies & Scope**:
  - The resync command requires the POS node to be online and executing heartbeats to receive the instruction.
  - The POS must process the instruction and re-transmit missing outbox records.
  - If the discrepancy is due to documents rejected by platform validation rules (e.g. malformed JSON or constraint violations), re-syncing the identical raw records will fail again; manual intervention is required.
  - The reconciliation mechanism is therefore a bounded, conditional auditing protocol, rather than an unconstrained or self-sufficient self-healing loop.
