# Menu Pricing & Discontinue Suggestions Workflow

## What
Dedicated repricing and item-discontinuation suggestion engine and approval tracking (`ai_menu_suggestions`) with cost-drift detection, cross-branch price variance checks (`> 10%` on same `product_ref`), 5 EGP rounding / `+15%` price-increase cap guardrails, and 30-day post-application margin follow-up.

## Why
`BuildMenuIntelligence` (`ItemRanker`) already scores items for `reprice` and `fix_or_drop` strategies, and `ComboCandidate` tracks combo approvals (`ComboCandidatesPage` + `ComboDecisionController`). However, there is no persisted suggestion workflow for individual item repricing/discontinuation or 30-day impact tracking.

## Where
- **Migrations:** `ai_menu_suggestions`.
- **Domain:** `app/Domain/Menu/` service building actionable suggestions on top of `item_profiles`, `item_period_stats`, and `dim_products`.
- **UI:** Review/decision actions in `app/Filament/App/Pages/Management/` (sibling to `ComboCandidatesPage`).

## Done when
- Suggestions respect all guardrails (exclude `< 20` units sold in period, exclude items `< 30` days old, round suggested price to 5 EGP, cap price increase at 15%).
- Owners can accept/reject/mark-applied suggestions, and 30 days after application the platform reports actual qty and contribution margin before vs after.
- Feature tests verify guardrails and acceptance criteria against `Tests\Fixtures\MenuFixture`.
