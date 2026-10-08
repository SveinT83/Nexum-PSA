# Approved customer synchronization Git delivery - 2026-10-08

Status: verified scoped main candidate; Reviewed by Svein Tore.
Authorization: Svein explicitly approved the delivered feature and requested issue closure and Git merge.
HR-2026-10-08-TRIPLETEX-CUSTOMERS and HR-2026-10-08-TRIPLETEX-PROFILES are Reviewed.
Individual manual scenario results are not invented from that overall approval.

## Scope

Shared Tripletex customer numbers, GUI-controlled customer writes, existing-customer selection,
initial Site/contact suggestions, ongoing two-way Billing Email and one bound Site address.
Tripletex wins conflicting fields; no ongoing primary-contact synchronization.
Includes the related five-digit suggestion boundary fix from completed Issue #297.
Issue #239 and Issue #297 were independently read back as already closed/completed before this merge.

The release candidate starts from origin/main at 1d25c8bb5680e13a4a12887c2c621d67648f07ee.
Only the approved customer files are included. Shared TODO/human-review documents include just
the relevant entries; unrelated Dev SSO, Vault, calendar and other work is excluded.
The authoritative Dev HEAD, working files and normal staging index are preserved.

## Verification

An isolated extraction of the exact candidate tree ran on Dev with SQLite :memory:, synthetic HTTP
and a test-only application key. Composer dependencies match main. Autoload path checks confirmed
that tests loaded the candidate code rather than the live Dev working copy.

The six complete test classes executed 145 tests / 846 assertions, exit 0, with no assertion failures:
TripletexCustomerNumberTest, TripletexCustomerProfileTest, TripletexTimeSyncTest,
TripletexConnectionTest, ClientTechTest and DataExchangeRuntimeTest.

PHPUnit initially reported the same phpdotenv warning in all 145 cases because the clean extraction
had no .env file. A blank test-only .env was added outside Git; no real configuration was copied.
Seven representative cases spanning all six classes then passed cleanly (56 assertions, 50.73s).
No application-code change or warning suppression was needed. The original run took 976.37s.
Three customer-picker JavaScript interaction checks also passed against candidate sources.
Scoped whitespace and source-integrity checks passed. The whole repository suite was not run.
Final changes after the tested source snapshot are approval/status documentation only.

## Delivery and rollout

Delivery is through a scoped pull request to main with an expected-head merge guard.
GitHub PR and merge history provide the immutable final commit references.
This request does not deploy Nexum production or run its migrations.

Later production rollout requires both customer link/profile migrations, normal view/opcache refresh,
and an external schedule:run runner every minute. No new queue worker or frontend build.
The existing GUI customer switch controls activation; no additional customer-write environment flag.
Retain encrypted link/profile delivery evidence on rollback.

Repository Knowledge is updated. BookStack publication/read-back is not claimed: the connected
search tool rejected valid nonempty queries with query-pattern schema validation before service access.
That concrete handbook follow-up remains recorded in TODO for rollout; permissions were not widened.
The public website handoff remains unpublished pending production verification.
