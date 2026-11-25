# 13 — AI: Profit Contribution (تحليل مساهمة كل مطعم في أرباح المجموعة)

**Requirement:** §3 — analyze each restaurant's contribution to group profit.
**Tier:** AI · **Route:** `/analytics/ai/contribution`

## Questions it answers
1. What share of group sales and group profit does each branch / restaurant produce?
2. Which branches are under-performing their size (big share of sales, small share of profit)?
3. Which branches explain the change in group profit since last period?
4. Which branches are loss-making, and for how long?

## Computation (`ContributionAnalyzer`)
1. **Shares**: `profit_share = branch K14 ÷ group K14`, `sales_share = K06 ÷ group K06`.
   If group profit ≤ 0, shares are computed on **gross profit** instead and the UI says so.
2. **Efficiency index** = profit_share ÷ sales_share (1.0 = average; < 0.8 = under-performer; > 1.2 = over-performer).
3. **Profit change attribution**: Δgroup K14 = Σ Δbranch K14; each branch's Δ further split into
   - volume effect: Δsales × previous net margin
   - margin effect: Δnet margin × current sales
4. **Loss streak**: consecutive months with K14 < 0 per branch.
5. **Pareto**: cumulative profit share sorted desc — "3 branches make 70 % of profit".
6. Roll-up by restaurant (brand) as well as branch.

## Layout
Narrative card · treemap (size = sales share, colour = efficiency index) · profit share vs sales share bars · Δ profit waterfall by branch (volume vs margin effect stacked) · loss-making list with streak length.

## LLM narrative
Highlights: top contributors, under-performers by efficiency index with their main cost leak (from report 03 leakage ratios in payload), loss-making branches, one recommendation per under-performer.

## Schedule
Monthly (2nd of month, after month close) + on-demand.

## Acceptance criteria
- Shares sum to 100 %; attribution effects sum to Δ group net profit exactly.
- Negative group profit switches to gross-profit basis and sets `basis = gross` in payload.
