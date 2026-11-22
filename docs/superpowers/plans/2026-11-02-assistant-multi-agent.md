# Assistant Multi-Agent Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single generic assistant agent with a coordinator plus marketing, finance, operations and data-checker specialists. They rely on learned item roles and on analysis done in code, and the user watches a live work log while they run.

**Architecture:** A new `App\Domain\Menu` layer learns each item's role (main/side/drink/…) and computes menu engineering, basket pairs, combos, trends and timing in SQL/PHP. Specialists are `laravel/ai` agents exposed to the coordinator (`RestaurantAnalyst`) through `AgentTool`, and each one loads playbooks as skills. Runs move into a queued job that writes progress events per `runId`, and the chat page polls them to render a work log.

**Tech Stack:** Laravel 12, `laravel/ai` v1.3 (`AgentTool`, `HasSkills`, `CanActAsTool`, `HasStructuredOutput`, stream events with `invocationId`), Filament 4 + Livewire, MySQL/SQLite (tests), PHPUnit via `php artisan test`, Pint, PHPStan.

**Spec:** `docs/superpowers/specs/2026-11-02-assistant-multi-agent-design.md`

## Global Constraints

- No product or category names (Arabic or English) in prompts, skills or code. Test fixtures use made-up names.
- The model never computes numbers. Every figure in an answer comes from a tool result, and the existing `NumericGroundingChecker` stays in force.
- Branch scope always goes through `MetricsQuery::for($user, $filters)` / `->scoped(...)`. No raw query skips it.
- Items are keyed org-wide as `COALESCE(product_ref, product_uuid)` (same as `ProductPerformanceReport`).
- Marketing focus lists and combos never contain an item whose role is `drink`, `addon`, `adjustment`, `raw`, or which has no trusted role.
- Every combo has exactly one `main`, and its margin is ≥ the floor. The floor is the org's weighted average contribution-margin % of `main` items over the analysed period.
- Per-question token budget across all agents of one `runId` = `2 × config('ai_billing.per_run_input_token_cap')` (config key `ai_billing.per_question_input_token_cap`, default `240000`).
- A role is trusted when `source = owner`, or when `source ∈ {signal, auto}` and `confidence ≥ 0.70` (config `ai.roles.trusted_confidence`, default `0.70`).
- Answers are in Arabic with Western digits and currency `ج.م`. WhatsApp: no tables, ~900 characters, no work log.
- Tool payloads stay inside `FormatsToolResult` limits (6 KB target, 16 KB hard cap).
- Role classification is recorded in `ai_usage_ledger` with `user_id = null`, `channel = 'system'`. It is skipped for orgs without AI access or with an exhausted budget.
- WhatsApp delivery is out of scope. Only the `Channel::WhatsApp` flag is honoured.
- After each task: `vendor/bin/pint --dirty` and `vendor/bin/phpstan analyse --memory-limit=1G <changed paths>` are clean.

## Review Focus

1. **Org with very little history** (new org, under 14 days or under 200 orders): analysis tools return `insufficient_data` with an Arabic reason, not invented groups, pairs or forecasts. Tests are in Tasks 5, 6 and 8.
2. **Branch-restricted manager** asks a marketing question: menu engineering and basket pairs use only that manager's branches, and no other branch's names or items leak. Tests are in Tasks 5 and 6.
3. **A specialist fails mid-run** (provider error inside a sub-agent): the coordinator still answers with what it has, the work log marks that specialist as failed, and the run ends. Test is in Task 11.
4. **User presses stop during a delegated run:** every agent of the run stops after its current step, the log shows "cancelled", and no `done` event follows. Test is in Task 12.
5. **Page reload mid-run:** the work log and the partial answer are restored from the run's stored events, and polling resumes. Test is in Task 13.

---

## File Structure

```
app/Domain/Menu/
  ItemRole.php                 enum + role helpers
  ItemKey.php                  SQL key expression + normaliser for category names
  ItemRoleMap.php              read model for one org (trusted roles, unclassified keys)
  ItemRoles.php                service: mapFor(), ensureClassified(), setOwnerRole()
  ItemSignals.php              readonly DTO of per-item behaviour stats
  RoleSignals.php              computes ItemSignals + hard roles
  RoleClassifier.php           orchestrates signals → AI categories → AI products → upsert
  MenuEngineeringAnalysis.php  four-group matrix
  BasketAnalysis.php           pair support/confidence/lift
  ComboBuilder.php             combo proposals with pricing
  ItemTrends.php               rising/falling items within role
  ItemTiming.php               weekday×hour profile per item
app/Domain/Forecast/SalesForecast.php
app/Ai/Agents/ItemRoleClassifier.php
app/Ai/Agents/Concerns/UsesOrganizationAiSettings.php
app/Ai/Agents/{MarketingSpecialist,FinanceSpecialist,OperationsSpecialist,DataChecker}.php
app/Ai/Agents/RestaurantAnalyst.php            (becomes the coordinator)
app/Ai/Tools/Contracts/ReportsProgress.php
app/Ai/Tools/Menu/{MenuEngineering,BoughtTogether,ComboBuilderTool,ItemTrendsTool,ItemTimingTool}.php
app/Ai/Tools/Finance/{SalesForecastTool,ProfitabilityTool,FoodCostWasteTool,ExpensesPurchasesTool,SalesComparisonTool,TargetsTool}.php
app/Ai/Tools/Operations/{BranchRankingTool,ShiftsWeekdaysTool,OverviewTool,PeakHoursTool,DataHealthTool}.php
app/Ai/Middleware/EnforceBudget.php            (per-question budget)
app/Domain/Assistant/RunLog.php                (event store per runId)
app/Domain/Assistant/AssistantEvent.php        (new event types)
app/Domain/Assistant/AssistantService.php      (invocation mapping, start())
app/Jobs/ClassifyItemRoles.php
app/Jobs/RunAssistantQuestion.php
app/Models/{ItemRoleAssignment,CategoryRoleAssignment,AiRunEvent}.php
app/Filament/App/Pages/Assistant.php           (polling + work log)
app/Filament/App/Pages/Management/ItemRolesPage.php
resources/views/filament/app/assistant/work-log.blade.php
resources/ai/skills/{marketing,finance,operations}/<skill>/SKILL.md
database/migrations/2026_11_03_0000{01..03}_*.php
tests/Fixtures/MenuFixture.php
tests/Evals/questions.v1.php
```

---

### Task 1: Item roles storage and read model

**Files:**
- Create: `app/Domain/Menu/ItemRole.php`, `app/Domain/Menu/ItemKey.php`, `app/Domain/Menu/ItemRoleMap.php`, `app/Domain/Menu/ItemRoles.php`, `app/Models/ItemRoleAssignment.php`, `app/Models/CategoryRoleAssignment.php`, `database/migrations/2026_11_03_000001_create_item_role_tables.php`
- Modify: `config/ai.php` (add `'roles' => ['trusted_confidence' => env('AI_ROLES_TRUSTED_CONFIDENCE', 0.70)]`)
- Test: `tests/Feature/Menu/ItemRolesTest.php`

**Interfaces:**
- Produces:
  - `enum ItemRole: string { Main='main'; Side='side'; Drink='drink'; Dessert='dessert'; Addon='addon'; Combo='combo'; Adjustment='adjustment'; Raw='raw'; }` with `isMarketable(): bool` (Main, Side, Dessert, Combo) and `isComboCompanion(): bool` (Side, Drink, Dessert).
  - `ItemKey::SQL = 'COALESCE(product_ref, product_uuid)'`; `ItemKey::normalizeCategory(string $name): string` (trim, collapse whitespace, lowercase).
  - Table `item_roles`: `id, organization_id (fk cascade), item_key string(64), role string(16), source string(8) [owner|signal|auto], confidence decimal(3,2), classified_at timestamp, timestamps, unique(organization_id,item_key)`.
  - Table `category_roles`: `id, organization_id, category_key string(191), role string(16) nullable (null = mixed), confidence decimal(3,2), source string(8), timestamps, unique(organization_id,category_key)`.
  - `ItemRoles::mapFor(int $organizationId): ItemRoleMap`
  - `ItemRoles::setOwnerRole(int $organizationId, string $itemKey, ItemRole $role): void`
  - `ItemRoleMap::roleOf(string $itemKey): ?ItemRole` (null unless trusted), `ItemRoleMap::isTrusted(string $itemKey): bool`, `ItemRoleMap::untrustedAmong(array $itemKeys): list<string>`

- [ ] **Step 1: Write failing tests**

```php
public function test_owner_role_is_trusted_and_never_downgraded(): void
{
    $org = Organization::factory()->create();
    ItemRoleAssignment::create(['organization_id'=>$org->id,'item_key'=>'K1','role'=>'drink','source'=>'auto','confidence'=>0.95,'classified_at'=>now()]);
    app(ItemRoles::class)->setOwnerRole($org->id, 'K1', ItemRole::Main);
    $map = app(ItemRoles::class)->mapFor($org->id);
    $this->assertSame(ItemRole::Main, $map->roleOf('K1'));
}

public function test_low_confidence_auto_role_is_not_trusted(): void
{
    // auto, confidence 0.55 → roleOf null, untrustedAmong(['K2']) === ['K2']
}

public function test_unknown_key_is_untrusted(): void
{
    // mapFor on empty org → roleOf('X') null, untrustedAmong(['X']) === ['X']
}

public function test_roles_are_isolated_per_organization(): void
{
    // same item_key in two orgs with different roles → each map sees its own
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=ItemRolesTest`. Expected: FAIL (classes missing).
- [ ] **Step 3:** Implement the migration, models, enum, `ItemKey`, `ItemRoleMap` (in-memory array built from one query per org), and `ItemRoles`. `setOwnerRole` upserts with `source=owner, confidence=1.00`.
- [ ] **Step 4:** Run `php artisan test --compact --filter=ItemRolesTest`. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat(menu): add item role storage and read model"`

---

### Task 2: Behaviour signals and hard roles, plus the made-up menu fixture

**Files:**
- Create: `app/Domain/Menu/ItemSignals.php`, `app/Domain/Menu/RoleSignals.php`, `tests/Fixtures/MenuFixture.php`
- Test: `tests/Feature/Menu/RoleSignalsTest.php`

**Interfaces:**
- Consumes: `ItemKey::SQL`, `ItemRole`.
- Produces:
  - `final readonly class ItemSignals { string $itemKey; string $name; ?string $categoryName; ?string $productType; float $avgPrice; float $avgUnitCost; int $ordersWith; int $ordersAsOnlyLine; float $attachRate; float $avgQtyPerOrder; float $priceVsMedian; }` (`attachRate = 1 - ordersAsOnlyLine/ordersWith`, `priceVsMedian = avgPrice / median avgPrice of manufactured items`).
  - `RoleSignals::compute(int $organizationId, CarbonImmutable $from, CarbonImmutable $to): array<string, ItemSignals>` (keyed by item key, completed orders only, from `wh_orders` + `wh_order_items` + `dim_products` + `dim_categories`).
  - `RoleSignals::hardRole(ItemSignals $s): ?ItemRole`: `Raw` when `productType ∈ {raw_material, consumable}`; `Adjustment` when `avgPrice ≤ max(1.0, 0.02 × median price)` AND `avgUnitCost == 0` AND `attachRate ≥ 0.9`; otherwise null.
  - `MenuFixture::create(): self` exposes `$organization, $branchA, $branchB, $owner, $managerA`. It seeds 60 days (2026-08-18 → 2026-10-16) of completed orders over two branches. Its menu uses invented names: mains "Zorba Plate" (high margin, low volume), "Kelto Wrap" (high margin, high volume), "Brimo Box" (low margin, high volume), "Nadd Bowl" (low margin, low volume), "Feta Tower" (new: only 5 days on sale), "Quill Stew" (cost 0); sides "Lumo Rice", "Pexa Salad"; drinks "Vira Soda", "Tiny Spring" (very high volume, always with another item); add-on "Glaze Dip"; adjustment "Diff Line" (price 1, cost 0, always attached); raw "Bulk Flour". Orders pair Zorba Plate with Lumo Rice in about 60% of its orders. Each product's `product_ref` is its name upper-kebab-cased (`ZORBA-PLATE`, `DIFF-LINE`, `TINY-SPRING`, …), so item keys in tests are those refs. All rows also go into `agg_product_daily` and `agg_branch_daily` so reports see them. Follow the seeding style of `tests/Fixtures/TwoBranchesTwoWeeks.php`.

- [ ] **Step 1: Write failing tests**

```php
public function test_adjustment_line_is_detected_from_behaviour_not_name(): void
{
    $f = MenuFixture::create();
    $signals = app(RoleSignals::class)->compute($f->organization->id, CarbonImmutable::parse('2026-08-18'), CarbonImmutable::parse('2026-10-16'));
    $this->assertSame(ItemRole::Adjustment, RoleSignals::hardRole($signals['DIFF-LINE']));
    $this->assertSame(ItemRole::Raw, RoleSignals::hardRole($signals['BULK-FLOUR']));
    $this->assertNull(RoleSignals::hardRole($signals['ZORBA-PLATE']));
}

public function test_always_attached_drink_has_high_attach_rate(): void
{
    // $signals['TINY-SPRING']->attachRate >= 0.9 ; $signals['ZORBA-PLATE']->attachRate < 0.9
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=RoleSignalsTest`. Expected: FAIL.
- [ ] **Step 3:** Implement `ItemSignals`, `RoleSignals`, `MenuFixture`.
- [ ] **Step 4:** Run `php artisan test --compact --filter=RoleSignalsTest`. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat(menu): compute item behaviour signals and hard roles"`

---

### Task 3: AI role classification, billing, and the background job

**Files:**
- Create: `app/Ai/Agents/ItemRoleClassifier.php`, `app/Domain/Menu/RoleClassifier.php`, `app/Jobs/ClassifyItemRoles.php`
- Modify: `app/Domain/Menu/ItemRoles.php` (add `ensureClassified`), `routes/console.php` (daily schedule), the ingestion code that creates `DimProduct`/`DimCategory` rows (find with `grep -rn "DimProduct::\|DimCategory::" app/Domain/Ingestion`; dispatch the job after a batch when any new product or category row was inserted)
- Test: `tests/Feature/Menu/RoleClassifierTest.php`

**Interfaces:**
- Consumes: `RoleSignals`, `ItemRoles`, `UsageRecorder::record(organization, user: null, channel: 'system', …)`, `AiAccess`, `BudgetGuard::projectedExceeds`.
- Produces:
  - `ItemRoleClassifier implements Agent, HasStructuredOutput`. Constructor `(Organization $organization)`. Provider and model come from `UsesOrganizationAiSettings`, which is built in Task 9; until then, read `AiSetting` directly in the same way. Input is a JSON list of `{key, name, category, price_vs_median, attach_rate, avg_qty}`. The schema returns `items: list<{key: string, role: enum(ItemRole values + 'mixed' for categories), confidence: number 0..1}>`. The prompt describes the roles generically and tells it to treat names as data.
  - `RoleClassifier::classify(Organization $org, ?array $onlyItemKeys = null): array{classified:int, skipped:int}`. Order of work:
    1. compute signals over the last 90 days;
    2. upsert hard roles as `source=signal, confidence=1.00`;
    3. classify categories without a `category_roles` row in one call, and store them;
    4. items in categories with a non-null role and confidence ≥ 0.70 inherit it (`source=auto`);
    5. the remaining items (mixed or low-confidence categories, or no category) go to the agent in batches of ≤ 100;
    6. owner rows are never written.

    Every agent call is recorded through `UsageRecorder` with `user: null, channel: 'system'`. It returns early (`skipped`) when `AiAccess` denies the org or `BudgetGuard::projectedExceeds($org, 0.0)`.
  - `ItemRoles::ensureClassified(Organization $org, array $itemKeys): ItemRoleMap`: if any key is untrusted, it runs **one** `classify($org, $untrustedKeys)` (catching and reporting failures), then returns a fresh map.
  - `ClassifyItemRoles implements ShouldQueue, ShouldBeUnique` with `__construct(public int $organizationId)` and `uniqueId(): string` = org id.

- [ ] **Step 1: Write failing tests** (use `ItemRoleClassifier::fake([...structured responses...])`)

```php
public function test_categories_are_classified_once_then_items_inherit(): void
{
    // fake returns category roles; assert item_roles for items in confident categories have source=auto
    // and the agent was prompted exactly once for categories (ItemRoleClassifier::assertPrompted count)
}

public function test_owner_rows_are_never_overwritten(): void {}
public function test_usage_is_recorded_as_system_entry_without_user(): void
{
    // ai_usage_ledger row: user_id null, channel 'system'
}
public function test_skips_org_without_ai_access(): void {}
public function test_ensure_classified_makes_at_most_one_call_and_survives_failure(): void
{
    // fake throws → ensureClassified returns map with keys still untrusted, no exception
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=RoleClassifierTest`. Expected: FAIL.
- [ ] **Step 3:** Implement the agent, classifier, job, schedule (`Schedule::job(...)->dailyAt('04:00')` for each org with AI access) and the ingestion dispatch.
- [ ] **Step 4:** Run `php artisan test --compact --filter="RoleClassifierTest|ItemRolesTest|RoleSignalsTest"`. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat(menu): classify item roles with AI and record system usage"`

---

### Task 4: Item roles review page

**Files:**
- Create: `app/Filament/App/Pages/Management/ItemRolesPage.php` (+ its Blade view, following the `AiSettingsPage` conventions)
- Modify: `app/Filament/App/Pages/Management/AiSettingsPage.php` (header action linking to the new page)
- Test: `tests/Feature/Assistant/Settings/ItemRolesPageTest.php`

**Interfaces:**
- Consumes: `ItemRoles::setOwnerRole`, `ClassifyItemRoles`.
- Produces: a Filament page visible to users with the same permission as `AiSettingsPage` (`ai.manage`). It is a table of items (name, category, role label in Arabic, source, confidence), sorted untrusted first, then by confidence ascending. Each row has a role select that calls `setOwnerRole`. A header action "إعادة التصنيف" dispatches `ClassifyItemRoles`.

- [ ] **Step 1: Write failing tests:** a user with `ai.manage` sees untrusted rows first; changing a role stores `source=owner`; a user without `ai.manage` gets 403; the reclassify action pushes `ClassifyItemRoles` (`Queue::fake()`).
- [ ] **Step 2:** Run `php artisan test --compact --filter=ItemRolesPageTest`. Expected: FAIL.
- [ ] **Step 3:** Implement the page.
- [ ] **Step 4:** Run `php artisan test --compact --filter=ItemRolesPageTest`. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat(menu): add item roles review page"`

---

### Task 5: Menu engineering analysis, tool progress contract, and `menu_engineering` tool

**Files:**
- Create: `app/Domain/Menu/MenuEngineeringAnalysis.php`, `app/Ai/Tools/Contracts/ReportsProgress.php`, `app/Ai/Tools/Menu/MenuEngineering.php`
- Modify: `app/Ai/Tools/Concerns/FormatsToolResult.php` (`formatEnvelope(..., ?string $summary = null)` puts `'summary' => $summary` at the top level when non-null)
- Test: `tests/Feature/Menu/MenuEngineeringTest.php`

**Interfaces:**
- Consumes: `MetricsQuery::for`, `->scoped('agg_product_daily', $period)`, `ItemRoles::ensureClassified`, `ItemKey::SQL`.
- Produces:
  - `interface ReportsProgress { public function progressLabel(): string; }`: a colloquial Arabic "doing" label, e.g. `'بحسب هوامش وأداء الأصناف…'`.
  - `MenuEngineeringAnalysis::run(MetricsQuery $query, Organization $org): array{status: 'ok'|'insufficient_data', reason?: string, groups?: array<string, array{hidden_gems: list<array>, stars: list<array>, workhorses: list<array>, weak: list<array>}>, new_items: list<array>, unknown_cost: list<array>, unclassified_count: int, floors: array<string, float>}`. Groups are keyed by role value. Each item row has `{key, name, role, qty, net_sales, unit_margin, margin_pct, mix_share, popularity_ratio, margin_vs_avg}`.
  - Tool `menu_engineering` (`implements Tool, ReportsProgress`). It takes the report filter schema plus `role?: enum(main, side, dessert, combo)` and `per_branch?: bool`. The envelope `summary` reads like `"لقيت 4 أصناف مربحة ومش بتتباع كفاية من 18 صنف رئيسي"`. At most 8 items per group per role (sorted by `margin_vs_avg` desc for hidden gems).
- Algorithm (computed per role group of trusted, marketable items over the period):

```
eligible  = items with role.isMarketable(), qty > 0, days_on_sale ≥ 14, unit cost > 0
new_items = days_on_sale < 14 ; unknown_cost = cogs == 0 (both excluded from groups)
N         = count(eligible in group)
mix_share = qty / Σqty(group) ;  popular  ⇔ mix_share ≥ 0.70 × (1/N)
unit_cm   = (net_sales − cogs) / qty ; avg_cm = Σ(net_sales − cogs) / Σqty(group)
profitable ⇔ unit_cm ≥ avg_cm
hidden_gems = profitable ∧ ¬popular ; stars = profitable ∧ popular
workhorses  = ¬profitable ∧ popular ; weak = ¬profitable ∧ ¬popular
insufficient_data when the period has < 14 distinct business days or < 200 completed orders in scope
floors['main'] = Σ(net_sales − cogs)/Σnet_sales over eligible mains   (used by ComboBuilder)
```

- [ ] **Step 1: Write failing tests** (on `MenuFixture`, with roles seeded through `ItemRoles::setOwnerRole` so no AI is involved)

```php
public function test_hidden_gem_is_the_high_margin_low_volume_main(): void
{
    $groups = $result['groups']['main'];
    $this->assertSame(['ZORBA-PLATE'], array_column($groups['hidden_gems'], 'key'));
    $this->assertContains('KELTO-WRAP', array_column($groups['stars'], 'key'));
    $this->assertContains('BRIMO-BOX', array_column($groups['workhorses'], 'key'));
}
public function test_drinks_addons_adjustments_and_raw_never_appear_in_any_group(): void
{
    // flatten all groups across roles; assert none of TINY-SPRING, VIRA-SODA, GLAZE-DIP, DIFF-LINE, BULK-FLOUR
}
public function test_unclassified_items_are_excluded_and_counted(): void
{
    // leave one main without a role, fake classifier to throw → excluded, unclassified_count === 1
}
public function test_new_and_unknown_cost_items_are_listed_separately(): void
{
    // FETA-TOWER in new_items, QUILL-STEW in unknown_cost
}
public function test_manager_sees_only_their_branch(): void
{
    // managerA: result built only from branchA rows (assert qty equals branchA-only sum for KELTO-WRAP)
}
public function test_insufficient_data_for_short_history(): void
{
    // custom period of 5 days → status insufficient_data with Arabic reason
}
public function test_tool_envelope_has_summary_and_progress_label(): void {}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=MenuEngineeringTest`. Expected: FAIL.
- [ ] **Step 3:** Implement the analysis, contract, envelope change and tool. Use `handle()` with try/catch and caching like `GetKpis`.
- [ ] **Step 4:** Run `php artisan test --compact --filter="MenuEngineeringTest|GetKpisTest|QueryMetricsTest"`. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat(menu): add menu engineering analysis and tool"`

---

### Task 6: Basket analysis and `bought_together` tool

**Files:**
- Create: `app/Domain/Menu/BasketAnalysis.php`, `app/Ai/Tools/Menu/BoughtTogether.php`
- Test: `tests/Feature/Menu/BasketAnalysisTest.php`

**Interfaces:**
- Consumes: `MetricsQuery::scoped('wh_order_items', $period)` joined to completed `wh_orders` in scope, plus `ItemRoleMap`.
- Produces:
  - `BasketAnalysis::pairsFor(MetricsQuery $query, ItemRoleMap $roles, ?string $anchorKey = null, int $limit = 10): array{status, reason?, orders_considered: int, pairs: list<array{anchor_key, anchor_name, anchor_role, partner_key, partner_name, partner_role, together_orders, support, confidence, lift}>}`. Pairs only run from a `main` anchor to a partner whose role `isComboCompanion()`. Minimum support is `max(5 orders, 0.002 × orders_considered)`. Sorted by lift desc, then by together_orders. `insufficient_data` when there are fewer than 200 multi-line orders in scope.
  - Tool `bought_together` (`ReportsProgress`, label `'بشوف الأصناف اللي بتتطلب مع بعض…'`). Params are the filters plus `item_key?`.

- [ ] **Step 1: Write failing tests:** `ZORBA-PLATE → LUMO-RICE` is the top pair with lift > 1; there are no main→main pairs; `DIFF-LINE`/`BULK-FLOUR` never appear; a manager only sees pairs from their branch's orders; a short period returns insufficient data.
- [ ] **Step 2:** Run `php artisan test --compact --filter=BasketAnalysisTest`. Expected: FAIL.
- [ ] **Step 3:** Implement it. Compute pair counts in SQL with a self-join on `order_uuid`, restricted to anchor keys that are mains.
- [ ] **Step 4:** Run `php artisan test --compact --filter=BasketAnalysisTest`. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat(menu): add bought-together basket analysis"`

---

### Task 7: Combo builder, trends and timing tools

**Files:**
- Create: `app/Domain/Menu/ComboBuilder.php`, `app/Domain/Menu/ItemTrends.php`, `app/Domain/Menu/ItemTiming.php`, `app/Ai/Tools/Menu/ComboBuilderTool.php`, `app/Ai/Tools/Menu/ItemTrendsTool.php`, `app/Ai/Tools/Menu/ItemTimingTool.php`
- Test: `tests/Feature/Menu/ComboBuilderTest.php`, `tests/Feature/Menu/ItemTrendsTimingTest.php`

**Interfaces:**
- Consumes: `MenuEngineeringAnalysis::run` (for `floors['main']` and hidden gems), `BasketAnalysis::pairsFor`, `ItemRoles`.
- Produces:
  - `ComboBuilder::proposeFor(MetricsQuery $query, Organization $org, ?string $targetKey = null, int $max = 3): array{status, reason?, margin_floor_pct: float, combos: list<array{items: list<array{key,name,role,list_price}>, list_price, suggested_price, cost, margin_pct, discount_pct, why: string}>}`. If `targetKey` is null, it targets the top hidden gem of role main. Each combo is the target main, plus its highest-lift companion of role side or dessert, plus optionally its highest-lift drink. Pricing:

    ```
    list_price = Σ avg unit prices ; cost = Σ avg unit costs
    suggested_price = list_price × (1 − d), d = largest value in {0.15, 0.12, 0.10, 0.08, 0.05}
                      with (suggested_price − cost)/suggested_price ≥ margin_floor
    no d satisfies the floor → combo dropped
    suggested_price rounded down to the nearest 5 EGP, margin recomputed after rounding and re-checked
    ```

  - `ItemTrends::run(MetricsQuery $query, ItemRoleMap $roles): array{status, rising: list, falling: list}`. It compares the last 28 days vs. the 28 before, within marketable roles, among items with ≥ 20 units in the earlier window, ranked by % change in qty. Top 5 each way.
  - `ItemTiming::run(MetricsQuery $query, string $itemKey): array{status, weekday: list<{weekday, qty_share}>, hour: list<{hour, qty_share}>, slow_slots: list<{weekday, hour}>}`. It uses `wh_orders.weekday/hour_of_day`. `slow_slots` are the 3 lowest-share slots during the hours the restaurant is open.
  - Tools: `combo_builder` (params: filters, `item_key?`), `item_trends`, `item_timing` (`item_key` required). All implement `ReportsProgress` and return a `summary`.

- [ ] **Step 1: Write failing tests**

```php
public function test_combo_has_exactly_one_main_and_respects_margin_floor(): void
{
    foreach ($result['combos'] as $c) {
        $this->assertCount(1, array_filter($c['items'], fn ($i) => $i['role'] === 'main'));
        $this->assertGreaterThanOrEqual($result['margin_floor_pct'], $c['margin_pct']);
        $this->assertSame(0.0, fmod($c['suggested_price'], 5.0));
    }
}
public function test_default_target_is_top_hidden_gem_with_its_best_companion(): void
{
    // first combo items keys ⊇ ['ZORBA-PLATE','LUMO-RICE']
}
public function test_combo_dropped_when_no_discount_keeps_floor(): void {}
public function test_trends_and_timing_ignore_non_marketable_roles(): void {}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter="ComboBuilderTest|ItemTrendsTimingTest"`. Expected: FAIL.
- [ ] **Step 3:** Implement the services and tools.
- [ ] **Step 4:** Run the same filter. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat(menu): add combo builder, item trends and timing tools"`

---

### Task 8: Finance and operations tools, including the sales forecast

**Files:**
- Create: `app/Domain/Forecast/SalesForecast.php`, `app/Ai/Tools/Finance/*.php`, `app/Ai/Tools/Operations/*.php` (names in File Structure)
- Test: `tests/Feature/Ai/Tools/SalesForecastTest.php`, `tests/Feature/Ai/Tools/ReportToolsTest.php`

**Interfaces:**
- Consumes: the existing reports (`ProfitabilityReport`, `FoodCostWasteReport`, `ExpensesPurchasesReport`, `SalesComparisonReport`, `TargetsVsActualReport`, `BranchRankingReport`, `ShiftsWeekdaysReport`, `OverviewReport`, `DataHealthReport`), `MetricsQuery::hourlyTotals`, `Completeness`.
- Produces:
  - `SalesForecast::forecast(MetricsQuery $query, CarbonImmutable $from, CarbonImmutable $to): array{status, reason?, method: string, expected: float, low: float, high: float, by_week: list<{week_start, expected}>, complete_days_used: int}`. Algorithm:

    ```
    history  = last 56 business days with completeness = complete (from Completeness), net sales per day
    base[wd] = median of the last 4 complete days with weekday wd
    trend    = Σ(last 28 complete days) / Σ(previous 28 complete days), clamped to [0.85, 1.15]
    day_fc   = base[wd(day)] × trend ; expected = Σ day_fc over target days
    spread   = median absolute % deviation of (actual / base[wd]) over history
    low/high = expected × (1 ∓ max(spread, 0.05))
    insufficient_data when complete_days_used < 28
    ```

  - Tool names: `sales_forecast`, `profitability`, `food_cost_waste`, `expenses_purchases`, `sales_comparison`, `targets_vs_actual`, `branch_ranking`, `shifts_weekdays`, `overview_report`, `peak_hours`, `data_health`. These match the labels already in `Assistant::mapToolStatus`. Each one wraps the report's public methods and returns its key rows (top 10 at most) through `formatEnvelope` with a `summary`, and implements `ReportsProgress`.

- [ ] **Step 1: Write failing tests:**
  - forecast on `TwoBranchesTwoWeeks` (14 days) → `insufficient_data`;
  - on `MenuFixture` (60 days) → `low < expected < high`, `complete_days_used ≥ 28`, and `expected` equals the formula recomputed in the test from seeded daily totals;
  - each report tool returns valid JSON with `summary`, without `error`, with branches limited to managerA's when run as managerA.
- [ ] **Step 2:** Run `php artisan test --compact --filter="SalesForecastTest|ReportToolsTest"`. Expected: FAIL.
- [ ] **Step 3:** Implement them.
- [ ] **Step 4:** Run the same filter. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat(ai): add forecast and finance/operations report tools"`

---

### Task 9: Shared org AI settings and per-question budget

**Files:**
- Create: `app/Ai/Agents/Concerns/UsesOrganizationAiSettings.php`
- Modify: `app/Ai/Agents/RestaurantAnalyst.php` (move `provider()`, `model()`, `providerOptions()` into the trait), `app/Ai/Agents/ItemRoleClassifier.php` (use the trait), `app/Ai/Middleware/EnforceBudget.php`, `config/ai_billing.php` (`'per_question_input_token_cap' => env('AI_PER_QUESTION_INPUT_TOKEN_CAP', 240000)`)
- Test: `tests/Feature/Assistant/Agent/RunBudgetTest.php` (and the existing `MiddlewareAndExecutionTest` must stay green)

**Interfaces:**
- Produces:
  - Trait `UsesOrganizationAiSettings`. It requires an `int|null` org id via `abstract protected function aiOrganizationId(): ?int`, and provides `provider()`, `model()`, `providerOptions(Lab|string $provider)`. The behaviour is identical to today's `RestaurantAnalyst`, including BYO and failover.
  - `EnforceBudget::__construct(User|Organization $userOrOrg, ?BudgetGuard $budgetGuard = null, ?string $runId = null)`. When `$runId` is set, it adds `$step->usage->inputTokens` deltas into `Cache` key `ai:run_tokens:{runId}` (TTL 1 h) and stops when the running total ≥ `per_question_input_token_cap`. The per-agent cap check stays as it is.

- [ ] **Step 1: Write failing tests:**
  - two agents sharing a runId whose cumulative tokens cross 240000 → the second one's next step returns `StepResult::stop()`;
  - different runIds don't share totals;
  - the trait reproduces the BYO provider name `byo_openai_{orgId}` and the failover chain (port the assertions from the existing provider tests).
- [ ] **Step 2:** Run `php artisan test --compact --filter="RunBudgetTest|MiddlewareAndExecutionTest|ProviderOptionsTest"`. Expected: FAIL on the new tests.
- [ ] **Step 3:** Implement it.
- [ ] **Step 4:** Run the same filter. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "refactor(ai): share org AI settings and add per-question token budget"`

---

### Task 10: Specialist agents and their skills

**Files:**
- Create: `app/Ai/Agents/MarketingSpecialist.php`, `FinanceSpecialist.php`, `OperationsSpecialist.php`, `DataChecker.php`, and `resources/ai/skills/marketing/{marketing-focus,combo-design,menu-cleanup,promotion-review}/SKILL.md`, `resources/ai/skills/finance/{profit-review,cost-check,forecast,expense-anomalies}/SKILL.md`, `resources/ai/skills/operations/{branch-comparison,peak-hours-staffing,waste,targets-follow-up}/SKILL.md`
- Test: `tests/Feature/Assistant/Agent/SpecialistsTest.php`

**Interfaces:**
- Consumes: the tools from Tasks 5–8, `UsesOrganizationAiSettings`, and the middleware (`InjectRuntimeContext`, `EnforceBudget(user, runId: $runId)`, `StopOnCancel`, `RecordUsage`).
- Produces: each specialist `implements Agent, CanActAsTool, HasTools, HasSkills, HasMiddleware, HasProviderOptions`, with constructor `(User $user, Channel $channel, ?string $runId = null)`, `#[MaxSteps(8)]`, `#[Timeout(120)]`, `#[MaxTokens(3000)]`.

  | Class | `name()` | Tools | Skills dir |
  |---|---|---|---|
  | MarketingSpecialist | `marketing_specialist` | resolve_period, find_branches, menu_engineering, bought_together, combo_builder, item_trends, item_timing, get_kpis, query_metrics (promotion-review uses it for item discounts vs. qty) | marketing |
  | FinanceSpecialist | `finance_specialist` | resolve_period, find_branches, get_kpis, get_kpi_series, profitability, food_cost_waste, expenses_purchases, sales_comparison, targets_vs_actual, sales_forecast | finance |
  | OperationsSpecialist | `operations_specialist` | resolve_period, find_branches, get_kpis, branch_ranking, shifts_weekdays, overview_report, peak_hours, data_health, targets_vs_actual | operations |
  | DataChecker | `data_checker` | data_health | none |

  Each `instructions()` is short, static and generic: role, grounding rule, "use the matching skill", "return findings as compact bullets with numbers and the reason; no greeting, no final advice to the user". `description()` (shown to the coordinator) says when to delegate and that the task must contain period, branches and the exact question. `DataChecker`'s description: give it the findings plus completeness warnings; it returns one line, or a short warning when missing data changes the conclusion. Each SKILL.md has `name` and `description` frontmatter and lists the tool order and the output shape. For example, marketing-focus: menu_engineering(role=main) → per_branch if more than one branch → bought_together for the top 2 hidden gems → combo_builder → item_timing for the top gem → output "ركز على / متلمسش / كومبو مقترح / أفكار".

- [ ] **Step 1: Write failing tests:**
  - each specialist's `tools()` names equal the table row exactly;
  - `skills()` resolves to existing `Skill::fromDirectory` objects whose names match the directories;
  - no SKILL.md file and no `instructions()` text contains any product or category name from `MenuFixture` or `TwoBranchesTwoWeeks` (read the files and assert with `assertStringNotContainsString` for each name);
  - `MarketingSpecialist::fake([...])` with `new AgentTool(...)` streams events, and the final text is returned.
- [ ] **Step 2:** Run `php artisan test --compact --filter=SpecialistsTest`. Expected: FAIL.
- [ ] **Step 3:** Implement the agents and write the skills.
- [ ] **Step 4:** Run `php artisan test --compact --filter=SpecialistsTest`. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat(ai): add marketing, finance, operations and data-checker specialists"`

---

### Task 11: Coordinator, event mapping and answer style

**Files:**
- Modify: `app/Ai/Agents/RestaurantAnalyst.php` (it becomes the coordinator), `app/Domain/Assistant/AssistantEvent.php`, `app/Domain/Assistant/AssistantService.php`
- Test: `tests/Feature/Assistant/Agent/CoordinatorTest.php` (existing `AssistantServiceStreamTest`, `AssistantServiceGatesTest` stay green)

**Interfaces:**
- Consumes: the specialists (Task 10), `ReportsProgress`, the tool envelope `summary`.
- Produces:
  - `RestaurantAnalyst` keeps its class name (so stored conversations and existing fakes keep working) and gets `#[MaxSteps(10)]`. Its tools are the basic ones (`get_workspace_context`, `resolve_period`, `find_branches`, `get_kpis`, `get_kpi_series`, `query_metrics`) plus `new AgentTool(new MarketingSpecialist(...))`, Finance, Operations and DataChecker, all constructed with the same user, channel and runId. The new `instructions()` covers:
    1. answer simple facts directly without delegating;
    2. for analysis or advice, first write a one-line plan prefixed `خطة:`, then delegate self-contained tasks (period dates, branch ids, the question);
    3. call `data_checker` only when a specialist reported completeness warnings;
    4. answer style per spec §5 (headline, 2–5 bullets each with number + reason; marketing layout "ركز على / متلمسش / كومبو مقترح / أفكار"; one-line period/branches; one-line completeness unless data_checker says otherwise; "اقتراح:" labels);
    5. WhatsApp rules.

    It keeps the existing grounding, permissions and injection rules, and removes the reference to non-existent playbooks.
  - `AssistantEvent` new types and factories:
    - `plan(string $text)`
    - `agentStarted(string $agent, string $labelAr)`
    - `stepStarted(string $agent, string $labelAr)`
    - `stepFinished(string $agent, ?string $summaryAr)`
    - `agentFinished(string $agent, bool $failed)`

    Add a `public ?string $agent = null` property. The labels for agents are `المساعد`, `التسويق`, `المالية`, `التشغيل`, `مدقق البيانات`.
  - `AssistantService::stream` mapping rules:
    - track the coordinator's `invocationId` (the first `StreamStart`);
    - `TextDelta` from the coordinator becomes `text_delta`, except a leading `خطة:` line, which becomes a `plan` event; `TextDelta` from other invocations is dropped;
    - `ToolCall` for a specialist name becomes `agent_started`;
    - other `ToolCall`s become `step_started` with that tool's `progressLabel()`, attributed to the agent that owns the invocation;
    - `ToolResult` becomes `step_finished` with the JSON `summary` field, if any;
    - the specialist's `ToolResult` becomes `agent_finished` (failed when the result starts with `Agent failed:`);
    - sub-agent `StreamEnd`/`Error` events do not end the run.

    The existing `tool_call`/`tool_result` events keep being emitted for the source chips.

- [ ] **Step 1: Write failing tests** (use `RestaurantAnalyst::fake`/`MarketingSpecialist::fake` with scripted tool calls, as in `AssistantServiceStreamTest`)

```php
public function test_simple_question_makes_no_delegation(): void
{
    // fake coordinator answers with get_kpis only → no agent_started events
}
public function test_delegated_run_emits_plan_agent_and_step_events_in_order(): void
{
    // types sequence contains plan, agent_started(marketing), step_started, step_finished(summary not null), agent_finished, text_delta..., done
}
public function test_sub_agent_text_never_reaches_the_answer(): void {}
public function test_failed_specialist_is_marked_and_run_still_finishes(): void
{
    // MarketingSpecialist fake throws → agent_finished(failed=true), final event done, answer text non-empty
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter="CoordinatorTest|AssistantServiceStreamTest|AssistantServiceGatesTest"`. Expected: the new tests FAIL.
- [ ] **Step 3:** Implement it.
- [ ] **Step 4:** Run the same filter. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat(ai): turn the analyst into a coordinator with live progress events"`

---

### Task 12: Queued runs and the run event log

**Files:**
- Create: `app/Jobs/RunAssistantQuestion.php`, `app/Domain/Assistant/RunLog.php`, `app/Models/AiRunEvent.php`, `database/migrations/2026_11_03_000002_create_ai_run_events_table.php`
- Modify: `app/Domain/Assistant/AssistantService.php` (add `start()`), `app/Domain/Assistant/Contracts/Assistant.php`
- Test: `tests/Feature/Assistant/Agent/QueuedRunTest.php`

**Interfaces:**
- Consumes: `AssistantService::stream`, `AssistantEvent`.
- Produces:
  - Table `ai_run_events`: `id, run_id uuid index, user_id, conversation_id nullable, seq unsigned int, type string(24), payload json, created_at`, with `unique(run_id, seq)`. It also gets `message_id nullable` so the work log can be linked to the saved assistant message after `done`.
  - `RunLog::append(string $runId, int $userId, AssistantEvent $event): void`, `RunLog::since(string $runId, int $userId, int $afterSeq): list<array{seq:int,type:string,payload:array}>`, `RunLog::forMessage(string $messageId): list<...>`, `RunLog::isFinished(string $runId): bool`. Events with type `done`/`error` count as finished. Text deltas are coalesced: consecutive `text_delta` events are appended to the last row instead of adding a new row each time.
  - `Assistant::start(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): void` runs the gates synchronously (gate errors are appended to `RunLog` immediately) and dispatches `RunAssistantQuestion`.
  - `RunAssistantQuestion implements ShouldQueue` with `$timeout = 900`, `$tries = 1`, constructor `(int $userId, string $channel, ?string $conversationId, string $text, string $runId)`. It iterates `AssistantService::stream(..)` and appends every event, with gate checks skipped (already done). On an uncaught exception it appends an `error` event.
  - The stale `ai_run_events` rows (older than 30 days) are pruned with `Model::prunable`.

- [ ] **Step 1: Write failing tests:**
  - `start()` with `Queue::fake()` dispatches the job, and a gate failure appends an error event without dispatching;
  - running the job synchronously with fakes writes ordered events ending in `done`;
  - `since($runId, $user, 3)` returns only seq > 3;
  - another user's `since` returns `[]`;
  - **cancel:** `cancel($runId)` before the second step makes the log end with `error(cancelled)` and no `done`, including when a specialist is mid-run.
- [ ] **Step 2:** Run `php artisan test --compact --filter=QueuedRunTest`. Expected: FAIL.
- [ ] **Step 3:** Implement it.
- [ ] **Step 4:** Run `php artisan test --compact --filter="QueuedRunTest|AssistantServiceStreamTest"`. Expected: PASS.
- [ ] **Step 5:** Commit: `git commit -m "feat(assistant): run questions in a queued job with a stored event log"`

---

### Task 13: Chat page work log with polling

**Files:**
- Modify: `app/Filament/App/Pages/Assistant.php`, `resources/views/filament/app/assistant/index.blade.php`
- Create: `resources/views/filament/app/assistant/work-log.blade.php`
- Test: `tests/Feature/Assistant/Page/AssistantPageTest.php` (extend it)

**Interfaces:**
- Consumes: `Assistant::start`, `RunLog::since`, `RunLog::forMessage`, `RunLog::isFinished`.
- Produces: `send()` calls `start()` instead of streaming in-request, and stores `currentRunId` and `lastSeq = 0`. A new `poll()` action, wired with `wire:poll.1s` only while `isStreaming`, pulls `since(currentRunId, lastSeq)` and updates these public properties:
  - `workLog: array{plan:?string, agents: list<array{agent, label, status: running|done|failed, step:?string, findings: list<string>}>}`
  - `streamedAnswer`

  On `done`/`error` it reloads the messages. In `mount()`, if the user has an unfinished run in this conversation (its last event isn't done/error), the page sets `isStreaming` and resumes polling. Each persisted assistant message gets a `workLog` loaded through `RunLog::forMessage`.

  Work-log view: one row per agent (label, spinner or ✓ or ✗, current step, findings). It collapses to `"استعان بـ: التسويق، المالية · N خطوات"` once answer text exists, and expands on click. Colors use the existing theme tokens in `index.blade.php`. It is RTL.
- [ ] **Step 1: Write failing tests** (Livewire test with `Queue::fake()` and `RunLog::append` seeding events directly):
  - after `send`, `isStreaming` is true and the job is pushed;
  - `poll()` after seeding `plan`, `agent_started`, `step_started` → `workLog` has the plan and one running agent with its step;
  - `step_finished` adds a finding;
  - `done` stops streaming and the message shows a collapsed log;
  - **reload:** a new component mount with an unfinished run restores `workLog` and `isStreaming`;
  - the stop button calls `cancel` for `currentRunId`.
- [ ] **Step 2:** Run `php artisan test --compact --filter=AssistantPageTest`. Expected: the new tests FAIL.
- [ ] **Step 3:** Implement it.
- [ ] **Step 4:** Run `php artisan test --compact --filter=AssistantPageTest`. Expected: PASS.
- [ ] **Step 5:** Manually check with the `run` skill or the dev server and a queue worker (`php artisan queue:work --timeout=900`). Ask the screenshot question "ايه المنتجات اللي المفروض اركز على تسويقها الفترة الجاية". The work log should show the marketing specialist's steps live, and the answer must not contain any drink, add-on or adjustment item.
- [ ] **Step 6:** Commit: `git commit -m "feat(assistant): show a live work log on the chat page"`

---

### Task 14: Rule-based evals

**Files:**
- Create: `tests/Evals/questions.v1.php`
- Modify: `app/Domain/Assistant/Evals/EvalRunner.php` (support a `rules` key and a `fixture` key), `tests/Feature/Assistant/Evals/EvalRunnerTest.php`

**Interfaces:**
- Consumes: `MenuFixture`, `ItemRoles`, `RunLog`/stream events.
- Produces: case fields `fixture: 'two_branches'|'menu'` and `rules: list<string>` from this closed set:
  - `no_delegation`
  - `delegates:<agent>`
  - `focus_excludes_roles:drink,addon,adjustment,raw`: no item name whose role is listed appears under the "ركز على" section
  - `combos_one_main_above_floor`: re-check every combo in the `combo_builder` tool results of the run
  - `grounded`: the existing `NumericGroundingChecker`

  v1 holds ≥ 12 cases: 4 marketing (including the mineral-water-style question), 3 finance (including "how much will sales be next month"), 3 operations, and 2 simple KPI questions with `no_delegation`.
- [ ] **Step 1: Write failing tests:** the runner grades each rule correctly against canned transcripts (one passing and one failing transcript per rule).
- [ ] **Step 2:** Run `php artisan test --compact --filter=EvalRunnerTest`. Expected: FAIL.
- [ ] **Step 3:** Implement the rules and write `questions.v1.php`.
- [ ] **Step 4:** Run `php artisan test --compact --filter=EvalRunnerTest`. Expected: PASS. Then run the full suite: `php artisan test --compact`. Expected: all green.
- [ ] **Step 5:** Commit: `git commit -m "test(assistant): add rule-based evals for specialists"`
