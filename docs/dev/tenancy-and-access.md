# Tenancy & Access Control

Platform Restaurants uses a single database partitioned by `organization_id` (`Organization -> Restaurant -> Branch`), with Spatie RBAC (`spatie/laravel-permission`) and strict branch-level scoping.

---

## 1. Tenant Isolation

- **Global Eloquent Scope (`OrganizationScope`):** Applied via the `BelongsToOrganization` trait on tenant models (`Restaurant`, `Branch`, `BranchSet`, `Target`, `IngestBatch`, `Wh*`, `Dim*`, `Agg*`, etc.). Automatically filters reads to `auth()->user()->organization_id` and populates `organization_id` on create.
- **Branch Access (`AccessibleBranches`):**
  - `org_owner` and `org_analyst`: all branches where `organization_id = $user->organization_id`.
  - `branch_manager`: only branches linked in `user_branch_access` (`$user->branches()`).
  - Unauthenticated or `organization_id === null` users: empty query (`whereRaw('1 = 0')`).
- **Query Enforcement (`MetricsQuery`):** Intersects requested `branchIds`, `branchSetId`, and `restaurantId` with `AccessibleBranches::ids($user)`. Passing an unauthorized branch ID throws `AuthorizationException` (403).

---

## 2. Roles & Permissions (`RolesAndPermissionsSeeder`)

| Permission | `operator` | `org_owner` | `org_analyst` | `branch_manager` |
|---|:---:|:---:|:---:|:---:|
| `analytics.view` | — | Yes | Yes | Yes |
| `analytics.view_group` | — | Yes | Yes | — |
| `analytics.view_other_branch_names` | — | Yes | Yes | — |
| `ai.view` | — | Yes | Yes | — |
| `ai.manage` | — | Yes | — | — |
| `targets.manage` | — | Yes | — | — |
| `users.manage` | — | Yes | — | — |
| `alerts.manage` | — | Yes | — | — |
| `alerts.acknowledge` | — | Yes | — | Yes |
| `branches.manage` | Yes | Yes | — | — |
| `export` | — | Yes | Yes | Yes |

### Panels & User Management
- **`/admin` (`AdminPanelProvider`):** Restricted to `operator` (`organization_id = null`). Operators provision organizations, restaurants, branches, and tenant users (`/admin/users`), where organization reassignment and deleting/demoting the last `org_owner` are blocked.
- **`/app` (`AppPanelProvider`):** Restricted to organization users (`org_owner`, `org_analyst`, `branch_manager`). `UserResource` prevents an `org_owner` from demoting themselves if they are the organization's sole owner.

---

## 3. Cascading Branch Deletion

Deleting a `Branch` from `/admin` or `/app` (`Branch::delete()`) executes inside a transaction with a row lock (`lockForUpdate()`):

1. **Token revocation:** `Branch::deleting` revokes all Sanctum tokens (`$branch->revokeTokens()`), preventing concurrent pairing/rotation races via the same row lock.
2. **Database cascade:** Migration `2026_11_02_000009_cascade_branch_owned_data.php` installs `ON DELETE CASCADE` foreign keys to `branches.id` across **29 tables** (`17 wh_*`, `6 dim_*`, and `6 agg_*`), complementing cascades on `ingest_batches`, `ingest_dirty_dates`, `ingest_recompute_fences`, `ingest_rejections`, `reconciliation_issues`, `branch_commands`, `branch_pairing_codes`, `user_branch_access`, `branch_set_members`, and `targets`.
3. **Storage purge:** `Branch::deleted` dispatches `DeleteBranchFiles` (`queue = ingest`, `afterCommit`, `tries = 5`, `backoff = 60`) to remove `storage/app/private/ingest/{organization_id}/{branch_id}`.
4. **Preserved shared data:** `Organization`, `Restaurant`, `User` accounts, `catalog_items`, and `branch_sets` remain intact.
