# 09. Targets, History-Based Allocation, and Branch Ranking Engine

This document specifies the target management framework, automated daily allocation based on 8-week history, target achievement tracking, and the mathematical multi-criteria scoring algorithm for branch ranking.

---

## 1. Target Data Architecture

Targets are configured per branch, metric, and calendar month ([`2026_11_02_000005_create_targets_and_calendar_tables.php`](../../database/migrations/2026_11_02_000005_create_targets_and_calendar_tables.php)):

- **Table `targets`**:
  - `branch_id`: Branch ID.
  - `metric`: Evaluated KPI string (`net_sales`, `net_profit`, `orders`, `food_cost_pct`).
  - `period_month`: First calendar date of the month (`YYYY-MM-01`).
  - `value`: Target value (monetary amount, integer orders count, or ratio for `food_cost_pct`).
  - `distribution`: Distribution strategy (`weekday_weights` or `flat`).
  - Unique constraint: `['branch_id', 'metric', 'period_month']`.
- **Table `target_daily`**:
  - `target_id`: Foreign key to `targets.id` with cascade on delete.
  - `date`: Calendar date (`YYYY-MM-DD`).
  - `value`: Daily allocated target value.
  - Primary key: `['target_id', 'date']`.

### Automatic Allocation Trigger ([`TargetObserver`](../../app/Domain/Targets/TargetObserver.php))
When a `Target` model is created or updated, `TargetObserver` automatically invokes [`TargetDistributor::persist()`](../../app/Domain/Targets/TargetDistributor.php), calculating and writing daily records to `target_daily`.

---

## 2. Daily Target Allocation Engines ([`TargetDistributor`](../../app/Domain/Targets/TargetDistributor.php))

The distributor handles branch inception dates, calendar anomalies, and historical seasonality:

```
[ Target Input: Value, Month, Strategy ]
                   |
                   v
  Filter calendar days >= branch.opened_on
                   |
     +-------------+-------------+
     |                           |
[ flat ]               [ weekday_weights ]
     |                           |
     |              Find last Friday before month
     |              Take 8 full completed weeks (56 days)
     |              Exclude calendar_events (Ramadan, Eid, promos)
     |                           |
     |             [ History >= 4 clean weeks & Sales > 0? ]
     |                           |
     |                 +---------+---------+
     |                 |                   |
     |              [ Yes ]              [ No ]
     |                 |                   |
     |        Calculate weekday      Fallback to flat
     |        shares (0..6)
     |                 |
     v                 v
  Distribute value proportionally across eligible days
  Absorb fractional rounding into the final day of the month
```

### 1. Flat Distribution Engine ([`TargetDistributor::distributeFlat()`](../../app/Domain/Targets/TargetDistributor.php))
Divided equally across eligible calendar days ($K$):
$$\text{Base} = \text{round}\left( \frac{\text{Target Value}}{K}, 2 \right)$$
- Days $1$ through $K-1$ receive $\text{Base}$.
- Day $K$ absorbs fractional rounding differences:
  $$\text{Value}_K = \text{round}\left( \text{Target Value} - \sum_{i=1}^{K-1} \text{Value}_i, 2 \right)$$
- Ensures the sum of daily targets matches the monthly target.

### 2. Weekday Weights Distribution Engine ([`TargetDistributor::computeWeekdayWeights()`](../../app/Domain/Targets/TargetDistributor.php))
1. **Historical Baseline Window**: Identifies the last Friday before the target month begins. Takes the 8 preceding full completed weeks (exactly 56 days, starting on a Saturday and ending on Friday).
   > [!NOTE]
   > This 56-day completed historical window in `TargetDistributor` differs from the `last_8_weeks` preset in `PeriodResolver`, which spans from seven Saturdays before the current week through today (including the current partial week).
2. **Calendar Event Exclusions**: Queries `calendar_events` for events overlapping the 8 weeks (such as Ramadan, Eid, or promotional campaigns). All overlapping dates are excluded from the baseline.
3. **Fallback Condition**: If fewer than 4 weeks of history remain after exclusions, or if total sales $\le 0.0$, the engine **falls back automatically to flat distribution**.
4. **Weekday Shares**: Sums `net_sales_after_returns` for each weekday $w \in [0..6]$ (Saturday through Friday) across the clean weeks:
   $$\text{Weight}_w = \frac{\text{Sales}_w}{\sum_{i=0}^6 \text{Sales}_i}$$
5. **Daily Allocation**: Maps each eligible date to its weekday weight, normalizes across the month's days, and allocates proportionally. The final eligible day absorbs rounding discrepancies.

---

## 3. Bulk Restaurant Target Creation

Located in [`ListTargets`](../../app/Filament/App/Resources/TargetResource/Pages/ListTargets.php) via action **"إضافة أهداف مؤشرات المطعم"**:

- **Target Level Semantics**: When a manager enters targets for a restaurant, each value is applied as an individual target **for each available branch** in that restaurant—it is not an aggregate pool split among branches.
- **Non-Destructive Behavior**: Existing targets for that branch, metric, and month are preserved and not overwritten.
- **Percentage Conversion**: `food_cost_pct` input (e.g. `32%`) is divided by 100 ($0.32$) before storage.

---

## 4. Target Achievement Tracking ([`TargetAchievement`](../../app/Domain/Targets/TargetAchievement.php))

For any day within the active month:
- **Elapsed Days ($D_e$)**: Current day of month.
- **Remaining Days ($D_r$)**: Calendar days remaining in month.
- **Target To-Date**: Sum of `target_daily.value` from 1st of month through today.
- **Actual To-Date**: Sum of actual KPI values from `agg_branch_daily` through today.
- **Achievement %**:
  $$\text{Achievement} = \frac{\text{Actual To-Date}}{\text{Target To-Date}} \times 100$$
- **Projected Month-End**:
  $$\text{Current Daily Run-rate} = \frac{\text{Actual To-Date}}{D_e}$$
  $$\text{Projected} = \text{Actual To-Date} + (\text{Current Daily Run-rate} \times D_r)$$
- **Required Daily Run-rate**:
  $$\text{Required Run-rate} = \frac{\text{Monthly Target} - \text{Actual To-Date}}{D_r}$$

---

## 5. Branch Ranking & Scoring Engine ([`BranchRankingReport`](../../app/Domain/Metrics/Reports/BranchRankingReport.php))

The branch ranking engine produces a normalized league table across 7 operational KPIs.

### Evaluated Criteria & Default Weights

| KPI Key | Evaluated Metric | Default Weight | Optimization Direction |
|---|---|---|---|
| `net_margin` | Net Profit Margin ($K15$) | **25.0%** | Higher is better |
| `sales_growth` | Net Sales Growth ($K24$) | **20.0%** | Higher is better |
| `target_achievement` | Sales Target Achievement % | **20.0%** | Higher is better |
| `food_cost_pct` | Food Cost % ($K16$) | **15.0%** | **Lower is better** |
| `waste_pct` | Waste % of Sales ($K17$) | **10.0%** | **Lower is better** |
| `avg_ticket_growth` | Average Ticket Growth | **5.0%** | Higher is better |
| `cancel_rate` | Order Cancellation Rate ($K21$) | **5.0%** | **Lower is better** |

Custom weight profiles can be configured per organization via the `scoring_profiles` table.

### 1. Incomplete Branch Exclusion
Branches that have missing or unreconciled days in the period are excluded from official ranking:
- Prevents branches with missing data from appearing falsely profitable or lean.
- Excluded branches are displayed in an auxiliary diagnostic section with complete/total days and error status (`ExcludedBranchDto`).
- If $\le 1$ branch is eligible, the report enters **Single Branch Mode**, omitting ranks and showing raw KPIs only.

### 2. Min-Max Percentile Scoring ([`BranchRankingReport::computePercentileScores()`](../../app/Domain/Metrics/Reports/BranchRankingReport.php))
For each KPI across all eligible branches:
- If $\text{Max} == \text{Min}$, all branches receive a neutral score of `50.0`.
- **Higher is Better**:
  $$\text{Score}_b = \left( \frac{V_b - \text{Min}}{\text{Max} - \text{Min}} \right) \times 100$$
- **Lower is Better**:
  $$\text{Score}_b = \left( \frac{\text{Max} - V_b}{\text{Max} - \text{Min}} \right) \times 100$$

### 3. Missing KPI Weight Redistribution ([`BranchRankingReport`](../../app/Domain/Metrics/Reports/BranchRankingReport.php))
If a branch cannot evaluate a specific KPI (e.g. no comparison period for growth, or no target set), the branch is not penalized:
- Available weights are summed: $W_{\text{avail}} = \sum_{k \in \text{Available}} W_k$.
- Overall score is normalized over available criteria:
  $$\text{Overall Score} = \frac{\sum_{k \in \text{Available}} (\text{Score}_k \times W_k)}{W_{\text{avail}}}$$
- The branch is flagged with `hasUnavailable = true` and effective weights are displayed.

### 4. Ties and Rank Delta Calculation
- **Standard Competition Ranking**: In the event of identical overall scores, branches tie: e.g. Ranks $1, 2, 2, 4$.
- **Rank Delta**: Compares current rank against the previous period rank:
  $$\text{Delta} = \text{Rank}_{\text{prev}} - \text{Rank}_{\text{curr}}$$
  - $\text{Delta} > 0$: Rank improved ($\blacktriangle +N$, green).
  - $\text{Delta} < 0$: Rank declined ($\blacktriangledown N$, red).
  - $\text{Delta} = 0$: Unchanged ($=$, gray).

### 5. Branch Name Anonymization
To maintain healthy competition without exposing sensitive branch locations to store-level managers:
- Evaluates `$query->canSeeOtherBranchNames()` and organization setting `show_names_to_managers`.
- If unauthorized, branches other than the manager's assigned branch are anonymized as "فرع 1", "فرع 2", etc.
