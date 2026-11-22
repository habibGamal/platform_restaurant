# 07. Metrics Query, KPI Catalog, Overhead Allocation, and Period Math

This document provides a comprehensive mathematical and technical specification of the analytics query engine (`MetricsQuery`), period alignment, like-for-like branch resolution, the complete KPI formulas catalog ($K01$ through $K25$), overhead allocation rules, and completeness determination.

---

## 1. Metrics Query Engine ([`MetricsQuery`](../../app/Domain/Metrics/MetricsQuery.php))

Many report services use `MetricsQuery` for branch selection and aggregate queries. Some report widgets and services also query warehouse, dimension, or operational tables directly; inspect each report's source. `MetricsQuery` encapsulates branch security, filter intersection, like-for-like scoping, and aggregate database querying.

### Branch Scope Resolution Algorithm ([`MetricsQuery`](../../app/Domain/Metrics/MetricsQuery.php))
1. **Security Baseline**: Fetches authorized branch IDs for the authenticated user via [`AccessibleBranches::ids()`](../../app/Domain/Tenancy/AccessibleBranches.php).
2. **Explicit Branch Selection**: If `$filters->branchIds` is populated:
   - Verifies all requested branches exist in `$accessibleBranchIds`.
   - Throws `Illuminate\Auth\Access\AuthorizationException` if any unauthorized branch is requested.
   - Sets `$candidateIds = $filters->branchIds`.
3. **Branch Set Intersection**: If `$filters->branchSetId` is set:
   - Queries `branch_sets` for the user's organization (scoped to public sets or sets owned by the user).
   - Intersects `$candidateIds` with set members: `$candidateIds = array_intersect($candidateIds, $setIds)`.
4. **Restaurant Intersection**: If `$filters->restaurantId` is set:
   - Queries all branches belonging to the restaurant within the organization.
   - Intersects: `$candidateIds = array_intersect($candidateIds, $restaurantIds)`.
5. **Mandatory Security Intersect**: Always intersects candidate branches with accessible branches:
   ```php
   $resolved = array_values(array_intersect($candidateIds, $accessibleBranchIds));
   ```
6. **Like-for-Like (LFL) Filtering**:
   If `$filters->likeForLike === true` and a comparison period is active:
   - Queries `agg_branch_daily` to find branches having rows within the current period.
   - Queries `agg_branch_daily` to find branches having rows within the comparison period.
   - Retains only branches with data in **both** periods:
     ```php
     $resolved = array_values(array_intersect($resolved, $currentBranches, $comparisonBranches));
     ```

### Series Bucketing & Ordered Keys ([`MetricsQuery::series()`](../../app/Domain/Metrics/MetricsQuery.php))
When generating chart time series:
- Iterates through every calendar date in the period and determines its bucket start using `$granularity->bucketStart($date)`.
- Pre-populates all ordered buckets with `KpiValues::zero()`.
- Fetches rows from `agg_branch_daily`, aggregates them into their corresponding buckets, and sums them via `KpiValues::plus()`.
- Ensures time-series charts receive contiguous bucket keys without missing dates.

---

## 2. Periods, Presets, Timezones, and Year-over-Year 364-Day Alignment

- **Application Timezone**: Standardized to `Africa/Cairo` across all business date evaluations.
- **Week Start**: The business week begins on **Saturday** (`CarbonInterface::SATURDAY`).

### Period Presets ([`PeriodResolver::resolve()`](../../app/Domain/Metrics/Period/PeriodResolver.php))

| Preset Enum | Date Range Definition |
|---|---|
| `today` | Current calendar day (`startOfDay` to `startOfDay`). |
| `yesterday` | `today - 1 day`. |
| `this_week` | Current Saturday through today. |
| `last_week` | Saturday through Friday of the previous week (`subDays(7)`). |
| `this_month` | 1st day of the current month through today. |
| `last_month` | Entire preceding calendar month. |
| `this_quarter` | Start of the current quarter through today. |
| `this_year` | January 1st of the current year through today. |
| `last_30_days` | `today - 29 days` through today (30 calendar days). |
| `last_8_weeks` | Seven Saturdays before the current week through today (`$today->startOfWeek(SATURDAY)->subWeeks(7)->startOfDay()` to `$today`). Spans 7 prior full weeks plus the current in-progress week up to today. |
| `custom` | Explicit `from` and `to` dates supplied by the user. |

### Intraday Cutoff Logic
If a period's end date is today:
```php
$cutoff = ($periodTo->toDateString() === $today->toDateString()) ? $nowTime : null;
```
For hourly reports, [`MetricsQuery::hourlyTotals()`](../../app/Domain/Metrics/MetricsQuery.php) restricts comparisons to hours $\le$ current hour, ensuring aligned comparisons against previous periods.

### Comparison Modes & 364-Day Shift ([`PeriodResolver::comparison()`](../../app/Domain/Metrics/Period/PeriodResolver.php))
1. `none`: No comparison period.
2. `previous`: Natural preceding period (e.g., previous month or identical elapsed duration shifted backwards).
3. `same_weekday_last_week`: Shifts the date range exactly **-7 days**.
4. `same_period_last_year`: **Shifts the date range exactly -364 days ($52\text{ weeks} \times 7\text{ days}$)**:
   - Preserves weekday-for-weekday alignment (e.g. Saturday compares to Saturday, Friday to Friday).
   - Avoids distorting restaurant metrics by calendar drift where weekends shift across dates.

### Automatic Granularity Selection ([`Granularity::auto()`](../../app/Domain/Metrics/Period/Granularity.php))
- **$\le 31$ days**: `Granularity::day` (daily points).
- **$32$ to $182$ days**: `Granularity::week` (weekly points, bucket start: Saturday).
- **$> 182$ days**: `Granularity::month` (monthly points, bucket start: 1st of month).

---

## 3. Comprehensive KPI Formulas Catalog ($K01$ – $K25$)

All core KPIs are implemented in [`KpiValues`](../../app/Domain/Metrics/KpiValues.php) and [`Kpi`](../../app/Domain/Metrics/Kpi.php).

> [!NOTE]
> **Ratio Boundaries and Zero/Null Guards**:
> - Ratios in [`KpiValues`](../../app/Domain/Metrics/KpiValues.php) are **not** bounded to $[0..1]$. Ratios can be negative (e.g. negative gross or net margin when operating at a loss, or negative growth) or exceed 1.0 (e.g. food cost % or waste % exceeding 100% of net sales, return rate exceeding 1.0 if refunds exceed sales in a period).
> - In [`KpiValues`](../../app/Domain/Metrics/KpiValues.php), ratio and division methods return `null` strictly when the denominator is **exactly zero** (`== 0.0` or `=== 0`).
> - Individual report services may apply custom guards suited to their specific domain presentation (for example, [`ProductPerformanceReport`](../../app/Domain/Metrics/Reports/ProductPerformanceReport.php) guards margin percent via `$totalNetSales > 0 ? ($margin / $totalNetSales) : null`).

| Code | Metric Name | Mathematical Formula | Unit | Zero / Null Behavior in `KpiValues` | Favorability Direction |
|---|---|---|---|---|---|
| **$K01$** | Orders Count | $\sum \text{orders (completed)}$ | Integer | `0` | Higher is better |
| **$K02$** | Gross Sales | $\sum \text{wh\_orders.sub\_total}$ | EGP | `0.00` | Higher is better |
| **$K03$** | Discounts | $\sum \text{discount} + \sum \text{items\_discount}$ | EGP | `0.00` | Lower is better |
| **$K04$** | Net Sales | $\sum (\text{total} - \text{tax})$ | EGP | `0.00` | Higher is better |
| **$K05$** | Returns Amount | $\sum \text{wh\_order\_returns.total\_refund}$ | EGP | `0.00` | Lower is better |
| **$K06$** | Net Sales After Returns | $K04 - K05$ | EGP | `0.00` | Higher is better |
| **$K07$** | Average Ticket | $\frac{K04}{K01}$ | EGP | `null` if $K01 = 0$ | Higher is better |
| **$K08$** | Cost of Goods Sold (COGS) | $\sum (\text{qty} \times \text{unit\_cost})$ | EGP | `0.00` | Lower is better |
| **$K08a$** | Recovered Return COGS | $\sum \text{returned\_cogs} \text{ where reverse\_stock=true}$ | EGP | `0.00` | Higher is better |
| **$K09$** | Gross Profit | $K06 - (K08 - K08a)$ | EGP | `0.00` | Higher is better |
| **$K10$** | Gross Margin % | $\frac{K09}{K06}$ | Ratio (can be $< 0$) | `null` if $K06 = 0.0$ | Higher is better |
| **$K11$** | Total Direct Expenses | $\sum \text{wh\_expenses.amount}$ | EGP | `0.00` | Lower is better |
| **$K11a$** | Direct Labor Cost | $\sum \text{expenses (category 'labor')}$ | EGP | `0.00` | Lower is better |
| **$K11b$** | Direct Rent Cost | $\sum \text{expenses (category 'rent')}$ | EGP | `0.00` | Lower is better |
| **$K12$** | Waste Cost | $\sum \text{wh\_wastes.total}$ | EGP | `0.00` | Lower is better |
| **$K13$** | Inventory Variance Cost | $\sum \text{wh\_stocktakings.variance\_cost}$ | EGP | `0.00` | Lower is better |
| **$K14$** | Net Profit | $K09 - K11 - K12 - K13$ | EGP | `0.00` | Higher is better |
| **$K15$** | Net Margin % | $\frac{K14}{K06}$ | Ratio (can be $< 0$) | `null` if $K06 = 0.0$ | Higher is better |
| **$K16$** | Food Cost % | $\frac{K08 - K08a}{K06}$ | Ratio (can exceed $1.0$) | `null` if $K06 = 0.0$ | **Lower is better** |
| **$K17$** | Waste % of Sales | $\frac{K12}{K06}$ | Ratio (can exceed $1.0$) | `null` if $K06 = 0.0$ | **Lower is better** |
| **$K17b$** | Waste Share of Usage | $\frac{K12}{(K08 - K08a) + K12}$ | Ratio (can exceed $1.0$) | `null` if denominator $= 0.0$ | **Lower is better** |
| **$K18$** | Labor Cost % | $\frac{\text{Allocated Labor}}{K06}$ | Ratio (can exceed $1.0$) | `null` if $K06 = 0.0$ | **Lower is better** |
| **$K19$** | Prime Cost % | $\frac{(K08 - K08a) + \text{Allocated Labor}}{K06}$ | Ratio (can exceed $1.0$) | `null` if $K06 = 0.0$ | **Lower is better** |
| **$K20$** | Net Purchases | $\text{Purchases} - \text{Purchase Returns}$ | EGP | `0.00` | Contextual |
| **$K21$** | Order Cancel Rate | $\frac{\text{Cancelled Orders}}{\text{Completed Orders} + \text{Cancelled Orders}}$ | Ratio ($0..1$) | `null` if total orders $= 0$ | **Lower is better** |
| **$K22$** | Return Rate | $\frac{K05}{K04}$ | Ratio (can exceed $1.0$) | `null` if $K04 = 0.0$ | **Lower is better** |
| **$K23$** | Target Achievement | $\frac{\text{actual to date}}{\text{target to date}}$ | Ratio | `null` when the target to date is zero or no target exists | Depends on the metric; food-cost targets are ceilings |
| **$K24$** | Growth Delta Rate | $\frac{\text{Current} - \text{Previous}}{\|\text{Previous}\|}$ | Ratio (can be $< 0$ or $> 1.0$) | `null` if $\text{Prev} = 0$ or null | Defined by KPI |
| **$K25$** | Cash Deficit Total | $\sum_{\text{closed shifts}} \max(0, \text{end\_cash} - \text{real\_cash})$ | EGP | `0.00` | **Lower is better** |

K23 is computed by [`TargetAchievement`](../../app/Domain/Targets/TargetAchievement.php), not `KpiValues`: cumulative targets use their daily distribution, while `food_cost_pct` uses the monthly ceiling directly. Report-specific variants may use different guards or periods.

---

## 4. Overhead Cost Allocation Engine ([`AllocatedCost`](../../app/Domain/Metrics/AllocatedCost.php))

Fixed monthly costs like labor contracts and branch rent are rarely entered as daily POS expenses. The platform allocates labor and rent dynamically across reporting periods:

### Three-Tier Calculation Rules ([`AllocatedCost::calculate()`](../../app/Domain/Metrics/AllocatedCost.php))
For each branch and each month overlapping the selected period:
1. **Rule 1 (Past Months)**: If the month is fully in the past (`monthEnd < today`), uses the actual sum of `labor_cost` or `rent_cost` recorded in `agg_branch_daily`.
2. **Rule 2 (Current / Future Months - Budgeted Rate)**: Sums `dim_expense_types.avg_month_rate` for expense types mapped to category `labor` or `rent`.
3. **Rule 3 (Fallback - Trailing Average)**: If no budget rate is set, computes the trailing 3-month average actual cost from `agg_branch_daily`.
4. **Proration Factor**: The monthly cost is prorated by the number of overlapping days:
   $$\text{Allocated Cost} = \text{Monthly Basis} \times \left( \frac{\text{Period Days Overlapping Month}}{\text{Total Days in Month}} \right)$$

---

## 5. Completeness Engine ([`Completeness`](../../app/Domain/Metrics/Completeness.php))

Completeness verifies whether a branch's data pipeline is up-to-date and fully reconciled before drawing conclusions or ranking branches.

### Status Taxonomy per Branch-Day ([`CompletenessResult::statusFor()`](../../app/Domain/Metrics/CompletenessResult.php))
- **`complete`**: `agg_branch_daily` row exists with `is_day_closed = true` and `is_reconciled = true`.
- **`closed`**: Day is closed (`is_day_closed = true`), but day summary reconciliation failed or is pending (`is_reconciled = false`).
- **`open`**: The date is today (`Africa/Cairo`) and transactions are still actively ingesting.
- **`missing`**: The branch was active and opened on or before that date, the date is in the past, and no closed row exists.
- **`not_opened`**: The calendar date is prior to `branches.opened_on`.

### Branch Completeness Rule ([`CompletenessResult::isBranchComplete()`](../../app/Domain/Metrics/CompletenessResult.php))
A branch is marked complete over a period **if and only if it has zero `missing` days and zero `closed` (unreconciled) days**. Incomplete branches are excluded from official branch rankings with full explanatory diagnostics.
