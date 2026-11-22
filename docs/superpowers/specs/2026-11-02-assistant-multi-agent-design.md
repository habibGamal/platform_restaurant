# Assistant redesign: coordinator + specialist agents

Date: 2026-11-02
Status: draft for owner approval

## Why

The assistant ("المساعد الذكي") gives answers that sound confident but are not useful. Example: asked "which products should I focus marketing on next period?", it recommended **مياه معدنية صغيره** (small mineral water). Nobody comes to a restaurant for water. It is a complementary item.

Root causes found in the current code and data:

- There is one general-purpose agent (`app/Ai/Agents/RestaurantAnalyst.php`) with a generic prompt. The prompt points at analysis guides ("playbooks") that do not exist.
- Its tools return raw numbers (`GetKpis`, `GetKpiSeries`, `QueryMetrics`). The model does the analysis in its head, so with 777 products it ends up ranking by volume.
- It does not know what an item *is*. The top "products" by quantity are **فرق طلبات** (a price-adjustment line: price 1, zero cost, "100% margin") and the small mineral water.
- The user only sees the answer at the end, plus a few source chips. They cannot see what the assistant is doing.

## What the owner wants (their words, condensed)

- **Marketing answers like a smart marketing manager.** Focus on products that are a good deal for the customer but don't sell much, and on high-margin products. Suggest combo offers whose items actually go together. The assistant can add its own ideas.
- **Complementary items** (water, drinks, sauces, add-ons) are never recommended as items to market.
- **Specialist agents:** marketing, finance, management/operations, and others as needed.
- **Live progress:** show what the agent is doing while it works instead of waiting for the full answer.
- **Not biased to today's database.** Products, categories and data will change. Nothing gets hard-coded to current product or category names. The current DB may be used to test, but the design must keep working as the menu changes.

## Goals

1. Answers that are correct in business terms, not just numerically grounded.
2. Every recommendation comes with its reason in numbers (margin %, sales vs. the group average, trend).
3. The user sees the plan, which specialist is working, what it is checking, and short findings while the run is in progress.
4. Works for any restaurant org on the platform with any menu, not just the current one.

## Non-goals

- Changing ingestion, the warehouse schema, or the existing report pages.
- Letting the AI write SQL. Analysis stays in whitelisted, permission-checked code (the same principle as today's `QueryMetrics`).
- Taking actions (changing prices, creating offers in the POS). The assistant recommends; humans decide.

## Anti-goals (would count as failure even if it "works")

- Any marketing focus list that contains a drink, add-on, sauce, adjustment line or raw material.
- Combos made of items that don't go together (e.g. two mains plus nothing, a dessert with a sauce), or combos priced at a loss.
- The model doing arithmetic itself (margins, shares, forecasts). All numbers come from tools.
- Hard-coded product or category names (Arabic strings) in prompts or code.
- A long data-completeness warning on top of every answer. One line, unless missing data changes the conclusion.
- Slower answers for simple questions ("كام مبيعات النهارده؟") just because there are now several agents.

## Constraints

- Laravel 12 + `laravel/ai` v1.3. That library already provides:
  - `Laravel\Ai\Tools\AgentTool`, which wraps an agent as a tool for another agent. Its `stream()` yields the sub-agent's stream events up to the parent, so nested progress is available.
  - `invocationId` on every stream event, plus `ParentInvocation`, to tell which (sub-)agent an event came from.
  - Skills (`Laravel\Ai\Skills\Skill`, `SKILL.md` with frontmatter, loaded on demand via `LoadSkill`). This is the natural home for each specialist's playbook.
  - Tool calls within one step run one after another, not in parallel.
- Provider: the org's configured provider and model (currently miraiapi via the `openai` entry on the `openai-compatible` chat-completions driver, model `claude-opus-4.8`). Failover chain, BYO keys, and reasoning level from `AiSetting` must keep working for every agent.
- Existing middleware must apply to every agent, sub-agents included: `EnforceBudget`, `RecordUsage`, `StopOnCancel`, `InjectRuntimeContext`.
- Branch permissions: every tool only sees branches the user may access (reuse `UsesReportFilters` / `FindBranches` scoping).
- Channels: Chat page (Livewire streaming in `app/Filament/App/Pages/Assistant.php`) and WhatsApp (`Channel::WhatsApp`, short answers, no tables).
- Answers in Arabic, Western digits, `ج.م` currency (current house style).

## Data available (as of today; treat as an example, not a contract)

- `dim_products` (name, category, type `manufactured | raw_material | consumable`, price, cost), `dim_categories` (35 today, messy names), `dim_recipes`.
- `wh_orders` (type, hour_of_day, weekday, customer_hash on ~30% of orders) and `wh_order_items` (product, qty, price, unit_cost, discount): 32k orders, 90k lines, ~2.8 items/order. That is enough for "bought together" analysis.
- `agg_product_daily` (qty, net sales, COGS, gross profit, waste per product per branch per day), `agg_branch_daily`, `agg_branch_hourly`, `agg_shift`, expenses, purchases, targets.
- History starts 2026-01-21, so year-over-year seasonality isn't available yet.
- Cost data quality varies: some items have zero cost (margin shows as 100%).

## The design

### 1. Item roles: learned, stored, correctable

A new piece of domain knowledge: each product's **role**.

Roles: `main`, `side`, `drink`, `dessert`, `addon` (sauces, extras, bread), `combo` (existing bundles and offers), `adjustment` (pseudo-products like price-difference lines), `raw` (raw materials and consumables, not sold as menu items).

How a role is assigned (in this order of precedence):

1. **Owner correction**, which always wins and is never overwritten.
2. **Hard signals from data:** `dim_products.type` of raw_material/consumable gives `raw`. A near-zero unit price with zero cost, used mostly as an extra line on orders, gives `adjustment`.
3. **AI classification of the category**, then of the product where the category is ambiguous (e.g. "كومبو", "عروض", "الوجبات الجديدة"). It uses the names, the price relative to the org's median, and ordering behaviour: how often the item is ordered alone vs. always alongside other items (attach rate), and typical quantity per order. Classification uses a cheap model call with structured output. It runs per **category** first (35 calls, not 777) and per product only where needed.
4. Results are stored (new table, e.g. `product_roles`: org, product_uuid, role, source `auto|owner`, confidence, classified_at) and reused by every tool.

When it reruns: new products or categories appear (hook into ingestion's dimension upsert, or a nightly job), plus a manual "reclassify" button. Owner-sourced rows are never touched.

**Scope of a role:** one role per product per **org**, keyed the same way existing reports group products across branches: `COALESCE(product_ref, product_uuid)`. Category roles are keyed by normalised category name per org. An owner correction applies to every branch.

**Items without a trusted role** (not yet classified, classification failed, or auto confidence below a threshold): tools leave them out of focus lists and combos, and return them in a separate `unclassified` list so the answer can say "N items still need a role". If an analysis tool finds unclassified items, it first tries an on-demand classification (bounded, e.g. one batched call), then falls back to this rule. This keeps the main anti-goal (no drink or add-on in focus lists) true even for brand-new menus.

**Who pays for classification:** it is recorded in the org's AI usage ledger as a system entry with no user (extend `UsageRecorder` to allow a null user). It is skipped for orgs without AI access. When the org's budget is exhausted, only the hard data signals apply, and the unknown-item rule above covers the rest.

**Settings UI:** a simple page/section under AI settings listing categories and products with their role and confidence. The owner can change a role. Low-confidence items are shown first.

The **owner's** rule: drinks/add-ons are never marketing targets. The **implementation** of "what is a drink/add-on" is learned, not hard-coded.

### 2. Analysis in code, interpretation by the AI

Specialists get purpose-built analysis tools that do the math in PHP/SQL and return compact, already-ranked results with the "why" fields included. The model chooses tools, reads results, and explains them. It never computes numbers.

All tools take period + branch scope, respect permissions, report data completeness, and are cacheable (reuse `CachesToolResults`, `FormatsToolResult`).

### 3. Agents

All agents are classes in `app/Ai/Agents/`. Each has its own short prompt plus one or more skills (playbooks) in `resources/ai/skills/<agent>/<skill>/SKILL.md`. They share the org's provider/model/failover/reasoning settings (extract today's `provider()`, `model()` and `providerOptions()` from `RestaurantAnalyst` into a shared trait or base class).

**Coordinator ("المساعد")**: the only agent the user talks to; it remembers the conversation.
- Understands the question, resolves the period and branches, and decides one of two paths:
  - **Simple fact** (a KPI, a number, a quick comparison): answers directly with the basic tools (`GetWorkspaceContext`, `ResolvePeriod`, `FindBranches`, `GetKpis`, `GetKpiSeries`, `QueryMetrics`). No delegation, so simple questions stay fast.
  - **Analysis or advice:** writes a short plan, delegates self-contained tasks (with period, branches, and what's needed) to one or more specialists via `AgentTool`, then writes the final answer from their findings.
- Writes the final answer in house style (see §5).

**Marketing & menu specialist**
- Tools:
  - `menu_engineering`: classic menu-engineering matrix, computed **within comparable roles only** (mains vs mains, sides vs sides). Popularity uses menu-mix share vs. 70% of the fair share (1/N) in that role group. Profitability uses contribution margin per unit vs. the group's weighted average. Returns four groups: **hidden gems** (high margin, low popularity: the main marketing targets), **stars**, **workhorses**, **weak items**. Per branch and overall. Excludes `drink/addon/adjustment/raw`. Flags new items (few days on sale) and unknown-cost items (zero cost) separately instead of ranking them.
  - `bought_together`: basket analysis on `wh_order_items` (support, confidence, lift) between roles, e.g. main→side, main→drink. Minimum-support thresholds scale with order volume.
  - `combo_builder`: given a target item (usually a hidden gem), proposes 1–3 combos. Each is a main plus a compatible side/drink chosen by lift and popularity, never two mains, and never a combo made only of complementary items. Each comes with list price, suggested combo price, cost, margin % and discount %. The suggested price keeps the combo margin at or above a floor (default: the org's average margin for mains). Learns from existing `combo`-role items too.
  - `item_trends`: items rising and falling (recent weeks vs. the prior period) within their role group.
  - `item_timing`: when each item sells (weekday × hour), to suggest pushing items at slow times.
- Skills: "marketing focus", "combo design", "menu cleanup", "promotion review" (did a past discount move volume?).

**Finance specialist**
- Tools: wraps existing reports (`ProfitabilityReport`, `FoodCostWasteReport`, `ExpensesPurchasesReport`, `SalesComparisonReport`, `TargetsVsActualReport`) as compact tools, plus a new `sales_forecast` tool.
- `sales_forecast` (the original screenshot question: "how much will sales be next month?") is deterministic: a weekday-aware baseline from recent complete days, with trend, excluding incomplete days, and a low/expected/high range. It states its method and confidence. The model never extrapolates in its head.
- Skills: "profit review", "cost and food-cost check", "forecast", "expense anomalies".

**Operations / management specialist**
- Tools: wraps `BranchRankingReport`, `ShiftsWeekdaysReport`, `OverviewReport`, waste data, `agg_branch_hourly` (peak hours), order types/channels.
- Skills: "branch comparison", "peak hours & staffing", "waste", "targets follow-up".

**Data checker**
- Uses the existing completeness logic (`Completeness`, `DataHealthReport`).
- Answers one question for the coordinator: given these findings, does missing or unreconciled data change the conclusion? It returns a one-line note, or a short warning when it does.
- Called only when a specialist's tools reported completeness issues.

Delegation runs specialist by specialist (the library executes tool calls sequentially). Running them in parallel is a later optimisation and is not required.

**Running time and where runs execute.** An analysis question with 2–3 specialists can take several minutes, which a single web request may not survive in production. So every run executes in a **queued job**. The job writes its progress events and answer chunks to storage keyed by `runId`, and the Chat page polls for new events about once a second. This works whatever the web-server timeouts are, survives a page reload mid-run, and keeps cancel working: `StopOnCancel` already reads a flag keyed by user and run. No broadcasting server is required; broadcasting can replace polling later.

**Cost per question.** One shared budget per user question, counted across the coordinator and all specialists through `runId`. Today, `EnforceBudget` counts each agent run separately, so sub-agents would each get the full cap. The per-question cap is configurable and defaults to 2× today's `per_run_input_token_cap`. Specialists also get lower step limits than the coordinator. Expect an analysis question to cost roughly 2–4× today's single-agent answer. Simple questions cost the same as today because they skip delegation.

**Sub-agent text in the stream.** Sub-agents' text and end-of-stream events arrive in the same stream as the coordinator's. `AssistantService` must use `invocationId` to keep only the coordinator's text as the answer and turn everything else into work-log events.

### 4. Live progress view

On the Chat page, above the answer, show a **work log** that updates live:

- The coordinator's plan as one short line (e.g. "هراجع المنيو مع التسويق، وأتأكد من الهوامش مع المالية").
- One row per specialist (icon + Arabic name), showing its current step in plain Arabic ("بحسب هوامش الأطباق الرئيسية…"), then a short finding when the step ends ("لقيت 41 صنف مربح ومش بيتباع كفاية").
- Each tool gets a human-readable label for "doing" and a one-line summary of its result. Tools provide these themselves (e.g. a `summary` field in their formatted result), so the UI never shows raw JSON or tool names.
- When the answer starts streaming, the log collapses to one line ("استعان بـ: التسويق، المالية · 7 خطوات") that can be expanded. It is saved with the message so it is still viewable after reload.
- Events: extend `AssistantEvent` with `plan`, `agent_started`, `step_started`, `step_finished`, `agent_finished`, mapped from library stream events using `invocationId` to tell agents apart.
- WhatsApp: there is no WhatsApp sending in the codebase yet, only the `Channel::WhatsApp` flag. This work only honours that flag (short answers, no tables, no work log). Building WhatsApp delivery is out of scope.

### 5. Answer style

- Headline with the conclusion first, then 2–5 bullets, each with its number **and** its reason.
- For marketing: "ركز على" items (hidden gems), "متلمسش" items (stars, protect them), combo table (items, price, margin), then ideas.
- Period/branch in one short line. Completeness in one line unless the data checker says it changes the conclusion.
- Recommendations labelled "اقتراح:". The decision stays human.
- Keep the WhatsApp rules (no tables, ~900 chars).

### 6. Quality checks

- Extend the existing eval runner (`app/Domain/Assistant/Evals`) with golden questions per specialist and **rule-based assertions**, e.g.:
  - The marketing focus list contains no item whose role is drink/addon/adjustment/raw.
  - Every combo has exactly one main and positive margin ≥ the floor.
  - A simple KPI question makes zero delegations.
  - Every number in the answer appears in tool results (existing `NumericGroundingChecker`).
- Unit/feature tests for each analysis tool using factory data with **made-up** product and category names, to prove nothing depends on today's menu.

## Decided by the owner

- Specialist agents: marketing, finance, management/operations, plus whatever else is needed (the owner approved coordinator + marketing + finance + operations + data checker).
- Marketing logic: complementary items are never focus items. Focus on good-value-but-low-sales and high-margin items. Combos must make sense together.
- "Good deal for the customer" and "high margin" form **one group** ("hidden gems": high margin, low popularity within the role group). There is no separate price-value measure.
- Live visibility of the agent's work.
- No bias to the current DB; it must survive menu and data changes.
- Proposal approved as a whole ("all is perfect").

## My calls (owner can override while reviewing)

- Role list and the precedence order in §1, including a settings page for corrections.
- Menu-engineering method (Kasavana–Smith style, within role groups).
- Combo margin floor default = org average margin of mains.
- Forecast is a deterministic tool with a range, not model reasoning.
- Simple questions bypass specialists.
- Work log collapses once the answer starts and is saved with the message.
- Data checker only runs when completeness issues exist.
- Arabic house style unchanged apart from §5.
- Roles are per org (shared by all branches), keyed like existing reports.
- Items without a trusted role are kept out of focus lists and combos and reported separately.
- Classification is charged to the org's AI usage ledger as a system entry, and skipped without AI access or budget.
- Runs execute in a queued job and the page polls for progress, instead of one long web request.
- One shared token budget per question (default 2× today's per-run cap). Analysis questions cost roughly 2–4× today's.
- WhatsApp: only the existing channel flag is honoured; delivery is out of scope.

## Left to the builder

- Exact table/column names, job scheduling for reclassification, cache TTLs.
- Statistical thresholds (minimum support, "new item" days, trend windows), as long as they scale with data volume rather than being tuned to today's numbers.
- Which model is used for role classification (a cheaper model is fine if the org's provider offers one; otherwise the org's model).
- Whether specialists stream their own text to the user (default: no, only progress plus the coordinator's final answer).
- How to migrate `RestaurantAnalyst` (rename or replace) while keeping existing conversations readable.
