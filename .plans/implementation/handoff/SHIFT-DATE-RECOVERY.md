# First-sync historical business dates — 2026-10-30

Confirmed root cause on the local POS: MariaDB expanded shifts.start_at to
TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP. The
sync UUID/date backfills overwrote historical starts and then copied their current
date into business_date. The POS connector and platform handler both transmitted
and stored that incorrect source date faithfully.

POS fix: `008a750` on feature/platform-connector. New migration
2026_10_31_235959_preserve_shift_start_at removes implicit ON UPDATE before sync
backfills on fresh installations and as a pending migration on existing ones.
An isolated real MariaDB reproduction confirmed subsequent UUID/date updates
preserve historical starts.

The human explicitly authorized restoration from historical created_at because
the configured legacy database was unavailable. The migration was applied locally
and platform:repair-shift-dates --apply --from-created-at restored 89 affected
shifts. It saved their original rows under the POS private local storage disk:
repairs/shift-dates/08873617-9200-42e7-bf08-ba21720c2069.json.

Corrected starts/business dates are requeued with newer outbox IDs for shifts,
orders, returns and expenses. Day summaries for both previous and corrected dates
are requeued too. Existing warehouse version/dirty-date handling updates source
rows and rebuilds old and new dates without deleting the branch.

The local POS now has 90 distinct business dates from August 1 to October 30 and
no shift ends before its start. See larament/docs/platform-shift-date-recovery.md
for preview/apply instructions on other installations. Do not assume created_at
is trustworthy on another dataset without checking its history.

Local replay verification: the platform now has all 90 distinct shift business
dates, zero reversed shift timestamps, and zero order/shift business-date
mismatches. All non-error repair outbox rows through ID 34149 were sent. Twenty-two
distinct completed POS orders have no items and remain rejected by the existing
items validation rule; their source records were not fabricated or modified.
Reconciliation can request further automatic date resends (bounded by its existing
three-attempt policy), so the total pending outbox count may include those resends
even after the date-repair batch has completed.

Checks: 187 sync tests passed; focused repair/day-close/connection UI checks passed
(20 tests), with the repair tests rerun after final adjustments. Targeted Pint and
PHPStan pass. The MariaDB regression probe used and removed a dedicated database.
