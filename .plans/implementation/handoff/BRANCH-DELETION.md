# Branch deletion and business dates

## Business date

The POS opens a trading day using its local date (Africa/Cairo). The currently open
inventory day's date is the business date; it remains unchanged across midnight.
A shift captures this date at creation. Orders, returns and expenses inherit the
associated shift's date. Purchases, purchase returns, waste and stocktaking capture
the open business date when closed. Inventory/day-summary documents carry their
explicit day date. The platform groups by these supplied dates.

Example: a day opened October 30 remains October 30 for orders placed at 02:00 on
October 31 until the POS trading day is closed and the next day is opened. Historical
POS migrations used shift start_at or document closed_at as a fallback; incorrect
imported timestamps can therefore also give historical rows incorrect business dates.

## Platform branch deletion

Run `php artisan migrate --force` when deploying this change. The new migration adds
branch foreign keys with ON DELETE CASCADE to all 29 warehouse, dimension and
aggregate tables. Existing rows belonging to already-deleted branches are removed
before these constraints are installed. Existing control-table cascades also remove
batches, rejection records, dirty dates, recompute fences, commands, pairing codes,
targets/daily targets and branch memberships/access.

Deleting a branch through either panel (single or bulk action) revokes its tokens
and deletes the branch transactionally. A DeleteBranchFiles job on the `ingest`
queue runs after commit to delete its `ingest/{organization_id}/{branch_id}` storage
directory. Failed file cleanup is retried up to five times; inspect failed jobs if
storage remains unavailable. Rollbacks preserve credentials, rows and files.

Organization users, shared catalog mappings, saved branch sets, the restaurant and
organization remain. Membership/access for the deleted branch is removed. Local
data on the remote POS remains there; its old token can no longer authenticate.

Stale recomputation jobs cannot recreate deleted branch records. Database foreign
keys also prevent late warehouse inserts. Restart workers after deployment.

Branch deletion and token issuance take the same branch row lock, preventing a
stale pairing/rotation operation from issuing tokens after deletion.

Validation: full platform suite passed (530 tests / 3828 assertions); after adding
the token-issuance guard, the related tenancy, ingestion, branch-management and
aggregation suites passed (246 tests / 1855 assertions). Targeted Pint and PHPStan
pass. Tests used platform_test_12; this migration has not been applied to the
developer database.
