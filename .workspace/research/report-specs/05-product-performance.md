# 05 — Product Performance (مقارنة أداء الأصناف الأكثر والأقل مبيعاً)

**Requirement:** §2 — compare best- and worst-selling items across restaurants.
**Tier:** core · **Route:** `/analytics/products` · **Conventions:** `00-conventions.md`

## Users & access
`org_owner`, `org_analyst`; `branch_manager` own branches (group ranks of an item shown without other branch names).

## Questions it answers
1. What are the top / bottom items by quantity, sales and profit — per branch and for the group?
2. Does the same item sell differently across branches (same brand)?
3. Which items have high sales but low margin, or are barely sold?
4. How are categories performing?

## Filters
Period (default **Last 30 days**) · comparison · branch picker · category · rank by (qty | sales | gross profit | margin %) · top N (10/20/50) · include legacy items toggle.

## Layout

1. **Top N / Bottom N tables** (tabs) — product, category, qty (`qty_sold`), sales, gross profit, margin %,
   share of branch sales, growth vs comparison. "Bottom" excludes items with zero sales for the whole period
   (listed separately as **"لم يُبع"** — items active in catalog but not sold).
2. **Cross-branch item matrix** — rows = items (matched by `product_ref` / `catalog_items`),
   columns = branches, cells = qty or sales per day open; heat-coloured per row.
   Shows "sells well in A, not in B".
3. **Category performance** — treemap (size = sales, colour = margin %) and table per branch.
4. **Item detail drawer** — daily qty trend per branch, price history (from `dim_products` changes), cost trend (unit_cost over time), returns qty, waste qty.
5. **Pareto** — cumulative share of sales by item: "X items make 80 % of sales" per branch.

## Data
- `agg_product_daily` (qty_sold, net_sales, cogs, gross_profit, returns_qty, waste_qty) grouped by product.
- Names/categories from `dim_products` / `dim_categories`; cross-branch identity via `product_ref` → `catalog_items`.
- Not-sold list: `dim_products` (not legacy, not deleted, type = manufactured) LEFT JOIN period sales where qty = 0.

## Edge cases
- Different brands (restaurants) have different menus → cross-branch matrix only offered for branches of the **same restaurant** or for mapped `catalog_items`.
- Price changes mid-period → margin uses actual `unit_cost`/price per sale (already in aggregates), not current catalog values.
- Weighted items (quantity decimals, scale barcodes) → qty shown with unit (`kg`).
- Items renamed on POS → identity is `product_uuid`; name shown is the latest.

## Acceptance criteria
- Fixture: top 5 by qty and by profit for A equal expected lists; bottom list excludes never-sold items, which appear under "لم يُبع".
- Pareto percentages sum to 100 %.
- Branch manager item ranks computed against group without exposing other branch names.

> Pricing / discontinue **recommendations** are AI features → report 16 (menu engineering). This report is descriptive.
