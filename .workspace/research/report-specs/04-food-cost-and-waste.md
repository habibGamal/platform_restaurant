# 04 — Raw-Material Cost & Waste Comparison (مقارنة تكلفة الخامات ونسبة الهالك)

**Requirement:** §2 — compare raw-material cost and waste ratio between restaurants.
**Tier:** core · **Route:** `/analytics/food-cost` · **Conventions:** `00-conventions.md`

## Users & access
`org_owner`, `org_analyst`; `branch_manager` own branches + group average.

## Questions it answers
1. Which branch spends the most on materials per pound of sales (food cost %)?
2. Which branch wastes most, and which items are wasted?
3. Is there hidden loss (stocktaking shortage) beyond declared waste?
4. Is food cost rising because purchase prices rose, or because of waste / portioning?

## Filters
Period (default **This month**) · comparison · branch picker · category · product type (raw material / manufactured).

## Layout

1. **KPI matrix per branch** — K16 food cost %, K17 waste % of sales, waste share of usage
   (K12 ÷ (K08 + K12)), K13 inventory variance and its % of sales, **total loss %** = (K12 + K13) ÷ K06.
   Target band from org settings (e.g. food cost 28–35 %) coloured green/amber/red.
2. **Food cost % trend** — per branch, weekly.
3. **Top wasted items** — table: product, branch, qty, cost, % of that branch's waste; filterable by branch.
   Source: `agg_product_daily.waste_qty/waste_cost`.
4. **Stocktaking variance table** — last stocktaking per branch: items with the largest shortage value,
   `stock_quantity` vs `real_quantity`, price, value. Drill to the stocktaking document.
5. **Purchase price movement** — for top 20 raw materials by spend: avg purchase price this period vs
   comparison period, per branch (spots one branch paying more for the same material, matched by `product_ref`).
6. **Theoretical vs actual usage** (phase 2, needs `inventory_day` + `recipe`):
   theoretical = Σ sold qty × recipe qty; actual = start + incoming − end − waste;
   gap per raw material = unexplained usage (over-portioning / theft).

## Data
- `agg_branch_daily`: cogs, waste_cost, inventory_variance_cost, net_sales_after_returns.
- `agg_product_daily`: waste_qty, waste_cost.
- `wh_stocktaking_items`, `wh_purchase_items` (direct warehouse queries, period-bounded, via MetricsQuery).
- `wh_inventory_days` + `dim_recipes` for section 6.

## Edge cases
- Cross-branch material comparison requires the same `product_ref`; unmatched products are listed under "غير موحّد" with a link to the catalog mapping screen (`catalog_items`).
- Units must match (kg vs g) — mapping screen stores a unit conversion factor; without it, the row is excluded from price comparison.
- Branch that never records waste → K17 = 0 % with warning icon "لا يتم تسجيل هالك" (0 % waste is a data-discipline red flag, not a success).

## Acceptance criteria
- Fixture: K16, K17, K13 per branch match expected values.
- Top wasted items list equals fixture's waste docs ordered by cost.
- Price movement shows only products present (same `product_ref`) in both periods.
