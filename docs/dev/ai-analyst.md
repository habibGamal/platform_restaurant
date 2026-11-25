# AI Analyst, MCP Server & Menu Intelligence

The AI subsystem provides a grounded conversational analyst (`RestaurantAnalyst`), a full-screen React chat UI (`/assistant`), a local and remote MCP server (`RestaurantServer`), organization token/EGP billing controls, and nightly Menu Intelligence precomputation (`BuildMenuIntelligence`).

---

## 1. Access & Billing Gates (`AiAccess`, `BudgetGuard`)

- **Access gate (`use-assistant` in `AssistantServiceProvider` -> `AiAccess`):** Requires:
  1. Authenticated user belongs to an organization (`organization_id !== null`).
  2. User holds Spatie permission `ai.view`.
  3. Organization has `ai_enabled = true` and `plan === OrganizationPlan::PRO` (`'pro'`).
- **Provider settings (`ai_settings`):** Configured per organization at `/app/management/ai-settings` (`AiSettingsPage`, requires `ai.manage`), including `provider`, `model`, `failover`, `reasoning_level`, optional encrypted `byo_key`, `monthly_budget_egp`, `per_user_monthly_egp`, `warn_at`, and `hard_stop`.
- **Metering & caps (`config/ai_billing.php`, `UsageRecorder`, `BudgetGuard`):**
  - Default monthly budgets: `basic = 0.0`, `pro = 1500.0` EGP (`AI_USD_TO_EGP = 50.0`).
  - Rate limits: `20` messages/min per user, `5` concurrent runs per org.
  - Token caps: `120,000` input tokens per run (`AI_PER_RUN_INPUT_TOKEN_CAP`), `240,000` across coordinator + specialists per question (`AI_PER_QUESTION_INPUT_TOKEN_CAP`).
  - Step usage is recorded in `ai_usage_ledger` against `ai_model_prices` (`AiModelPriceSeeder`).

---

## 2. Multi-Agent Coordinator & Execution Pipeline

`RestaurantAnalyst` (`app/Ai/Agents/RestaurantAnalyst.php`) acts as the coordinator (`#[MaxSteps(10)]`, `#[MaxTokens(4096)]`, `#[Timeout(300)]`, prompt & tool caching enabled) with middleware `InjectRuntimeContext`, `EnforceBudget`, `StopOnCancel`, and `RecordUsage`.

| Agent | Role | Key Tools & Playbook Skills (`resources/ai/skills/`) |
|---|---|---|
| `RestaurantAnalyst` | Coordinator & synthesis (Egyptian Arabic plan `خطة: ...`) | `get_workspace_context`, `resolve_period`, `find_branches`, `get_kpis`, `get_kpi_series`, `query_metrics`, plus specialist agents |
| `FinanceSpecialist` | Margins, costs, expenses, targets, forecasts | `ProfitabilityTool`, `FoodCostWasteTool`, `ExpensesPurchasesTool`, `SalesComparisonTool`, `TargetsTool`, `SalesForecastTool` (`SalesForecast` weekday median trend, requires >= 28 complete days & >= 200 orders) |
| `MarketingSpecialist` | Menu focus, combos, affinities, item cards | `MenuEngineering`, `BoughtTogether`, `ComboBuilderTool`, `ItemTrendsTool`, `ItemTimingTool`, `QueryMetrics`, `RankItems`, `GetItemCard`, `GetComboCandidates`, `GetAffinities` |
| `OperationsSpecialist` | Branch ranking, shifts, peak hours, waste, targets | `BranchRankingTool`, `ShiftsWeekdaysTool`, `OverviewTool`, `PeakHoursTool`, `DataHealthTool`, `TargetsTool` |
| `DataChecker` | Verifies whether completeness warnings alter conclusions | `DataHealthTool` |

### Queue & Streaming Architecture
- Questions run asynchronously via `RunAssistantQuestion` on the dedicated **`assistant`** connection and queue (`timeout = 900`, `tries = 1`, `uniqueFor = 960`, `ASSISTANT_QUEUE_RETRY_AFTER = 960`).
- `RunLog` persists `AiRunEvent` rows (pruned daily via `model:prune`).
- `NumericGroundingChecker` validates answer numerals against tool outputs.
- `BufferedChatCompletionsGateway` (`AppServiceProvider::configureAiProviders`) handles `OPENAI_STREAM=false` proxies that drop streamed tool calls, and `Http::globalOptions(['read_timeout' => 300])` prevents premature Guzzle stream timeouts.

---

## 3. React Chat UI (`/assistant`) & Packages

When `config('assistant.react_ui')` (`ASSISTANT_REACT_UI`, default `true`) is enabled, visiting `/app/assistant` redirects to `/assistant` (Inertia page `resources/js/pages/assistant/index.tsx`). Setting `ASSISTANT_REACT_UI=false` rolls back immediately to the Filament Livewire page.

### Routes (`routes/assistant/*.php`, middleware `auth`, `verified`, `can:use-assistant`)
- `GET /assistant` — Render Inertia React chat page (`AssistantPageController`)
- `POST /assistant/api/chat` — Start/continue run, returns SSE with `x-resumable-stream-id: <runId>` (`RESUMABLE_STREAM_ID_HEADER`)
- `GET /assistant/api/runs/{runId}/stream` & `POST /assistant/api/runs/{runId}/cancel` — Tail/cancel `RunTail` SSE stream (`ai-sdk` UI message stream)
- `GET/POST /assistant/api/threads`, `GET/PATCH/DELETE /assistant/api/threads/{id}`, `POST .../title`, `GET .../messages` — Thread management (`ThreadHistoryAdapter.withFormat(fmt)` using `"ai-sdk/v6"` / `aiSDKV6FormatAdapter`)
- `POST /assistant/api/combos/{signature}/decision` — Approve, reject, or reset `combo_candidates` decisions

### Confirmed Frontend Stack (`package.json`)
- `@assistant-ui/react` (`0.15.33`) + `@assistant-ui/ai-sdk` (`0.0.17`), `ai` (`7.0.165`), `@ai-sdk/react` (`4.0.169`), `streamdown` (`2.7.1`), `recharts` (`3.10.1`), `@tanstack/react-table` (`9.3.1`).
- Render-only tool UIs use `defineToolkit` (`type: "backend"`) rather than deprecated `makeAssistantToolUI`, alongside `makeAssistantDataUI` / `DataRenderers` for `"worklog"` / `"status"` data parts (`config('assistant.ui_tools')`).
- Components under `resources/js/components/assistant-ui/` use logical Tailwind CSS utilities (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`, `text-start`, `text-end`) for RTL support.

---

## 4. MCP Server (`routes/ai.php`, `RestaurantServer`)

`App\Mcp\Servers\RestaurantServer` bridges all read-only tools from the 5 agents (`BridgedTool`) and all `resources/ai/skills/*/*/SKILL.md` playbooks (`SkillPrompt`) scoped to the authenticated `User`:
- **Remote OAuth MCP:** `Mcp::web('/mcp/restaurants', RestaurantServer::class)` using Laravel Passport (`Mcp::oauthRoutes()`, 8h access tokens, 30d refresh tokens, allowed `MCP_REDIRECT_DOMAINS` in `config/mcp.php`, and `EnsureAssistantAccess`).
- **Local stdio MCP:** `php artisan mcp:start restaurants`, acting as the user configured in `MCP_USER_ID` (`config('ai.mcp.user_id')`).

---

## 5. Menu Engineering & Nightly Intelligence Pipeline

1. **BCG Item Roles (`ClassifyItemRoles`, daily at 04:00):** Evaluates sales signals (`RoleSignals`) and optional LLM classification (`ItemRoleClassifier`, `ai.roles.trusted_confidence = 0.70`, `reclassify_after_days = 7`) into `dim_item_roles` / `dim_category_roles`, reviewable at `/app/management/item-roles` (manual overrides lock against overwrites).
2. **Nightly Precomputation (`BuildMenuIntelligence`, daily at 04:30 & `php artisan menu:build-intelligence`):**
   - `IntelligenceClassifier`: classifies recipe components (`FoodClass`) and category meal types/seasons (`owner > auto > rule` via `LabelTrust`).
   - `ItemProfileBuilder`: builds `item_profiles` (`serves_n`, `price_tier`, `season`, `contains`, `main_protein`).
   - `ItemStatsBuilder`: aggregates `item_period_stats` (open days, velocity, attach rate, daypart/weekday/channel mix, MoM change).
   - `AffinityMiner`: mines multi-item baskets (`max_itemset_size = 4`, `min_baskets = 200`, `min_support_orders = 5`, `min_support_ratio = 0.002`) into `item_affinities`.
   - `ItemRanker`: ranks items within `<role>:<price_tier>` segments across 4 strategies (`push_next_month`, `protect`, `reprice`, `fix_or_drop`) into `item_rankings`.
   - `ComboCandidateGenerator` + `BundlePricer`: builds `solo_meal`, `sharing_box`, and `family_feast` candidate bundles (`combo_candidates`) respecting margin floors and overlap thresholds.
   - Operators review and override outputs on `/app/management/item-cards`, `/app/management/component-classes`, and `/app/management/combo-candidates`.
