# Changelog

All notable changes to this project. Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project is not versioned; history is grouped by month.

## [Unreleased]

### Added
- Consolidated documentation into a single VitePress 1.6.4 site in `docs/` (Arabic default at `/`, English developer docs under `/dev/`), added `AGENTS.md`, `CLAUDE.md`, `.workspace/`, `scripts/docs-check.sh`, and `.github/workflows/docs-check.yml`.

### Removed
- Removed the legacy in-app Docsify viewer (`docs/index.html`, `docs/_sidebar.md`, `docs_server.php`, `ServeDocsCommand` / `docs:serve`, `/docs/{file?}` route, and the `/app` "التوثيق الفني" navigation item).

### Changed
- **Menu Intelligence Precomputation Subsystem:** Nightly background pipeline (`BuildMenuIntelligence`, `menu:build-intelligence`) precomputing item profiles, rolling period sales statistics, APRIORI basket affinities, strategic segment rankings, and candidate combo meal packages into 8 new analytical database tables.
- **Menu Management Review Interfaces:** Three interactive Filament review pages under Management: Item Cards (`/app/management/item-cards`), Recipe Component Classes (`/app/management/component-classes`), and Combo Candidates (`/app/management/combo-candidates`).
- **Precomputed Menu Intelligence AI Tools:** Added `rank_items`, `get_item_card`, `get_combo_candidates`, and `get_affinities` to `MarketingSpecialist`, enforcing segment-based item ranking and margin floors.
- **Global Topbar Scope Selector:** Added persistent `ReportScopeSelector` Livewire component to the Filament App Panel topbar (`PanelsRenderHook::TOPBAR_END`) for selecting Restaurant / Brand and Branches across all analytics reports.
- **Order Type Sales Breakdown:** Added `OrderTypeSales` domain service and `OrderTypeSalesWidget` in the Sales Comparison report, categorizing orders and sales by channel (`dine_in`, `takeaway`, `delivery`, `drive_thru`, etc.).
- **Bulk Restaurant Target Allocation:** Added `restaurantTargets` bulk header action in `ListTargets` allowing operators to allocate monthly targets for sales, profit, orders, and food cost percentage across branches.
- **In-Memory Table Search & Chart Percentage Formatting:** Upgraded `ArrayTableRecords` to provide full-text and column-specific searching, multi-column sorting, and selectable page sizes (15, 30, 50, 100), plus raw JS percentage formatting in `BaseReportChartWidget`.
- **React Assistant Chat UI & MCP Server:** Full-screen React chat UI (`/assistant`) backed by `RunAssistantQuestion` and SSE run streams, plus OAuth/stdio MCP server (`RestaurantServer`) at `/mcp/restaurants`.
- Refined AI Assistant coordinator planning protocol to output concise Egyptian Arabic plans (`خطة: ...`) and silenced intermediate chat narration during tool execution.
- Report tool response envelopes now pass explicit definitions clarifying stock-count variance sign (`inventoryVarianceCost`: positive = shortage loss, negative = surplus gain) and net profit formulas.
- Enhanced `ResolvePeriod` to parse multi-month conversational date ranges (`monthsRange`).

### Fixed
- Corrected `MetricsQuery::branchIds()` branch set and restaurant candidate scoping to intersect properly with user accessible branch IDs.
- Automated dispatch of debounced `BuildMenuIntelligence` job from `ProcessIngestBatch` whenever catalog items, recipes, or back-dated sales orders are ingested.

### Upgrade notes
- Run `php artisan migrate` to create Menu Intelligence tables (`component_classes`, `category_profiles`, `item_profiles`, `item_period_stats`, `item_affinities`, `item_rankings`, `combo_candidates`, `menu_intelligence_builds`).
- Ensure cron triggers `php artisan schedule:run` every minute to execute the nightly `BuildMenuIntelligence` job scheduled at 04:30.

## 2026-11 (untagged)

### Added
- **AI Assistant & Multi-Agent Coordinator:** Integrated a multi-agent restaurant analyst coordinator (`RestaurantAnalyst`) with specialized agents (`FinanceSpecialist`, `OperationsSpecialist`, `MarketingSpecialist`, `DataChecker`) and interactive web chat.
- **Live Assistant Work Log & Streaming:** Added background execution for assistant queries via `RunAssistantQuestion` with SSE progress reporting and persistent run events.
- **Whitelisted Metrics Query Engine:** Added the `query_metrics` tool allowing the AI assistant to execute pre-validated KPI queries against aggregate and dimension models.
- **Menu Engineering & Item Role Classification:** Introduced automated BCG matrix classification via hard business signals and LLM evaluation.
- **Menu Analytics Tools:** Added basket analysis (`bought_together`), combo builder, item timing, and sales trend tools.
- **Item Roles Review Interface:** Created an interactive Filament management page (`ItemRolesPage`) for operators to review, filter, and manually override item classifications.
- **AI Token Budgets & Billing:** Added per-organization AI provider configuration and monthly token/EGP budget metering.

### Changed
- Shared organization-level AI settings and budgets across all subagents within a single question run.
- Enabled response streaming by default for OpenAI and compatible proxy providers.

### Fixed
- Dedicated queue connection/routing (`assistant`) for assistant query jobs to isolate AI latency from real-time POS ingestion.
- Bounded on-demand menu role classification with rate limits and execution timeouts.
- Hardened reasoning option parameter mapping for OpenAI-compatible chat completion gateways.

### Upgrade notes
- Run `php artisan migrate` to create AI assistant, billing, and `dim_item_roles` tables.
- Configure queue workers to listen to the `assistant` connection and queue (`php artisan queue:work assistant --queue=assistant --timeout=900 --tries=1`).
- Set provider credentials (`OPENAI_API_KEY`) or configure organization-specific credentials via the App Panel under **Management > AI Settings**.

## 2026-10 (untagged)

### Added
- **Multi-Tenant Foundation & Admin Panel:** Implemented tenancy hierarchy (Organizations, Restaurants, Branches, Users, Branch Sets) with Spatie RBAC and two Filament panels (`/admin` and `/app`).
- **Cryptographic POS Pairing & Ingestion API:** Added 8-digit one-time code pairing handshake, Sanctum bearer tokens with `ability:ingest`, automatic token rotation, and gzip-compressed batch submission at `/api/v1/ingest/batches`.
- **Bidirectional POS Heartbeat Protocol:** Introduced telemetry reporting and queued remote management commands via `/api/v1/heartbeat`.
- **Idempotent Ingestion & Version Guards:** Handled 16 POS document types with batch idempotency, entity version checks, and soft tombstones in raw warehouse storage.
- **Concurrency Fencing & Dirty Date Outbox:** Protected ingestion batches with database leases, monotonic fencing tokens (`FencedWorkerException`), and durable dirty date tracking (`ingest_dirty_dates`).
- **Daily Aggregate Engine & Reconciliation:** Built `BranchDayRebuilder` to aggregate orders, shifts, and inventory into daily KPI rollups, with automated summary reconciliation and POS resync commands.
- **Metrics Query Engine & KPI Catalog:** Implemented `MetricsQuery` with access-safe branch scoping, 364-day weekday alignment (52-week LFL), period presets, and standard formulas for KPIs K01 through K25.
- **Production Analytics Reporting Suite:** Launched 10 interactive analytics dashboards (Overview, Sales Comparison, Profitability, Food Cost & Waste, Product Performance, Shifts & Weekdays, Branch Ranking, Targets vs Actual, Expenses & Purchases, Data Health) with in-memory table pagination.
- **Target Planning & Historical Distribution:** Added target management with 8-week history weekday weight distribution, calendar event exclusions, and restaurant-to-branch bulk allocation.
- **Multi-Criteria Branch Ranking:** Implemented dynamic 7-KPI branch scoring and rank calculation using configurable scoring profiles.
- **Cascading Data Deletion:** Implemented safe cascading deletion for branches across 29 warehouse, dimension, and aggregate tables plus storage cleanup.

### Fixed
- Resolved lock contention during concurrent ingestion batch processing by splitting transactional warehouse writes from asynchronous aggregate scheduling.
- Prevented corrupt POS shift timestamps and negative durations from failing daily aggregate rebuild jobs.

### Upgrade notes
- Initial platform release: run `composer install && npm install && npm run build`.
- Run `php artisan migrate --seed` to initialize platform schema, Spatie roles, and default permissions.
- Ensure supervisor runs `php artisan queue:work` on `ingest` and `aggregate` queues and cron triggers `php artisan schedule:run` every minute.
