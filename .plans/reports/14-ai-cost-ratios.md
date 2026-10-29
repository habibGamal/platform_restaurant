# 14 — AI: Labor & Material Cost Ratios (تحليل تكلفة العمالة والخامات كنسبة من المبيعات)

**Requirement:** §3 — analyze labor and raw-material cost as a percentage of sales.
**Tier:** AI · **Route:** `/analytics/ai/cost-ratios`

## Prerequisite (POS + platform)
Labor cost only exists if salaries are recorded as POS expenses whose `expense_type.category = labor`
(new POS field, see larament connector plan) or categorized on the platform. The page shows a setup
banner when a branch has no labor-categorized expenses in the last 60 days.

## Questions it answers
1. What are food cost %, labor %, and prime cost % for each branch, and how do they trend?
2. Which branches are outside healthy bands?
3. What is driving changes: purchase price inflation, waste, menu mix, or staffing vs sales volume?

## Computation (`CostRatioAnalyzer`)
- K16 food cost %, K18 labor % (allocated, conventions §4), K19 prime cost %, monthly and weekly.
- **Bands** (org-configurable defaults): food cost 28–35 %, labor 18–28 %, prime cost ≤ 62 %.
- **Food cost % change decomposition** (period vs comparison):
  - price effect: Σ items qty₁ × (unit_cost₁ − unit_cost₀) ÷ sales₁ (ingredient inflation passed through recipe cost)
  - mix effect: change in share of high-cost items
  - selling-price effect: Σ qty₁ × (price₁ − price₀) impact on the denominator
- **Labor productivity**: sales per labor pound (K06 ÷ labor allocated); sales per shift-hour from `agg_shift`.
- **Fixed-cost absorption**: labor % vs sales scatter per branch-month — shows if labor % is high because sales are low (fixed cost) rather than overstaffing.

## Layout
Narrative card · ratio matrix (branches × K16/K18/K19, coloured by band) · monthly trend lines with bands shaded · food cost change decomposition bars · labor % vs sales scatter.

## LLM narrative
Per branch outside a band: what moved and the biggest lever (e.g. "تكلفة الدجاج ارتفعت 12 % ولم يتغير سعر البيع").

## Acceptance criteria
- Allocated labor basis follows conventions §4 order (actual closed month → avg_month_rate → trailing avg).
- Decomposition effects sum to Δ food cost % (within 0.1 pp rounding).
- Branch without labor data shows the setup banner and no labor KPIs (not 0 %).
