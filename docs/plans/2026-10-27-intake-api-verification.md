# Intake API Verification

Date: 2026-10-27. Authoritative implementation: Dev /var/Projects/tdPSA.
Status: Done On Dev; scoped Main promotion explicitly authorized and being finalized.
Production is not deployed. HR-2026-10-27-INTAKE-API remains Pending.

## Coverage And Tests

All 17 browser rows, embedded form/field settings, private uploads, public submissions,
review/link and Sales/Ticket/Task routing map to 23 typed HTTP operations / 38 schemas.
All 23 operationIds have successful HTTP responses validated against their schemas.
Exact runtime/Swagger/scopes/path parameters and references match.

- Dev affected suite: 912 passed / 48,851 assertions, zero failures/skips.
- Initial isolated Main candidate: 760 passed / 9,773 assertions, zero failures/skips.
- Final focused Intake suite: 70 passed / 1,180 assertions on both Dev and candidate.
- PHP syntax: 39 changed PHP files pass. Scoped Pint: 39 Intake PHP files pass.
- Git scoped whitespace checks pass. Original native baseline: 21 / 168, historical only.

Affected verification covers Intake, Knowledge source sync, Signal, Sales, Clients,
Contact, Ticket, Task and production-safe Swagger scanning. Dev contains additional
independent work; the scoped Main candidate excludes that work. The final promotion
record documents any base refresh and its additional verification.

## Native Runtime And Side Effects

Only additive 2026_10_27_040000_create_intake_api_commands.php ran on Dev.
Trusted HTTPS verifies 401 without a token, permitted workspace/form/context/lookup/
submission reads, native MySQL draft creation and exact saved read-back, two concurrent
same-key requests yielding one form, encrypted receipt replay, guarded update and
public draft denial. The exact temporary draft/fields/commands and token were removed.

Tests verify all ten field types; hidden conditions; snapshots; private nested upload/
download; every review outcome and five link types; route defaults and one target;
stale state/form policy; revoked token/actor rights; interrupted claims; public storage
failure without false acceptance; Signal failure without duplicate submission;
Clients numbering and no inferred billing address; default-off provider execution;
stable provider request key and honest unknown/reconciliation results.
No customer email, invitation, live provider creation or automation switch was enabled.

## Handbook

Knowledge source sync verified both Intake articles. Dev has no configured BookStack
integration, so the supported Tronder Data MCP published the two source bodies together
in existing BookStack page 553 (book 339 / chapter 552). Fresh read-back matched the
exact combined Markdown; page/chapter inherit existing access, no permission was changed.
An MCP string-pattern defect prevented a normal standalone title; its temporary page
651 was removed and read back as absent. No duplicate article remains.
Native automatic BookStack linkage/configuration is an independent operator follow-up;
handbook content is updated. Employee OpenWebUI retrieval is not claimed.

## Promotion, Deployment And Recovery

Main merge was explicitly authorized by Svein Tore: "Godkjenner og merge til main".
This does not mark any manual checklist scenario Reviewed and is not production
deployment authorization. Preserve the older Reviewed HR-2026-08-11-001 history.

For later production deployment, use the normal release procedure plus:
1. Deploy the scoped code/autoload changes.
2. php artisan migrate --path=database/migrations/2026_10_27_040000_create_intake_api_commands.php --force
3. Normal optimize/config cache handling and php artisan l5-swagger:generate.
4. Verify HTTPS routes, current human/token permissions, command storage and handbook availability.

No frontend build, new scheduler/worker activation or historical business backfill is
introduced. INTAKE_API_PROVIDER_CUSTOMER_CREATION_ENABLED remains false by default.
Rollback disables the new API entry points but retains APP_KEY, command claims,
submissions, private files and audit; reconcile unknown effects before another attempt.

## Dev Working-Copy Limitation

An initial Pint --dirty invocation selected unrelated dirty PHP files despite supplied
paths and was interrupted. It may have normalized unrelated Dev formatting. No dirty
work was bulk-reset. All later formatter calls were scoped to exact Intake paths.
The Main candidate reconstructs shared edits on Main and excludes all unrelated
formatting/work. Inspect the wider Dev diff before any later broad Dev commit.

## Human Review

HR-2026-10-27-INTAKE-API: Pending; no manual box was inferred from tests or merge
authorization. Svein Tore should perform form/builder, review/outcome/link, routing,
private upload, public JSON/multipart, permission/retry and handbook checks before
production deployment/release. The generated coverage JSON links operations to tests.

## Final Main Base Refresh

Candidate refreshed onto Notification Main 7d8087e4851f823fc87eaeff2cd46ff84eb8b4b6. All existing\ncontracts and Notification implementation are preserved. Refreshed Intake/Notification/\nKnowledge/production-Swagger suite passed 247 tests / 7,518 assertions, no failures/skips.\nFinal exact Intake contract recheck passed 1 / 158. The generated schema only adds\nIntake: 23 operations / 38 schemas; every pre-existing contract is unchanged.\nPR: https://github.com/SveinT83/Nexum-PSA/pull/340.\n