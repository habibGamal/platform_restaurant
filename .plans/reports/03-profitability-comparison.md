# 03 — Profitability Comparison (مقارنة الأرباح وهوامش الربح)

**Requirement:** §1 net profit and margin per branch; §2 compare profits and margins.
**Tier:** core · **Route:** `/analytics/profitability` · **Conventions:** `00-conventions.md`

## Users & access
`org_owner`, `org_analyst`. `branch_manager`: own branches + anonymized group average.

## Questions it answers
1. Which branches make the most money and the best margin (not the same thing)?
2. Where does each branch lose margin: material cost, expenses, waste or inventory shortage?
3. How did margins move versus the comparison period?

## Filters
Period (default **This month**) · comparison mode · branch picker · labor basis (paid / allocated).

## Layout

1. **P&L matrix (branches as columns)** — rows:
   Sales K06 · COGS K08 · Gross profit K09 · Gross margin K10 · Labor K11a · Rent · Other expenses ·
   Waste K12 · Inventory variance K13 · **Net profit K14** · **Net margin K15**.
   Each cell also shows % of sales. Group total column. Comparison period toggle shows Δ per cell.
2. **Margin ladder** — horizontal bars of K15 per branch, sorted, with group margin line.
3. **Margin leakage chart** — for each branch, stacked bar of cost ratios as % of sales:
   food cost (K16), labor (K18), other expenses, waste (K17), variance → remainder = net margin.
   Makes "why is branch X less profitable" visible at a glance.
4. **Margin trend** — K10 and K15 per branch by week/month.
5. **Profit vs sales scatter** — x = K06, y = K15; spots big-but-thin branches.

## Data
`agg_branch_daily`: net_sales_after_returns, cogs, returned_cogs_recovered, gross_profit, expenses_total,
labor_cost, rent_cost, other_expenses, waste_cost, inventory_variance_cost, net_profit.
Allocated labor/rent per conventions §4 (computed in `Kpi::allocatedMonthlyCost()`).

## Edge cases
- Stocktaking happens once a month → K13 lands on one day. In daily/weekly views show a note
  "يتضمن عجز جرد بتاريخ …" and offer "exclude inventory variance" toggle.
- POS `profit_pos` differs from platform K09 → platform value wins; the difference is listed in report 10.
- Branch with sales but no expenses recorded for the month → flag "لا توجد مصروفات مسجلة" (likely data gap, not a great margin).

## Acceptance criteria
- Fixture month: P&L matrix values match expected for A, B and total; rows sum correctly (K09 − K11 − K12 − K13 = K14).
- Labor basis toggle changes only labor-derived rows.
- Margin leakage segments + net margin = 100 % of sales for each branch.
