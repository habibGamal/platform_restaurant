# 01. Architecture, Repository Structure, and Technology Stack

This document describes the high-level architecture, directory layout, multi-tier data pipeline, and technology stack of `platform-restaurants` alongside its paired Point of Sale (POS) system `larament`.

---

## 1. Architectural Overview

`platform-restaurants` serves as the centralized analytics, multi-branch reporting, and data ingestion hub for multi-unit restaurant organizations. It receives transactional, shift, inventory, and operational documents pushed from decentralized restaurant POS installations, validates and persists raw data payloads, updates mirrored warehouse and dimension tables, incrementally aggregates business metrics, and renders operational intelligence dashboards via Filament.

### High-Level Topology

```
+---------------------------------------------------------------------------------------------+
|                                    Decentralized POS Systems                                |
|                                                                                             |
|   +-------------------------------------------------------------------------------------+   |
|   | Paired POS Instance (larament: Laravel ^11.9, Filament ~4.0, PHP ^8.2, MySQL)       |   |
|   |                                                                                     |   |
|   |  Local Changes (Orders, Returns, Shifts, Expenses, Inventory, Dimensions)           |   |
|   |       |                                                                             |   |
|   |       v (Eloquent Observers: larament:app/Sync/SyncOutboxObserver.php)              |   |
|   |  Local Sync Outbox (table: sync_outbox, buffered mutations)                        |   |
|   |       |                                                                             |   |
|   |       v (Scheduler: platform:sync -> larament:app/Sync/BatchSender.php)            |   |
|   |  HTTP Client (Gzip JSON Envelope, Idempotency-Key, Bearer Token)                    |   |
|   +-------------------------------------------------------------------------------------+   |
+---------------------------------------------------------------------------------------------+
                                       |
                   HTTPS POST /api/v1/ingest/batches
                   HTTPS POST /api/v1/heartbeat
                   HTTPS POST /api/v1/pair[/rotate]
                                       |
+---------------------------------------------------------------------------------------------+
|                                Central Platform (platform-restaurants)                      |
|                                                                                             |
|   1. Ingestion Ingress Layer (HTTP API)                                                     |
|      - Rate Limiting (Throttle: ingest-pair, ingest-batches)                                |
|      - Sanctum Authentication (auth:sanctum, ability:ingest)                                 |
|      - Ingress Middleware (EnsureBranchActive)                                              |
|      - Payload Decompression (Bounded streaming gzip inflation, 5 MB limit)                 |
|      - Envelope & Document Structural Validation (DocumentRules)                            |
|      - Raw Payload Persistence (Local disk: storage/app/private/ingest/{org}/{branch}/...)   |
|      - Ingestion Control Records (ingest_batches, ingest_rejections)                        |
|                                       |
|                                       v (Dispatches after DB commit)                        |
|   2. Ingestion Queue Worker (Queue: ingest)                                                 |
|      - Job: ProcessIngestBatch (tries=0, maxExceptions=3, timeout=60s)                       |
|      - Concurrency & Fencing: WithoutOverlapping("branch-{id}"), lease_expires_at,          |
|        fencing_token monotonic increment                                                    |
|      - Document Handlers (app/Domain/Ingestion/Handlers/*):                                 |
|        - Version Guard: skip if source_version >= incoming version                          |
|        - Upsert / Soft-Delete: updates wh_* and dim_* tables                                |
|        - Replaces child records atomically                                                  |
|        - Dirty Date Outbox: records affected (branch_id, business_date) into durable table   |
|                                       |
|                                       v (Dispatches delayed 30s)                            |
|   3. Aggregation Engine (Queue: aggregate)                                                  |
|      - Job: RecomputeBranchDay (uniqueId: branchId:date, uniqueFor: 120s)                   |
|      - Fencing & Lock: DirtyDateOutbox::acquireRecomputeLock / claimRecomputeFence          |
|      - Aggregator: BranchDayRebuilder                                                       |
|        - Wipes and rebuilds agg_branch_daily, agg_product_daily, agg_shift,                 |
|          agg_branch_hourly, agg_expense_daily, agg_purchase_daily                            |
|        - Reconciles wh_day_summaries vs platform totals (Reconciler)                        |
|        - Conditional resync command queuing (bounded up to 3 attempts)                       |
|        - Dispatches BranchDayUpdated event                                                  |
|                                       |
|                                       v (Queried via MetricsQuery)                          |
|   4. Analytics & Presentation Tier (Filament v5 Panels)                                     |
|      - Admin Panel (/admin): Superadmin operator management (Tenants, Branches, Users)      |
|      - Tenant App Panel (/app): Multi-unit reporting, branch ranking, targets, KPIs         |
|        - Scope Selector: Topbar Livewire component (Restaurant, Branch, Sets)               |
|        - Authorization: Tenancy scopes, AccessibleBranches, Spatie roles & permissions      |
+---------------------------------------------------------------------------------------------+
```

---

## 2. Repository Layout

The codebase follows domain-oriented design principles:

| Repository-Relative Path | Subsystem / Responsibility | Key Classes & Reference Files |
|---|---|---|
| `app/Domain/Ingestion/` | POS document handling, validation rules, registries, outbox, and concurrency exceptions | [`DocumentRules.php`](../../app/Domain/Ingestion/Validation/DocumentRules.php), [`HandlerRegistry.php`](../../app/Domain/Ingestion/Handlers/HandlerRegistry.php), [`AbstractDocumentHandler.php`](../../app/Domain/Ingestion/Handlers/Concerns/AbstractDocumentHandler.php), [`DirtyDateOutbox.php`](../../app/Domain/Ingestion/Support/DirtyDateOutbox.php), [`FencedWorkerException.php`](../../app/Domain/Ingestion/Exceptions/FencedWorkerException.php) |
| `app/Domain/Metrics/` | Analytical engine, KPI calculations, query builder, completeness verification, allocated overhead costs, and period math | [`MetricsQuery.php`](../../app/Domain/Metrics/MetricsQuery.php), [`Kpi.php`](../../app/Domain/Metrics/Kpi.php), [`KpiValues.php`](../../app/Domain/Metrics/KpiValues.php), [`Completeness.php`](../../app/Domain/Metrics/Completeness.php), [`AllocatedCost.php`](../../app/Domain/Metrics/AllocatedCost.php), [`PeriodResolver.php`](../../app/Domain/Metrics/Period/PeriodResolver.php) |
| `app/Domain/Metrics/Aggregation/` | Warehouse recomputation and POS summary reconciliation engine | [`BranchDayRebuilder.php`](../../app/Domain/Metrics/Aggregation/BranchDayRebuilder.php), [`Reconciler.php`](../../app/Domain/Metrics/Aggregation/Reconciler.php) |
| `app/Domain/Metrics/Reports/` | Pure domain reporting calculation classes (10 implemented reports + order type sales) | [`OverviewReport.php`](../../app/Domain/Metrics/Reports/OverviewReport.php), [`SalesComparisonReport.php`](../../app/Domain/Metrics/Reports/SalesComparisonReport.php), [`ProfitabilityReport.php`](../../app/Domain/Metrics/Reports/ProfitabilityReport.php), [`ProductPerformanceReport.php`](../../app/Domain/Metrics/Reports/ProductPerformanceReport.php), [`FoodCostWasteReport.php`](../../app/Domain/Metrics/Reports/FoodCostWasteReport.php), [`ExpensesPurchasesReport.php`](../../app/Domain/Metrics/Reports/ExpensesPurchasesReport.php), [`ShiftsWeekdaysReport.php`](../../app/Domain/Metrics/Reports/ShiftsWeekdaysReport.php), [`BranchRankingReport.php`](../../app/Domain/Metrics/Reports/BranchRankingReport.php), [`TargetsVsActualReport.php`](../../app/Domain/Metrics/Reports/TargetsVsActualReport.php), [`DataHealthReport.php`](../../app/Domain/Metrics/Reports/DataHealthReport.php), [`OrderTypeSales.php`](../../app/Domain/Metrics/Reports/OrderTypeSales.php) |
| `app/Domain/Targets/` | Target allocation models, weekday weight distribution from history, achievement calculation | [`TargetDistributor.php`](../../app/Domain/Targets/TargetDistributor.php), [`TargetAchievement.php`](../../app/Domain/Targets/TargetAchievement.php), [`TargetObserver.php`](../../app/Domain/Targets/TargetObserver.php) |
| `app/Domain/Tenancy/` | Multi-tenancy access resolution, global query scopes, pairing code redemption | [`AccessibleBranches.php`](../../app/Domain/Tenancy/AccessibleBranches.php), [`OrganizationScope.php`](../../app/Domain/Tenancy/Scopes/OrganizationScope.php), [`BelongsToOrganization.php`](../../app/Domain/Tenancy/Concerns/BelongsToOrganization.php), [`PairingCodeService.php`](../../app/Domain/Tenancy/PairingCodeService.php) |
| `app/Filament/Admin/` | Platform operator control panel (`/admin`) for cross-tenant infrastructure | [`AdminPanelProvider.php`](../../app/Providers/Filament/AdminPanelProvider.php), [`OrganizationResource.php`](../../app/Filament/Admin/Resources/OrganizationResource.php), [`RestaurantResource.php`](../../app/Filament/Admin/Resources/RestaurantResource.php), [`BranchResource.php`](../../app/Filament/Admin/Resources/BranchResource.php), [`UserResource.php`](../../app/Filament/Admin/Resources/UserResource.php) |
| `app/Filament/App/` | Tenant analytics and management control panel (`/app`) | [`AppPanelProvider.php`](../../app/Providers/Filament/AppPanelProvider.php), [`ReportPage.php`](../../app/Filament/App/Pages/Reports/ReportPage.php), [`Overview.php`](../../app/Filament/App/Pages/Reports/Overview.php), [`SalesComparison.php`](../../app/Filament/App/Pages/Reports/SalesComparison.php), and widgets under `app/Filament/App/Widgets/Reports/` |
| `app/Http/Controllers/Api/V1/` | Ingestion HTTP endpoints (POS contract implementation) | [`PairController.php`](../../app/Http/Controllers/Api/V1/PairController.php), [`IngestBatchController.php`](../../app/Http/Controllers/Api/V1/IngestBatchController.php), [`HeartbeatController.php`](../../app/Http/Controllers/Api/V1/HeartbeatController.php) |
| `app/Jobs/` | Asynchronous queue jobs for batch processing, recomputation, and file pruning | [`ProcessIngestBatch.php`](../../app/Jobs/ProcessIngestBatch.php), [`RecomputeBranchDay.php`](../../app/Jobs/RecomputeBranchDay.php), [`DeleteBranchFiles.php`](../../app/Jobs/DeleteBranchFiles.php) |
| `app/Models/` | Core organizational and ingest state models | [`Organization.php`](../../app/Models/Organization.php), [`Restaurant.php`](../../app/Models/Restaurant.php), [`Branch.php`](../../app/Models/Branch.php), [`BranchSet.php`](../../app/Models/BranchSet.php), [`IngestBatch.php`](../../app/Models/IngestBatch.php), [`IngestDirtyDate.php`](../../app/Models/IngestDirtyDate.php), [`IngestRejection.php`](../../app/Models/IngestRejection.php), [`BranchCommand.php`](../../app/Models/BranchCommand.php), [`ReconciliationIssue.php`](../../app/Models/ReconciliationIssue.php) |
| `app/Models/Warehouse/` | Mirrored raw POS documents (isolated by `branch_id` and `uuid`) | [`WhOrder.php`](../../app/Models/Warehouse/WhOrder.php), [`WhOrderItem.php`](../../app/Models/Warehouse/WhOrderItem.php), [`WhPayment.php`](../../app/Models/Warehouse/WhPayment.php), [`WhOrderReturn.php`](../../app/Models/Warehouse/WhOrderReturn.php), [`WhShift.php`](../../app/Models/Warehouse/WhShift.php), [`WhExpense.php`](../../app/Models/Warehouse/WhExpense.php), [`WhPurchase.php`](../../app/Models/Warehouse/WhPurchase.php), [`WhWaste.php`](../../app/Models/Warehouse/WhWaste.php), [`WhStocktaking.php`](../../app/Models/Warehouse/WhStocktaking.php), [`WhInventoryDay.php`](../../app/Models/Warehouse/WhInventoryDay.php), [`WhDaySummary.php`](../../app/Models/Warehouse/WhDaySummary.php), Dimensions: `DimProduct`, `DimCategory`, `DimExpenseType`, etc. |
| `app/Models/Aggregates/` | Precomputed read-model tables used exclusively by reports | [`AggBranchDaily.php`](../../app/Models/Aggregates/AggBranchDaily.php), [`AggProductDaily.php`](../../app/Models/Aggregates/AggProductDaily.php), [`AggShift.php`](../../app/Models/Aggregates/AggShift.php), [`AggBranchHourly.php`](../../app/Models/Aggregates/AggBranchHourly.php), [`AggExpenseDaily.php`](../../app/Models/Aggregates/AggExpenseDaily.php), [`AggPurchaseDaily.php`](../../app/Models/Aggregates/AggPurchaseDaily.php) |
| `database/migrations/` | Schema definition migrations (sequential version control) | Migrations defining ingest control, warehouse, dimensions, aggregates, targets, dirty date fencing, cascading deletion |

---

## 3. Technology Stack & Dependencies

### Platform Dependencies ([`composer.json`](../../composer.json))
- **PHP**: `^8.3`
- **Framework**: `laravel/framework: ^13.17`
- **Admin Panel**: `filament/filament: ^5.11`
- **Security & RBAC**: `spatie/laravel-permission: ^8.3`, `laravel/fortify: ^1.37.2`, `laravel/sanctum: ^4.0`
- **Frontend Inertia**: `inertiajs/inertia-laravel: ^3.0`
- **Testing Framework**: `phpunit/phpunit: ^12.5.23` configured in [`phpunit.xml`](../../phpunit.xml) against **MySQL** (`DB_DATABASE=platform_testing`).

### Paired POS System Dependencies (`larament:composer.json`)
The paired POS repository uses its own distinct dependency footprint:
- **PHP**: `^8.2`
- **Framework**: `laravel/framework: ^11.9`
- **Admin Panel**: `filament/filament: ~4.0`
- **Frontend Inertia**: `inertiajs/inertia-laravel: ^2.0`
- **Permissions**: `spatie/laravel-permission: 6.25`
- **Testing Framework**: `pestphp/pest: ^3.7` (runs tests using SQLite in-memory).

### Storage & Local Disk Root ([`config/filesystems.php`](../../config/filesystems.php))
- The application stores raw incoming payloads using `Storage::disk('local')`.
- In `config/filesystems.php`, the `'local'` disk driver root is explicitly configured as `storage_path('app/private')`.
- Therefore, raw batches reside physically on the host filesystem under `storage/app/private/ingest/{org}/{branch}/{Y}/{m}/{batch_uuid}.{json|json.gz}`.

### Frontend Stack (Filament Theme & Assets)
- **Vite & Tailwind CSS**: Tailwind v4 (`resources/css/filament/app/theme.css`).
- **Chart.js**: Rendered via Filament Chart Widgets (`BaseReportChartWidget`) with custom JavaScript hooks via `Filament\Support\RawJs` for percentage-formatted tooltips and axes.
- **Language & Typography**: Right-to-Left (RTL) Arabic typography (`dir="rtl"`, Cairo font, locale `ar`).
