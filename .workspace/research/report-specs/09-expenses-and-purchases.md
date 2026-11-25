# 09 — Expenses & Purchases (إجمالي المصروفات والمشتريات)

**Requirement:** §1 — total expenses and purchases (drill-down behind the dashboard cards).
**Tier:** core · **Route:** `/analytics/expenses` · **Conventions:** `00-conventions.md`

## Users & access
`org_owner`, `org_analyst`; `branch_manager` own branches.

## Questions it answers
1. How much did each branch spend, on what categories, versus last period and versus budget?
2. How much did each branch purchase, from which suppliers?
3. Are expenses growing faster than sales?

## Filters
Period (default **This month**) · comparison · branch picker · expense category · supplier.

## Layout

### Expenses
1. **Category × branch matrix** — categories (labor, rent, utilities, marketing, maintenance, other) × branches; cell = amount + % of that branch's sales. Totals row/column.
2. **Budget vs actual** — per expense type with `avg_month_rate` (POS field = monthly budget): month-to-date actual vs prorated budget; over-budget rows red.
3. **Expenses vs sales growth** — per branch: K24 of K11 vs K24 of K06 (expenses growing faster than sales = warning).
4. **Expense list** — drill: date, branch, shift, type, amount, notes (from `wh_expenses`).

### Purchases
5. **Purchases per branch** — net purchases (K20), invoices count, returns, trend by week.
6. **Supplier table** — supplier, branches buying, amount, invoices, share; supplier matched across branches by name normalization + manual mapping.
7. **Purchases vs COGS** — per branch: K20 vs K08. Purchases ≫ COGS for weeks = stock building up or unrecorded waste; ≪ = stock running down. Shown as ratio with explanation tooltip.

## Data
`agg_expense_daily` (by type & category), `dim_expense_types` (category, avg_month_rate), `wh_expenses` (list),
`agg_purchase_daily`, `dim_suppliers`, `agg_branch_daily.cogs`.

## Edge cases
- Expense types not yet categorized (category = `other` by default) → banner "N أنواع مصروفات غير مصنفة" linking to the mapping screen in the `app` panel (Management group). Categorization can also be set on the platform (overrides POS value).
- Deleted expense on POS → tombstone → disappears from aggregates on recompute.
- Purchases are never added to expenses or profit (architecture §6.3 accounting rule) — the page states this in a help note.

## Acceptance criteria
- Fixture: category matrix totals equal K11 per branch; labor row equals K11a.
- Budget proration on day 14 of a 30-day month = `avg_month_rate × 14/30`.
- Purchases vs COGS ratio matches fixture values.
