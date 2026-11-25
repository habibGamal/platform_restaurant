# 08 — Targets vs Actual (مقارنة المستهدف بالمحقق)

**Requirement:** §2 targets vs actual; §4 set sales and profit targets for each restaurant.
**Tier:** core · **Route:** `/analytics/targets` · **Conventions:** `00-conventions.md`
**Target setup UI:** `features/targets-management.md`

## Users & access
`org_owner`, `org_analyst`: all branches. `branch_manager`: own branches (read-only targets).

## Questions it answers
1. Are we on track for this month's sales and profit targets — per branch and for the group?
2. At the current pace, where will we finish the month?
3. What daily sales are needed from now on to hit the target?
4. Historically, how often does each branch hit its targets?

## Target metrics (v1)
`net_sales` (K06), `net_profit` (K14), `orders` (K01), `food_cost_pct` (K16, a ceiling — lower is better).
Targets are monthly per branch; group target = Σ branch targets (for ratio targets: sales-weighted).

## Daily target distribution
Monthly target is spread across days so "today's target" and "target to date" exist:
- `weekday_weights` (default): weight of each weekday = branch's avg share of weekly sales over the last 8 weeks (excluding calendar events); days in the month get target ∝ weight.
- `flat`: equal per day.
Computed by `TargetDistributor::daily(Target): array<date, amount>` and cached per target version.

## Layout
1. **Group gauge row** — for each metric: achievement to date (K23 = actual MTD ÷ target MTD), month achievement so far (actual ÷ full target), projection.
2. **Branch table** — target (month), target to date, actual to date, achievement %, gap (EGP), **projected month-end** and projected achievement %, **required daily run-rate** for remaining days vs current daily average. Status chip: ✅ on track (≥ 100 %), ⚠️ at risk (90–100 %), ❌ behind (< 90 %).
3. **Burn-up chart** (per branch or group) — cumulative actual vs cumulative target line across the month, with projection dashed.
4. **History** — last 12 months achievement % per branch (heatmap: branches × months).

## Projection
`projected = actual_to_date + Σ remaining-day targets × pace_factor`,
`pace_factor = actual_to_date ÷ target_to_date` (so projection keeps the weekday shape, unlike linear extrapolation).

## Data
`targets`, `agg_branch_daily`, distribution cache. Service: `TargetAchievement::for(MetricsQuery): Collection<BranchTargetStatus>` — reused by reports 01, 07 and alerts 18.

## Edge cases
- No target set for a branch/month → row shows "لم يُحدد مستهدف" with a link to set it (owners only).
- Target changed mid-month → recompute distribution; history keeps the latest value (audit log keeps old ones).
- Branch opened mid-month → target applies from `opened_on`; days before excluded from distribution.
- `food_cost_pct` target: achievement shown as "within / above ceiling", not a %.

## Acceptance criteria
- Fixture: target 300,000 for A in month M, weekday weights from fixture history → target to date on day 14 equals expected value; projection formula verified.
- Required run-rate = (target − actual) ÷ remaining days (weighted) matches expected.
- Branch with no target excluded from group achievement, and group target note says so.
