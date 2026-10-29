# Feature — Targets Management (تحديد أهداف المبيعات والأرباح لكل مطعم)

**Requirement:** §4 — set sales and profit targets for each restaurant. Consumed by report 08, ranking 07, alerts 18.

## Model
`targets`: id, organization_id, branch_id, metric (`net_sales` | `net_profit` | `orders` | `food_cost_pct`),
period_month (first day of month), value (numeric(14,2); for `food_cost_pct` a ratio 0–1),
distribution (`weekday_weights` | `flat`), notes, created_by, updated_by, timestamps.
Unique `(branch_id, metric, period_month)`. Changes are audited.

## App panel — Management group (Filament, `targets.manage`)
1. **Targets grid page** — rows = branches, columns = next 12 months, one metric at a time; inline edit; totals row = group target.
2. **Bulk tools**:
   - "Copy last year + X %" per branch (uses last year's same-month actual K06/K14).
   - "Copy previous month".
   - Excel import/export (columns: branch, month, metric, value) with validation report.
3. **Restaurant-level target split** (optional): enter a target for a restaurant → split across its branches by last 3 months' sales share; user can adjust before saving.
4. Locking: targets for past months are read-only unless the owner unlocks (audited).

## Distribution
`TargetDistributor::daily(Target): array<string date, float>` (spec in report 08). Re-computed when target or
history changes; cached in `target_daily` (target_id, date, value) for fast joins in reports and alerts.

## Acceptance criteria
- Grid saves upsert by unique key; second save of same cell updates, not duplicates.
- "Last year + 10 %" for a branch with last-year actual 250,000 → 275,000.
- Excel import rejects unknown branch names and negative values, reporting row numbers.
- Daily distribution for a month sums exactly to the monthly value (last day absorbs rounding).
