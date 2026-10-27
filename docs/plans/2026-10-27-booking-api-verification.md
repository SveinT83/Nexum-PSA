# Booking API Verification

Date: 2026-10-27. Authoritative implementation: /var/Projects/tdPSA, branch Dev.
Status: Done On Dev; isolated Main candidate verified; requested Main merge authorized.
Production remains unchanged. Human-review checklist HR-2026-10-27-BOOKING-API is
Pending; Svein Tore explicitly authorized scoped Main promotion ahead of manual
scenario acceptance. No human checklist is marked Reviewed from automated tests.

## Implemented Coverage

All 13 supported browser rows (7 read, 3 write, 3 action), including embedded form/
context, hours, routing, timeline, decision and notice behavior, map to 17 official
HTTP operations: 4 anonymous/public and 13 protected, with 43 typed Swagger schemas.
Exact runtime/method/path/native permission/scope/operationId mapping is in the
coverage matrix JSON. Unsupported future products are not represented as fake API
operations. Four Booking abilities have route, catalog, typed contract and tests.

## Automated Evidence

- Dev: 189 distinct tests / 2622 assertions, zero unresolved failures/skips.
- Isolated Main candidate: 189 distinct tests / 2490 assertions, zero unresolved
  failures/skips. Booking subset: 35 tests, including the inventory contract test.
- Counts deduplicate JUnit test identities across the broad run and targeted repair
  runs; already-passing cases were not repeated without a changed file/failure.
- Booking/Calendar native and API, Knowledge articles, User work plan, account-owned
  notification mail, Integration API-key administration and production Swagger scan
  were exercised. Every successful Booking test call validates its real JSON result
  against the generated schema, including nested allowlists and required fields.
- Full Laravel suite was not run; this is the relevant cross-module regression set.
- Syntax/Pint/whitespace and final scoped manifest checks accompany Main promotion.

Initial verification found and fixed a missing test import and the confirmation
transaction callback return. Knowledge source counts were updated for the new API
guide; a dynamic Marketing source count remains compatible with another contributor's
new guide. Calendar's preference regression now uses a real bearer instead of a
fabricated token. Missing descriptions in five unrelated active Intake catalog
entries broke Dev API-key pages; descriptions were repaired on Dev without changing
abilities/rights. Those unrelated Intake entries are excluded from Main packaging.

## Native MySQL Evidence

Separate real database processes exercised the actual shared native decisions:
competing requests for one slot produced one confirmation and one blocked request;
confirmation versus decline produced one applied and one blocked terminal decision.
An ordinary Calendar model writer waited while the reservation mutex was held.
Native command uniqueness, ciphertext-at-rest and rollback were verified. No real
customer delivery occurred. All synthetic Booking/Calendar/service/unit fixtures
were removed. Two synthetic Dev users (IDs 6 and 7) were disabled and left without
roles/tokens because the existing append-only Email live-authority ledger prevents
physical identity deletion. Its immutable evidence was preserved, never bypassed.
Ordinary Calendar editing can intentionally create overlaps after a booking; the
serialization establishes truthful ordering, not a new universal no-overlap policy.
The global Calendar writer mutex introduces intentional write serialization; monitor
throughput if bulk Calendar import volume becomes material.

## HTTP And TLS Evidence

An isolated candidate server used a private SQLite database and mocked notifications.
All named SQL connections were confined to that database. A temporary localhost TLS
certificate was explicitly trusted by the test client with hostname validation;
certificate checking was never disabled. Twenty successful HTTPS calls covered all
17 operations, including setting create/update/toggle, public submission, confirm,
decline, receipt and exact current read-back. The isolated token/database/private
key and server were removed afterward. An initial test migration attempt retained
Telescope's named MySQL connection and failed trying to create an existing table;
that table was unchanged. All named connections were then isolated before bootstrap.

The ordinary trusted https://dev.nexumpsa.eu runtime independently returned HTTP 200
for public catalogue and scoped context/summary/settings/requests reads. /docs
returned HTTP 200 with all 17 Booking operations. The temporary read-only bearer was
revoked. Both ordinary Dev API effect gates remain false; production was not used.

## Handbook Publication

Authoritative Booking README, booking-api.md and booking-overview.md were updated.
knowledge:sync-docs --module=Booking processed one chapter/two articles, zero skips.
Dev has no configured native BookStack integration, so actual handbook publication
used the connected BookStack MCP. Existing Booking Overview page 543, chapter 540,
book 339, revision 3 exactly matches the authoritative overview including the entire
API reference guide. A separate page name was blocked by connector schema validation;
the temporary page 650 was soft-deleted and confirmed absent. No placeholder remains.
Page/chapter inheritance and existing Book role overrides were read back unchanged.
Staff Editor role /staff-internal retains page-view-all. No permissions were expanded.
Native pending_push metadata is not presented as a completed automated native sync.
OpenWebUI assistant retrieval and individual human inbox receipt were not claimed.

## Deploy And Recovery

Exact additive migration: database/migrations/2026_10_27_040000_create_booking_api_commands.php.
It has run on Dev only. Promote the code/Swagger and run only that reviewed migration
on a later authorized deployment, then normal optimize:clear and documentation sync.
No role grants, request backfill, frontend build or new scheduler/worker activation.
BOOKING_API_PUBLIC_SUBMISSIONS_ENABLED and BOOKING_API_STAFF_WRITES_ENABLED remain
false until separate operational activation after named manual review. Retain APP_KEY,
requests/audit and encrypted command/dispatch history on rollback. Unknown notification
outcomes require reconciliation, never blind resend. Production deployment, external
activation and named manual checks remain separate from the authorized Main merge.
