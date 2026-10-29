# 16 — AI: Menu Pricing & Discontinue Suggestions (اقتراح أصناف تحتاج تعديل السعر أو إيقافها)

**Requirement:** §3 — suggest items whose price should change or that should be discontinued.
**Tier:** AI · **Route:** `/analytics/ai/menu`

## Questions it answers
1. Which items are Stars, Plowhorses, Puzzles, Dogs (menu engineering) per branch / restaurant?
2. Which items lost margin because ingredient cost rose but price didn't?
3. Which items should be repriced, promoted, re-costed or removed — and what is the expected impact?

## Method (`MenuEngineeringAnalyzer`, period default last 60 days, per restaurant menu)
1. **Classification** (Kasavana-Smith), per branch over manufactured items with ≥ 14 days on menu:
   - popularity: item mix % ≥ 70 % × (1 ÷ number of items) → high
   - profitability: item contribution margin (price − unit_cost, avg) ≥ menu weighted avg CM → high
   - Star (high/high), Plowhorse (high pop / low CM), Puzzle (low pop / high CM), Dog (low/low)
2. **Cost-drift check**: item food cost % now (last 14 days) vs 90 days ago; flag if + 5 pp or above the org food-cost ceiling.
3. **Cross-branch price check** (same `product_ref`): price differs between branches of the same brand by > 10 % → flag for review.
4. **Rules → suggestion** (each with reason and estimated monthly impact):
   | Condition | Suggestion | Impact estimate |
   |---|---|---|
   | Plowhorse or cost-drift, food cost % > ceiling | Raise price to reach target food cost % (rounded to 5 EGP) | Δprice × last-30-day qty × (1 − assumed 10 % volume loss) |
   | Plowhorse, recipe-heavy | Re-cost recipe / portion | (current CM − target CM) × qty |
   | Puzzle | Promote / reposition, not reprice | none (qualitative) |
   | Dog for 2 consecutive analyses, < 1 % of sales | Discontinue or replace | removed sales × (1 − substitution 50 %) − saved waste |
   | Star with food cost well below band | Hold price, protect quality | — |
5. **Guardrails**: never suggest price increases > 15 % at once; exclude items with < 20 sold in period; exclude new items (< 30 days); every suggestion marked "اقتراح" requiring human decision.

## Storage & workflow
`ai_menu_suggestions`: id, organization_id, branch_id/restaurant_id, product_uuid, class, suggestion, current_price, suggested_price, reason json, impact_estimate, status (new/accepted/rejected/applied), decided_by, decided_at.
Accepted price changes are **not** pushed to the POS in v1 (POS master/slave product sync already exists on larament for chains; v2 may use a heartbeat command).
Follow-up: 30 days after "applied", compare item qty and CM before/after → shown on the suggestion.

## Layout
Menu-engineering quadrant scatter (x = popularity, y = CM, colour = class) per branch · suggestions table (filter by type, sort by impact) · item card with price/cost history and reasoning · LLM summary of top 5 suggestions in Arabic.

## Acceptance criteria
- Fixture menu of 8 items yields the expected class for each item.
- Suggested price makes the item's food cost % ≤ ceiling and is rounded to 5 EGP; increase capped at 15 %.
- Items with < 20 sales produce no suggestion.
