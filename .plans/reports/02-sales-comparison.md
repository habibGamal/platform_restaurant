# 02 — Sales Comparison (مقارنة المبيعات وعدد الطلبات ومتوسط قيمة الطلب)

**Requirement:** §2 — compare sales, order count and average order value between restaurants.
**Tier:** core · **Route:** `/analytics/sales` · **Conventions:** `00-conventions.md`

## Users & access
`org_owner`, `org_analyst` (any accessible branches). `branch_manager`: own branches only; can compare
their branch against **group average** (anonymized benchmark), never against named other branches.

## Questions it answers
1. Which branch sells most / least, and is the gap due to **traffic** (orders) or **basket** (avg ticket)?
2. How does each branch's sales mix by channel and payment method differ?
3. How did each branch move versus the comparison period?

## Filters
Period · comparison mode · branch picker (2+ branches, or all) · channel (order type) · like-for-like toggle.

## Layout

1. **Side-by-side KPI matrix** — rows = branches, columns = K06, K01, K07, K03 (discounts), K21 (cancel rate), K22 (return rate); each cell shows value + growth badge. Group average row at the bottom.
2. **Traffic vs basket scatter** — x = orders per day (K01 ÷ days with data), y = avg ticket (K07), bubble size = K06. Quadrant lines at group medians: "high traffic / low basket" etc.
3. **Sales trend per branch** — multi-line K06 by day/week/month; toggle to "indexed" (first bucket = 100) so branches of different size can be compared.
4. **Growth decomposition table** — for each branch:
   `Δsales ≈ Δorders effect + Δticket effect`
   - orders effect = (orders₁ − orders₀) × ticket₀
   - ticket effect = (ticket₁ − ticket₀) × orders₁
   (sums exactly to Δ net sales K04; returns shown as separate line).
5. **Channel mix** — 100 % stacked bar per branch from `sales_by_type`; and payment mix from `sales_by_method` (cash / card / Talabat card).
6. **Discount intensity** — K03 ÷ K02 per branch (high discount share is a pricing/control signal).

## Data
- `agg_branch_daily` (orders_count, net_sales, gross_sales, discounts, returns_amount, cancelled_count, sales_by_type, sales_by_method).
- Channel filter: requires per-channel figures → computed from `sales_by_type` json (`orders`, `net_sales` per type).
  **Aggregator requirement:** `sales_by_type` must store `{type: {orders, net_sales}}`, not only amounts.

## Edge cases
- Branches with different number of open days in the period → scatter uses per-day averages; matrix shows totals plus "أيام العمل" column.
- A channel a branch doesn't offer (e.g. no Talabat) → empty, not zero, in channel mix.
- Indexed trend when first bucket is 0 → index from the first non-zero bucket.

## Acceptance criteria
- Fixture: A vs B last week — K06, K01, K07 match expected; orders effect + ticket effect = ΔK04 exactly.
- Channel filter "delivery" changes all figures consistently (orders, sales, ticket).
- Branch manager sees own branch + "متوسط المجموعة" row and no other branch names.
