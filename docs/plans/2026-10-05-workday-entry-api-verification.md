# Workday Date Entry API Verification

Date: 2026-10-05. Authoritative checkout: /var/Projects/tdPSA, branch Dev.
Owner: Codex. Product owner/reviewer: Svein Tore.

## Approval and scope

Svein Tore explicitly approved the calendar/modal UX on 2026-10-05 and requested complete API
support for a future Nexum PSA MCP server. That UI approval is recorded in HR-2026-10-01-WORKDAY.
The API follow-up is covered by the approved Workday RFC and Slice 09 API parity direction.

Status: Done On Dev. No MCP server, gateway, employee impersonation, production rollout or
credential expansion was introduced. Remaining explicit combined-pilot checks stay In Review.

## What changed

Added GET /api/v1/workdays/{work_date}/entry, operationId workdayEntry.

It reads the selected employee date without creating a record. The response contains:

- persisted own day or null and its optimistic version (0 before first save);
- original day timezone or the profile work-plan timezone for an unregistered date;
- effective planned intervals/minutes after availability and explicit absence;
- free planned ranges after current work and adjacent retained reservations;
- an optional first free period of up to 60 minutes, with explicit timezone offset;
- current edit capability and whether the confirmed day needs a correction first.

It reuses WorkdayTimeline, so the API and calendar calculate the same suggestions. Plan data
is separate from actual time. The response contains no UI geometry or absence reasons.
Requires workdays.read and workday.view_own, plus existing active-human/feature checks.
No query/body identity override is accepted; wrong input returns 422 and expired dates 404.
The original retention deadline calculation is shared with draft creation, without changing it.
The existing ability description now mentions effective plan/free-time reads; no scope was added.

The existing PUT draft action already supports create, add, edit and removal of exact-minute
intervals and explicit breaks. It replaces the complete interval/break lists under version and
idempotency checks. The consumer guide now documents preservation, writable fields, sorted
version-specific indexes, conflict handling and persisted read-back. Preview/confirmation,
traceable correction, source allocation, absence, reminders, oversight and Task conversion
remain available under their existing scopes.

## Tests on Dev

1. WorkdayEntryApiTest and ApiContractTest: 13 passed, 432 assertions.
   Personal bearer authentication goes through Laravel's HTTP pipeline; no Sanctum actingAs
   shortcut or shared employee identity. Cases cover:
   - context parity with the calendar and no read-side record creation;
   - 45-minute creation, adding 90 minutes, editing and removing one block;
   - preservation of other intervals and breaks, precise actual-minute totals;
   - duplicate retry, reused-key conflict, stale version and overlap rejection;
   - confirmed correction and fixed original timezone;
   - absence/adjacent overnight reservations and repeated clock-hour offsets;
   - foreign ownership, read-only capability, revoked permissions, wildcard coordinator denial;
   - malformed/expired/disabled contexts and no retention extension;
   - all 35 related operations reject anonymous/unscoped callers and match generated
     OpenAPI operation IDs, bearer security, exact scopes and success schemas.
   The first command wrapper reported a shell-exit quoting error after the successful test
   summary; all PHP tests completed successfully. The subsequent wrapper was corrected.
2. WorkdayTimelineTest, WorkdayEntryTest, ManualWorkdayTest and RetentionTest:
   60 passed, 475 assertions. Calendar/modal rendering, minute writes, version/confirmation
   behavior, privacy and three-year retention regressions passed.

Total: 73 tests / 907 assertions. No failed or skipped PHP test in these runs.
Syntax checks and scoped git diff --check passed. New files have mode 0664.
Tests use isolated synthetic records; no live employee content or token grants were changed.

## Published read-back

- php artisan route:clear completed on Dev.
- php artisan l5-swagger:generate completed on Dev.
- Trusted HTTPS GET https://dev.nexumpsa.eu/docs returned 200.
- 35 related operations are published across Workday, UserManagement and Calendar.
- workdayEntry has workdays.read, bearerAuth, and the typed response schema.
- Required fields, nullable saved day/suggestion and closed context schema were checked.
- Published entry operation matches the generated file.
- Public unauthenticated GET /api/v1/workdays/2026-10-05/entry returned 401.
- Published OpenAPI SHA-256:
  50ee68a2d221c935a510361a1f8ead7ae0c9ec748eb7f526e6506749454e62e5

Authenticated writes were verified in Laravel HTTP tests, not against live employee records.
This does not claim an external MCP session or production behavior.

## Files

Runtime/API:
- app/Modules/Workday/routes.php
- app/Modules/Workday/Controllers/Tech/WorkdayController.php
- app/Modules/Workday/Queries/ReadWorkdayEntry.php (new)
- app/Modules/Workday/Queries/WorkdayTimeline.php
- app/Modules/Workday/Support/WorkdayTime.php
- app/Modules/Workday/Actions/MutateWorkday.php
- app/Modules/Workday/Resources/Api/V1/WorkdayEntryOpenApi.php (new)
- app/Modules/Integration/Support/ApiAbilityCatalog.php (description only)
- storage/api-docs/api-docs.json (generated)

Tests:
- app/Modules/Workday/Tests/Feature/WorkdayEntryApiTest.php (new)
- app/Modules/Workday/Tests/Feature/ApiContractTest.php

Documentation: Workday API Knowledge, docs/integrations/workday-api-consumer.md, approved RFC/ADR,
Slices 02/09, implementation plan, pilot review guide, TODO, human-review register and this note.
All changes compared against the turn baseline are within this scope; unrelated Dev changes remain.

## Deployment and remaining review

No migration, new grant, asset build, dependency, queue restart or scheduler change is required.
Route cache refresh and OpenAPI generation were performed on Dev; repeat through the normal
release procedure when Svein later promotes the changes. No commit, push, Main or production action.

HR-2026-10-01-WORKDAY records Svein's calendar/modal UX approval. It remains In Review for
the separately listed API identity, device, delivery and operational retention/restore checks
before Main/production. The approved UI does not need another identical approval request.
A future MCP integration must preserve per-employee identity, obtain explicit confirmation,
respect version/idempotency rules and perform its own connection/read-back testing.
