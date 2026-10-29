# 01 — Group Overview Dashboard (لوحة التحكم الرئيسية)

**Requirement:** §1 — total sales; net profit and margin per branch; daily/weekly/monthly comparison
of restaurants; total expenses and purchases.
**Tier:** core · **Route:** `/analytics` (home after login) · **Conventions:** `00-conventions.md`

## Users & access
- `org_owner`, `org_analyst`: all branches, group totals.
- `branch_manager`: same page scoped to their branches; group totals and other branches hidden.

## Questions it answers
1. How much did the group sell and earn in this period, compared with the comparison period?
2. Which branches are driving or dragging the result right now?
3. Where is money going (expenses, purchases, waste)?
4. Is the data complete and fresh?

## Filters
Period preset (default **Today**, live) · comparison mode · branch picker · restaurant (brand).

## Layout

### Row 1 — KPI cards (each with value, comparison value, growth K24, sparkline of last 14 days)
| Card | KPI |
|---|---|
| المبيعات | K06 |
| صافي الربح | K14 |
| هامش الربح | K15 |
| عدد الطلبات | K01 |
| متوسط قيمة الطلب | K07 |
| المصروفات | K11 |
| المشتريات | K20 |
| الهالك | K12 (+ K17 as subtitle) |

### Row 2 — Sales trend
Line chart of K06 per day/week/month (auto granularity), current vs comparison period overlaid;
toggle "per branch" (stacked area, one series per branch, max 10 + "others").

### Row 3 — Branch table (the core of the page)
One row per branch, sortable, with a totals row:

| Column | KPI |
|---|---|
| الفرع + completeness dot | — |
| المبيعات + growth | K06, K24 |
| الطلبات | K01 |
| متوسط الطلب | K07 |
| مجمل الربح | K09 |
| صافي الربح | K14 |
| هامش الربح | K15 (colour scale red→green relative to group) |
| تكلفة الخامات % | K16 |
| الهالك % | K17 |
| المصروفات | K11 |
| المشتريات | K20 |
| المستهدف % | K23 (if a target exists, see report 08) |

Click a row → branch detail (same layout, filtered to one branch, plus shift list).

### Row 4 — Side panels
- **Profit bridge (waterfall):** Sales → −COGS → Gross profit → −Expenses → −Waste → −Inventory variance → Net profit.
- **Expense mix donut:** by expense category (labor, rent, utilities, …) → links to report 09.
- **Sales by channel:** `sales_by_type` (dine-in, takeaway, delivery, Talabat, web) as bar.
- **Data freshness strip:** each branch's last sync time; red if > 15 min while day open → links to report 10.

## Data
- Cards, table, bridge: `agg_branch_daily` summed over period, grouped by branch.
- Trend: `agg_branch_daily` grouped by bucket (`date_trunc('week'|'month', business_date)`).
- Today vs same-hour comparison: `agg_branch_hourly` up to current hour (conventions §1).
- Live refresh: subscribe to `org.{id}` Reverb channel; on `BranchDayUpdated` for a date in range → reload props (debounced 10 s).

## Edge cases
- Branch with no data today but `open` day → shows 0 with blue "مباشر"; with no heartbeat for > 15 min → amber "غير متصل".
- Comparison period before branch opened → growth "—", excluded from group growth when like-for-like is on.
- Negative net profit → red value, margin negative (do not clamp).

## Acceptance criteria
- With the shared fixture, last week: group K06, K14, K15, K11, K20 equal the fixture's expected values to the piaster.
- Branch manager of A sees only A; response contains no B figures (assert JSON props).
- Today view compares against same weekday last week up to the same hour.
- Page p95 < 500 ms with 50 branches × 365 days seeded.
