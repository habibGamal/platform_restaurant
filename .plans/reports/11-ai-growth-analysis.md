# 11 — AI: Sales Growth Analysis (تحليل نمو المبيعات ومقارنتها بالفترات السابقة)

**Requirement:** §3 — analyze sales growth and compare with previous periods.
**Tier:** AI (pro plan, `ai_enabled`) · **Route:** `/analytics/ai/growth` · **Conventions:** `00-conventions.md`

## What is "AI" here
Numbers are deterministic (PHP/SQL). The LLM writes the explanation from the computed JSON (architecture §8).

## Questions it answers
1. How fast is the group / each branch growing week-over-week, month-over-month, year-over-year?
2. Is growth coming from more orders, bigger tickets, a channel, a category or specific branches?
3. Is the trend accelerating or slowing, after removing seasonality (Ramadan, weekdays)?

## Computation (`GrowthAnalyzer::analyze(MetricsQuery): GrowthResult`)
1. **Growth table** — K06, K01, K07, K14 growth for WoW, MoM (month-to-date vs same days last month), YoY (weekday-aligned 364 days), per branch and group; like-for-like and all-branches variants.
2. **Decomposition** (per branch and group): Δsales = orders effect + ticket effect (report 02 formulas) + returns effect.
3. **Contribution to group growth**: branch Δsales ÷ group Δsales (sums to 100 %); same by channel (`sales_by_type`) and by category (`agg_product_daily`).
4. **Trend**: 28-day rolling sum of K06 per branch; slope of the last 8 weeks (linear regression on weekly sums) → growth rate per week; acceleration = slope change vs the prior 8 weeks.
5. **Seasonality-adjusted**: compare each week with the same week last year when ≥ 1 year of data exists; otherwise with the 8-week weekday-adjusted baseline; weeks overlapping `calendar_events` flagged.

## Output payload (`ai_insights.kind = growth`)
```json
{ "period": {"from": "…", "to": "…"}, "group": {"wow": 0.042, "mom": -0.013, "yoy": 0.18,
  "decomposition": {"orders_effect": 12400.00, "ticket_effect": -3100.00, "returns_effect": -450.00}},
  "branches": [ {"branch_id": 3, "wow": …, "contribution": 0.61, "trend_slope_weekly": 0.021, "accelerating": true} ],
  "channels": [ … ], "categories": [ … ], "flags": ["ramadan_overlap"] }
```

## LLM narrative (Arabic, ≤ 180 words)
Prompt contains only the payload + glossary; asks for: headline (one sentence), 3 drivers, 1 risk, 1 suggested action. Post-check: every number in the text must exist in the payload (rounded); otherwise regenerate once, then fall back to a template text.

## Layout
Headline narrative card · growth table (periods × KPIs) · decomposition waterfall · contribution bars (branches, channels, categories) · 28-day rolling trend chart with slope annotations.

## Schedule
Weekly (Saturday 08:00 branch tz) + monthly (1st 08:00) generation; on-demand "تحليل الآن" button (rate limited 5/day/org).

## Acceptance criteria
- Decomposition effects sum exactly to Δ net sales.
- Contributions sum to 100 % (± 0.1 rounding).
- Narrative post-check rejects a text containing a number not present in the payload (unit test with a stubbed LLM).
