# Targets & Branch Ranking

Monthly target planning (`app/Domain/Targets/`) and multi-criteria branch ranking (`app/Domain/Metrics/Reports/BranchRankingReport.php`) share data with Report 08 (`/app/reports/targets`) and Report 07 (`/app/reports/ranking`).

---

## 1. Monthly Targets & Daily Distribution (`TargetDistributor`)

Targets are stored in `targets` (`UNIQUE(branch_id, metric, period_month)`) for four metrics:
- `net_sales` (`K06`)
- `net_profit` (`K14`)
- `orders` (`K01`)
- `food_cost_pct` (`K16`, stored as a ratio `0..1`, acting as a ceiling)

Whenever a `Target` is created or updated, `TargetObserver` persists daily slices to `target_daily` via `TargetDistributor::persist()`.

### Distribution Strategies

1. **Eligible days:** Calendar days in `period_month` on or after `branches.opened_on`. Earlier days receive `0.0`.
2. **`flat`:** Divides `value` equally (`round(value / K, 2)`), with the final eligible day absorbing rounding remainder so `SUM(target_daily) === value`.
3. **`weekday_weights` (default):**
   - Finds the last Friday before `period_month` starts and takes the preceding **8 completed weeks (56 days, Saturday to Friday)**.
   - Excludes dates overlapping `calendar_events` (global or organization-scoped `ramadan`, `eid`, `holiday`, `promo`).
   - If fewer than **4 clean weeks** remain or total sales `<= 0.0`, automatically falls back to **`flat`**.
   - Otherwise computes each weekday's share (`0..6`) of `net_sales_after_returns` and distributes proportionally (final eligible day absorbs rounding).

### Management UI (`ListTargets` / `TargetResource`)
- **Bulk Restaurant Allocation (`restaurantTargets`):** Prompts for restaurant, month, distribution, and metric values. Applies each entered value as an individual target **per accessible branch** in that restaurant (not split as a pool) and skips branches that already have a target for that metric/month. `food_cost_pct` inputs are divided by `100`.
- **Copy Previous Month (`copyPreviousMonth`):** Copies the preceding month's targets into the selected target month.

### Achievement & Pace (`TargetAchievement`)
- Cumulative metrics (`net_sales`, `net_profit`, `orders`) compare actual MTD against `SUM(target_daily)` through `today`, projecting month-end via pace factor (`actual_to_date / target_to_date`) and computing required daily run-rate over remaining days.
- `food_cost_pct` compares actual period food cost % directly against the monthly ceiling.

---

## 2. Branch Ranking (`BranchRankingReport`)

Branches are ranked across **7 KPIs** using customizable organization weights in `scoring_profiles` (managed at `/app/management/scoring-profile`, weights must sum to `100`):

| KPI Key | Metric | Default Weight | Direction |
|---|---|---|---|
| `net_margin` | Net Margin (`K15`) | `25.0%` | Higher is better |
| `sales_growth` | Net Sales Growth (`K24`) | `20.0%` | Higher is better |
| `target_achievement` | Sales Target Achievement (`K23`) | `20.0%` | Higher is better |
| `food_cost_pct` | Food Cost % (`K16`) | `15.0%` | Lower is better |
| `waste_pct` | Waste % (`K17`) | `10.0%` | Lower is better |
| `avg_ticket_growth` | Average Ticket Growth | `5.0%` | Higher is better |
| `cancel_rate` | Order Cancel Rate (`K21`) | `5.0%` | Lower is better |

### Scoring Rules

1. **Incomplete-branch exclusion:** Branches with any `missing` or unreconciled `closed` day in the period (`CompletenessResult::isBranchComplete()`) are excluded from ranking and listed in an `ExcludedBranchDto` table.
2. **Single-branch mode:** If `<= 1` eligible branch remains, ranks are omitted and raw KPIs are shown.
3. **Min-max percentile scoring:** Each KPI is normalized to `[0, 100]` across eligible branches (`50.0` if `max == min`).
4. **Missing-KPI weight redistribution:** If a branch lacks a comparison period or target, that KPI's weight is excluded and the remaining available weights are re-normalized (`hasUnavailable = true`).
5. **Ties & deltas:** Identical scores share a rank (`1, 2, 2, 4`); `delta = previousRank - currentRank`.
6. **Anonymization:** Users without `analytics.view_other_branch_names` see sibling branches masked as `"فرع 1"`, `"فرع 2"`, etc.
