# Metrics Query Engine & KPI Catalog

All analytics pages and AI metric tools share one query and formula layer under `app/Domain/Metrics/`: `MetricsQuery`, `KpiValues`, `Kpi`, `AllocatedCost`, `Completeness`, and `Period/*`.

---

## 1. Branch Scoping & Like-for-Like (`MetricsQuery`)

`MetricsQuery::for(User $user, ReportFilters $filters)` resolves authorized branches before any aggregate read:

1. **Access baseline:** Calls `AccessibleBranches::ids($user)` (`org_owner` and `org_analyst` get all org branches; `branch_manager` gets `user_branch_access`; others get `[]`).
2. **Explicit selection:** If `$filters->branchIds` contains any ID outside `$accessibleBranchIds`, throws `AuthorizationException` (403) rather than silently dropping IDs.
3. **Set & restaurant intersection:** Intersects with `branch_sets` (org-wide `user_id IS NULL` or owned by `$user->id`) and `restaurant_id` within the organization.
4. **Like-for-Like (`likeForLike = true`):** When a comparison period is active, retains only branches that have rows in `agg_branch_daily` in **both** the current and comparison periods.
5. **Caching (`MetricsCache`):** Caches query results per org (`metrics.cache_ttl`, default `60` s; `metrics.cache_live_ttl`, default `15` s for queries touching today) and busts via `BumpMetricsCacheVersion` on `BranchDayUpdated`.

---

## 2. Periods, Timezone & 364-Day Alignment (`PeriodResolver`)

- **Timezone:** `Africa/Cairo`.
- **Business week:** Saturday (`0`) through Friday (`6`) via `App\Support\Weekday`.
- **Presets (`PeriodPreset`):** `today`, `yesterday`, `this_week`, `last_week`, `this_month`, `last_month`, `this_quarter`, `this_year`, `last_30_days`, `last_8_weeks` (7 Saturdays before current week through today), `custom`.
- **Comparison modes (`ComparisonMode`):**
  - `previous`: preceding period of equal length (or full previous month/quarter/year).
  - `same_weekday_last_week`: shifts `-7` days.
  - `same_period_last_year`: shifts **`-364` days (52 weeks)** so weekdays align (Saturday vs Saturday).
  - `none`: no comparison.
- **Intraday cutoff:** When the period ends on `today`, `$cutoff = $nowTime` restricts hourly comparisons (`MetricsQuery::hourlyTotals()`) to elapsed hours of the day.
- **Automatic granularity (`Granularity::auto()`):** `<= 31` days -> `day`; `32..182` days -> `week` (Saturday start); `> 182` days -> `month`.

---

## 3. KPI Formulas Catalog (`K01`–`K25`)

Ratios over any period are **ratio of sums**, never averages of daily ratios. Implemented in `KpiValues`, `Kpi`, and `TargetAchievement`:

| Code | Name (EN / AR) | Formula | Unit / Null Guard |
|---|---|---|---|
| `K01` | Orders / عدد الطلبات | Count of completed orders (`agg_branch_daily.orders_count`) | Integer |
| `K02` | Gross sales / إجمالي المبيعات قبل الخصم | `Σ wh_orders.sub_total` (completed) | EGP |
| `K03` | Discounts / الخصومات | `Σ order.discount + Σ items_discount` | EGP |
| `K04` | Net sales / صافي المبيعات | `Σ (total - tax)` (service charge stays in revenue; tax does not) | EGP |
| `K05` | Returns / المرتجعات | `Σ wh_order_returns.total_refund` by return `business_date` | EGP |
| `K06` | **Sales (headline)** / المبيعات | `K04 - K05` (`net_sales_after_returns`) | EGP |
| `K07` | Average ticket / متوسط قيمة الطلب | `K04 / K01` | `null` if `K01 === 0` |
| `K08` | COGS / تكلفة المبيعات | `Σ (qty * unit_cost) - K08a` (`cogs - returned_cogs_recovered`) | EGP |
| `K08a` | Recovered return COGS | `Σ returned_cogs` where `reverse_stock = true` | EGP |
| `K09` | Gross profit / مجمل الربح | `K06 - (cogs - returned_cogs_recovered)` | EGP |
| `K10` | Gross margin / هامش مجمل الربح | `K09 / K06` | `null` if `K06 == 0.0` |
| `K11` | Expenses / المصروفات | `Σ wh_expenses.amount` | EGP |
| `K11a` | Labor cost / تكلفة العمالة | `Σ expenses` where effective category = `labor` | EGP |
| `K11b` | Rent cost / الإيجار | `Σ expenses` where effective category = `rent` | EGP |
| `K12` | Waste cost / الهالك | `Σ wh_wastes.total` | EGP |
| `K13` | Inventory variance / عجز الجرد | `Σ (stock_quantity - real_quantity) * price` (positive = shortage loss) | EGP |
| `K14` | **Net profit** / صافي الربح | `K09 - K11 - K12 - K13` | EGP |
| `K15` | **Net margin** / هامش الربح | `K14 / K06` | `null` if `K06 == 0.0` |
| `K16` | Food cost % / نسبة تكلفة الخامات | `(cogs - returned_cogs_recovered) / K06` (lower is better) | `null` if `K06 == 0.0` |
| `K17` | Waste % of sales / نسبة الهالك | `K12 / K06` (lower is better) | `null` if `K06 == 0.0` |
| `K17b` | Waste share of usage | `K12 / ((cogs - returned_cogs_recovered) + K12)` | `null` if denominator `== 0.0` |
| `K18` | Labor % / نسبة العمالة | `AllocatedCost::labor(...) / K06` (lower is better) | `null` if `K06 == 0.0` |
| `K19` | Prime cost % | `((cogs - returned_cogs_recovered) + allocated_labor) / K06` (lower is better) | `null` if `K06 == 0.0` |
| `K20` | Net purchases / صافي المشتريات | `purchases_total - purchase_returns_total` (cash-flow info; **not** subtracted from profit) | EGP |
| `K21` | Cancel rate / نسبة الإلغاء | `cancelled_count / (orders_count + cancelled_count)` (lower is better) | `null` if total `=== 0` |
| `K22` | Return rate / نسبة المرتجع | `K05 / K04` (lower is better) | `null` if `K04 == 0.0` |
| `K23` | Target achievement / تحقيق المستهدف | `actual_to_date / target_to_date` (`TargetAchievement`) | `null` if no target or 0 |
| `K24` | Growth / النمو | `(current - previous) / |previous|` (`Kpi::growth()`) | `null` if `previous` is null/0 |
| `K25` | Cash deficit / عجز النقدية | `Σ max(0, end_cash - real_cash)` over closed shifts (lower is better) | EGP |

**Accounting rule:** Purchases (`K20`) do not reduce profit directly; purchased inventory enters the P&L via `cogs` (`K08`), `waste_cost` (`K12`), and `inventory_variance_cost` (`K13`).

---

## 4. Overhead Allocation (`AllocatedCost`)

Because salaries and rent are typically recorded once per month, `AllocatedCost::labor()` and `AllocatedCost::rent()` prorate each overlapping month per branch:

$$\text{Allocated Cost} = \text{Monthly Basis} \times \frac{\text{Overlapping Days}}{\text{Days in Month}}$$

Monthly basis priority per branch and month:
1. **Past closed months (`monthEnd < today`):** Actual `SUM(labor_cost)` or `SUM(rent_cost)` from `agg_branch_daily`.
2. **Current / open months with budgeted rates:** `SUM(dim_expense_types.avg_month_rate)` where `COALESCE(NULLIF(category_override, ''), category)` matches `labor` or `rent`.
3. **Fallback:** Trailing 3-month average actual cost from `agg_branch_daily`.

---

## 5. Data Completeness (`Completeness`)

Each `(branch_id, business_date)` resolves to one of five statuses in `CompletenessResult`:

| Status | Rule |
|---|---|
| `complete` | `agg_branch_daily` exists with `is_day_closed = true` and `is_reconciled = true` |
| `closed` | `is_day_closed = true` but `is_reconciled = false` |
| `open` | `business_date === today` (`Africa/Cairo`) |
| `missing` | `business_date < today`, `business_date >= branch.opened_on`, and no closed row exists |
| `not_opened` | `business_date < branch.opened_on` |

A branch is complete over a period (`isBranchComplete()`) iff it has **zero `missing` days and zero `closed` (unreconciled) days**.
