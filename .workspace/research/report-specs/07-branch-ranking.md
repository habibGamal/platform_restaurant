# 07 — Branch Ranking (ترتيب المطاعم: الأفضل والأسوأ أداءً)

**Requirement:** §2 — rank restaurants by indicators of best and worst performance.
**Tier:** core · **Route:** `/analytics/ranking` · **Conventions:** `00-conventions.md`

## Users & access
`org_owner`, `org_analyst`: full league table. `branch_manager`: sees own rank per KPI ("ترتيبك 3 من 12") and the group median, not other branch names (configurable by org owner: `show_names_to_managers`).

## Questions it answers
1. Who is best and worst on each KPI this period?
2. Who is best overall, using a fair score that doesn't simply reward the biggest branch?
3. Who moved up or down versus the last period?

## Filters
Period (default **Last month**) · branch picker · scoring profile · like-for-like toggle (default on).

## Scoring

Each KPI is converted to a **percentile score 0–100** among the compared branches
(direction-aware: for food cost %, waste %, cancel rate, cash deficit — lower is better).
Overall score = weighted average of KPI scores.

Default profile (editable per org in the `app` panel (Management group), weights sum to 100):

| KPI | Weight | Direction |
|---|---|---|
| Net margin K15 | 25 | higher |
| Sales growth K24 (vs comparison period) | 20 | higher |
| Target achievement K23 (sales) | 20 | higher |
| Food cost % K16 | 15 | lower |
| Waste % K17 | 10 | lower |
| Avg ticket growth | 5 | higher |
| Cancel rate K21 | 5 | lower |

Ratios and growth are used instead of absolute sales so small branches can rank first.
If a KPI is unavailable for a branch (no target set), its weight is redistributed proportionally across that branch's other KPIs, and the row shows an info icon.

## Layout
1. **Podium** — top 3 and bottom 3 by overall score with the 2 KPIs that most helped / hurt each.
2. **League table** — rank, Δ rank vs previous period (▲▼), branch, overall score, then each KPI value + its score as a coloured cell.
3. **Per-KPI leaders** — small cards: best & worst branch for each KPI, with value.
4. **Rank history** — line chart of each branch's rank over the last 6 periods.

## Data
`agg_branch_daily` (all KPIs), `targets` (via report 08 service `TargetAchievement`). Scores computed in
`App\Domain\Metrics\Ranking\RankingService::rank(MetricsQuery, ScoringProfile): RankingResult`.

## Edge cases
- Fewer than 3 branches → percentile = min-max scaling; with 1 branch the page shows KPIs only, no ranking.
- Branch with incomplete data in the period (conventions §5) → excluded from ranking and listed under "مستبعد لنقص البيانات".
- Ties → same rank, next rank skipped (1, 2, 2, 4).

## Acceptance criteria
- Fixture with 2 branches + 2 synthetic ones: overall scores and ranks match hand-computed values.
- Changing a weight re-ranks deterministically; weights not summing to 100 are rejected in the profile form.
- Branch manager response contains no other branch names unless `show_names_to_managers` is on.
