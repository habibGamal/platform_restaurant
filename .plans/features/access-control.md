# Feature — Central Management & Permissions (الإدارة المركزية والصلاحيات)

**Requirement:** §4 — central account for top management showing all restaurants; separate
permissions for each restaurant manager; compare selected restaurants or the whole group.

## Hierarchy
`Organization (group) → Restaurant (brand) → Branch (one POS)`.
A user belongs to exactly one organization (platform operators have none).

## Roles (spatie/laravel-permission, team = organization)

| Role | Sees | Can manage |
|---|---|---|
| `operator` | every organization (operator panel only) | orgs, plans, branches, pairing, tokens, ingestion |
| `org_owner` | all branches of their org, group totals | users & access, targets, alert rules, recipients, scoring profile, expense mapping, catalog mapping, branch sets |
| `org_analyst` | all branches of their org, group totals | own saved branch sets only |
| `branch_manager` | only branches in `user_branch_access`; group figures only as anonymized benchmark (avg/median/rank) | acknowledge alerts for their branches |

Permissions (granular, assigned to roles): `analytics.view`, `analytics.view_group`, `analytics.view_other_branch_names`,
`ai.view`, `targets.manage`, `users.manage`, `alerts.manage`, `alerts.acknowledge`, `branches.manage`, `export`.
Org owner may grant `analytics.view_other_branch_names` to managers (used by reports 02, 03, 07).

## Enforcement (one place)
- `AccessibleBranches::for(User): Collection<int>` — owner/analyst: all org branches; manager: `user_branch_access`.
- `MetricsQuery` always intersects requested branch ids with that set; requesting an inaccessible id → 403 (not silently dropped, so bugs surface).
- `OrganizationScope` global scope on all tenant models.
- Group-level data (group totals, other branch names) is never computed for managers — report services return it as `null`; it is not merely hidden in the Blade view.
- Pest arch test: classes under `App\Filament\App` may not use `DB::table('agg_*'|'wh_*')` directly — only `MetricsQuery` / report services.

## Comparing selected branches
- Branch picker (conventions §2) with: all, by restaurant, saved set, manual selection.
- `branch_sets` + members: org-wide sets (owner) or personal (any user). Selection is in the URL (`?branches=3,7,9` or `?set=12`) so views can be shared.

## Onboarding & users (app panel, Management group)
- Owner invites users by email (signed invitation link, 72 h) with role + branches; Fortify public registration disabled.
- 2FA required for `org_owner` and `operator` (Fortify 2FA already installed with the starter kit).
- Audit log (`activity_log`): role/branch access changes, token issue/revoke, target edits.

## Acceptance criteria
- Manager of branch A requesting `?branches=A,B` → 403.
- Manager's dashboard JSON has no key containing B's name or figures.
- Analyst can create a personal set; cannot edit targets (403).
- Removing a manager's access to A takes effect on the next request (no cached access).
