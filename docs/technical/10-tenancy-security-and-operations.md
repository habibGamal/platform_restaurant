# 10. Tenancy, Security, Operations, and Plan vs Implementation Matrix

This document details the multi-tenant isolation model, role-based access control (RBAC), branch access constraints, cascading deletion mechanics, operational runbooks, troubleshooting procedures, and an explicit matrix contrasting implemented features against unimplemented plans.

---

## 1. Multi-Tenancy Architecture

The application implements a single-database multi-tenant model partitioned by `organization_id`:

```
+-------------------------------------------------------------------------+
|                         Organization (Tenant)                           |
|                  Table: organizations (slug, plan, timezone)            |
+-------------------------------------------------------------------------+
       |                                                    |
       v                                                    v
+-----------------------------+              +----------------------------+
| Restaurants                 |              | Users (Tenant Staff)       |
| Table: restaurants          |              | Table: users               |
+-----------------------------+              +----------------------------+
       |                                                    |
       v                                                    |
+-----------------------------+                             |
| Branches                    |                             |
| Table: branches             |<----------------------------+
+-----------------------------+       Assigned via: user_branch_access
       |
       +---> Ingestion: ingest_batches, ingest_dirty_dates, ingest_rejections
       |
       +---> Warehouse: wh_orders, wh_shifts, wh_expenses, wh_purchases, etc.
       |
       +---> Dimensions: dim_products, dim_expense_types, dim_recipes, etc.
       |
       `---> Aggregates: agg_branch_daily, agg_product_daily, agg_shift, etc.
```

### 1. Global Scope (`OrganizationScope`)
Tenant models use [`BelongsToOrganization`](../../app/Domain/Tenancy/Concerns/BelongsToOrganization.php).
- Automatically applies [`OrganizationScope`](../../app/Domain/Tenancy/Scopes/OrganizationScope.php), restricting all queries to:
  ```sql
  WHERE organization_id = auth()->user()->organization_id
  ```
- Automatically injects `organization_id` upon model creation.

### 2. Platform Operator Isolation
Platform operators manage infrastructure across tenants via the Admin panel (`/admin`):
- Users with `organization_id = null` and the `operator` role bypass tenant scopes.
- Tenant managers (`/app`) are strictly forbidden from accessing `/admin`.

---

## 2. Roles & Permissions Matrix

Implemented using `spatie/laravel-permission` ([`RolesAndPermissionsSeeder`](../../database/seeders/RolesAndPermissionsSeeder.php)):

| Permission Name | Description | Operator | Org Owner (`org_owner`) | Org Analyst (`org_analyst`) | Branch Manager (`branch_manager`) |
|---|---|:---:|:---:|:---:|:---:|
| `analytics.view` | View `/app` report pages and widgets | - | Yes | Yes | Yes |
| `analytics.view_group` | View group-wide averages ("متوسط المجموعة") | - | Yes | Yes | - |
| `analytics.view_other_branch_names` | View unmasked names of sibling branches | - | Yes | Yes | - |
| `ai.view` | Access AI conversational assistant features | - | Yes | Yes | - |
| `targets.manage` | Create, edit, and bulk-allocate branch targets | - | Yes | - | - |
| `users.manage` | Invite and assign roles and branch access | - | Yes | - | - |
| `alerts.manage` | Configure system alerts and notification thresholds | - | Yes | - | - |
| `alerts.acknowledge` | Mark active operational alerts as acknowledged | - | Yes | - | Yes |
| `branches.manage` | Create branches, generate pairing codes, trigger resync | Yes | Yes | - | - |
| `export` | Export reports and tables to Excel / CSV | - | Yes | Yes | Yes |

---

## 3. Branch Access Control ([`AccessibleBranches`](../../app/Domain/Tenancy/AccessibleBranches.php))

Branch filtering strictly enforces user roles:

1. **`org_owner` & `org_analyst`**:
   - Access is granted to **all branches** belonging to their `organization_id`.
2. **`branch_manager`**:
   - Access is restricted strictly to branches assigned in the pivot table `user_branch_access` (`branch_id`, `user_id`).
   - If a manager attempts to inject unauthorized branch IDs into query parameters, [`MetricsQuery::branchIds()`](../../app/Domain/Metrics/MetricsQuery.php) throws `AuthorizationException`.
3. **Other / Unassigned Users**:
   - Yields an empty query builder (`whereRaw('1 = 0')`).

---

## 4. Cascading Branch Deletion & Secure Purge

When a branch is permanently decommissioned, all associated transactional and storage data is eliminated across the entire stack:

1. **Pessimistic Deletion Lock**: [`Branch::delete()`](../../app/Models/Branch.php) locks the branch record before deletion.
2. **Token Revocation**: `Branch::booted()` triggers `$branch->revokeTokens()`, immediately invalidating all Sanctum tokens.
3. **Database Cascading**: Migration [`2026_11_02_000009_cascade_branch_owned_data.php`](../../database/migrations/2026_11_02_000009_cascade_branch_owned_data.php) enforces foreign key cascading across **28 warehouse, dimension, aggregate, and control tables**:
   - `wh_orders`, `wh_order_items`, `wh_payments`, `wh_order_returns`, `wh_order_return_items`, `wh_refunds`, `wh_shifts`, `wh_expenses`, `wh_purchases`, `wh_purchase_items`, `wh_wastes`, `wh_waste_items`, `wh_stocktakings`, `wh_stocktaking_items`, `wh_inventory_days`, `wh_inventory_day_rows`, `wh_day_summaries`.
   - `dim_products`, `dim_recipes`, `dim_categories`, `dim_suppliers`, `dim_expense_types`, `dim_staff`.
   - `agg_branch_daily`, `agg_product_daily`, `agg_shift`, `agg_branch_hourly`, `agg_expense_daily`, `agg_purchase_daily`.
   - `ingest_batches`, `ingest_dirty_dates`, `ingest_rejections`, `reconciliation_issues`, `branch_commands`, `branch_pairing_codes`, `user_branch_access`, `branch_set_members`, `targets`.
4. **Storage Purge**: `Branch::deleted()` dispatches [`DeleteBranchFiles`](../../app/Jobs/DeleteBranchFiles.php) on queue `ingest`:
   - Deletes `ingest/{organization_id}/{branch_id}` from the `local` storage disk (physically located at `storage/app/private/ingest/...`).

---

## 5. Operations & Troubleshooting Runbook

### Key Management Commands

```bash
# 1. Recover uncompleted or stalled ingestion batches and dirty dates
php artisan ingest:recover --limit=100 --stale-seconds=90

# 2. Run recovery synchronously (useful for debugging in local/staging)
php artisan ingest:recover --sync

# 3. Completely rebuild aggregate metrics for a branch and date range
php artisan metrics:rebuild --branch=<branch_id> --from=YYYY-MM-DD --to=YYYY-MM-DD

# 4. View queue worker status and failed jobs
php artisan queue:failed
# Retry a reviewed failed job by ID; do not retry every failure blindly.
php artisan queue:retry <failed-job-id>
```

### Operational Troubleshooting Scenarios

| Issue Symptom | Root Cause | Remediation Procedure |
|---|---|---|
| POS receives `HTTP 409 Conflict` | Batch was previously received and accepted. | Normal idempotent behavior. POS updates local outbox to `sent_at = now()` based on the returned response body. |
| POS receives `HTTP 401 Unauthorized` | Ingestion token was rotated or branch was deleted. | POS automatically marks connection `revoked`. Admin must issue a new pairing code (15-min validity) and re-pair. |
| POS receives `HTTP 403 Forbidden` | The branch is suspended or the batch's branch UUID does not match the authenticated branch. | Check branch status and the batch UUID against the authenticated branch; investigate before changing status. |
| Reports show zero for recently completed orders | Batches accepted, but aggregate recomputations have not finished. | Verify queue workers are running on both `ingest` and `aggregate` queues. Check `ingest_dirty_dates` for stalled records; execute `php artisan ingest:recover`. |
| Branch ranking shows branch in "Excluded" table | Incomplete data: branch has missing past days or unreconciled day summaries. | Open `/app/reports/data-health`, review "Completeness Calendar", and inspect open reconciliation issues. |

---

## 6. Stack & Repository Comparison

| Attribute | Central Platform (`platform-restaurants`) | Decentralized POS (`larament`) |
|---|---|---|
| **PHP Runtime** | `^8.3` | `^8.2` |
| **Laravel Framework** | `^13.17` | `^11.9` |
| **Filament Panel** | `^5.11` | `~4.0` |
| **Test Framework** | PHPUnit `^12.5.23` | Pest `^3.7` |
| **Test Database** | **MySQL** (`platform_testing` configured in [`phpunit.xml`](../../phpunit.xml)) | **SQLite** in-memory (`:memory:`) |
| **Frontend Stack** | Inertia React `^3.0`, Tailwind v4 | Inertia React `^2.0`, Tailwind v4 |
| **Storage Root** | Local disk at `storage/app/private` | Local disk at `storage/app` |

---

## 7. Implemented Behavior vs Assumptions & Unimplemented Plans

The repository contains both fully implemented production code and architectural planning documents under `.plans/`. To ensure accurate operational documentation, the table below delineates verified implementation from assumptions and future plans:

| Feature / Module | Status | Actual Source Implementation | Planning Doc / Assumption Reference | Notes & Differences |
|---|---|---|---|---|
| **Reports 01 to 10** | **Fully Implemented** | `app/Domain/Metrics/Reports/*`, `app/Filament/App/Pages/Reports/*` | `.plans/reports/01` to `10` | Implemented with Filament v5, interactive `ArrayTableRecords` sorting/searching, and real-time topbar filter form. Report 10 route is `/app/reports/data-health`. |
| **Reports 11 to 18 (AI Modules)** | **Unimplemented Plan** | None in `app/Domain/Metrics/Reports/` | `.plans/reports/11-ai-growth-analysis.md` through `18-ai-alerts.md` | Purely conceptual designs. No report pages or domain calculators exist in codebase. |
| **Laravel AI Assistant** | **Work in progress** | `app/Ai/`, `app/Domain/Assistant/`, `app/Filament/App/Pages/Assistant.php` | `.plans/ai-agent-plan.md` | A page and tools exist in the changing working tree; see [assistant inventory](11-assistant-work-in-progress.md) for limits. Do not infer deployment readiness. |
| **Order Type Sales Breakdown** | **Fully Implemented (Uncommitted)** | `app/Domain/Metrics/Reports/OrderTypeSales.php`, `app/Filament/App/Widgets/Reports/SalesComparison/OrderTypeSalesWidget.php` | Addressed in Arabic guide `docs/data-flow-and-reports.md` | Summarizes completed orders by channel before returns. |
| **Bounded Reconciliation Protocol** | **Fully Implemented** | `app/Domain/Metrics/Aggregation/Reconciler.php`, `HeartbeatController.php` | `.plans/reports/10-branch-sync-health.md` | Bounded to 3 automated retry attempts; conditional on `wh_day_summaries` presence and POS network connectivity; cannot resolve schema ingress rejections. |
| **Like-for-Like (LFL) Scoping** | **Fully Implemented** | `MetricsQuery::branchIds()` | `.plans/reports/00-conventions.md §2` | Verifies data presence in `agg_branch_daily` across both periods. |
| **364-Day Year-over-Year Shift** | **Fully Implemented** | `PeriodResolver::comparison()` | `.plans/reports/00-conventions.md §2` | Shifts exactly 364 days (52 weeks) to preserve day-of-week alignment. |
| **POS Outbound Sync Engine** | **Fully Implemented** | `larament:app/Sync/*` | `.plans/implementation/briefs/pos-*` | POS features outbox observers, gzip compression, retry backoff (1..60 min), and heartbeat command handling. |
