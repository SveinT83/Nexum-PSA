# Feature Slice: Calendar API 05-provider-operations

Status: Done On Dev
Date: 2026-10-24
Parent: ../rfc/2026-10-23-calendar-full-api-parity.md
Owner: Codex
Human review: HR-2026-10-23-CALENDAR-API

## Goal
Calendar-only Nextcloud status/mapping/health/sync/activation/reconciliation preview and apply.

## User-Visible Behavior
Official typed API covers NC-01 through NC-07 in the approved gap matrix with browser-equivalent authorization.

## Scope
Calendar-only Nextcloud status/mapping/health/sync/activation/reconciliation preview and apply. Module routes, shared authoritative actions, schemas and relevant tests.

## Out Of Scope
Main/production deployment, real provider activation, future dispatch and Mail invitation features.

## Data Touched
Calendar and related UserManagement/Nextcloud records only as specified by the parent RFC.
Provider writes use guarded explicit commands; automated tests use fakes.

## Permissions
Token scope plus active actor, existing module permission and object/field authorization.
Read endpoints do not initialize permissions or calendars.

## Tests
Meaningful feature allow/deny/privacy/validation/replay tests for the affected contracts.
Run on authoritative Dev with isolated SQLite.

## Documentation
Update parent RFC, matrix, TODO, human-review and source Knowledge/API documentation.
Final documentation slice publishes and reads back affected BookStack articles.

## Done Criteria
- [x] Implement all assigned matrix rows with honest typed contracts.
- [x] Relevant tests pass on Dev; preserve existing consumers.
- [x] Documentation and human-review state match verified behavior.
\nVerification: 47 Nextcloud tests / 205 assertions pass, including 8 new API regressions and 39 automatic-sync tests. Typed contract/catalog publication and additional final negative cases remain in slice 06. Faked provider tests prove exact conditional PUT/read-back, stale preview denial, unknown delivery retained then observed/bound with only one PUT, sanitized status and calendar-only attempts. No real provider writes or activation performed.\n
Final integrated evidence: ../plans/2026-10-24-calendar-api-verification.md. Human acceptance: HR-2026-10-23-CALENDAR-API is Reviewed by Svein Tore, 2026-10-24 ("Godkjent. Merge"). Explicit approval clears the API merge gate; no individual scenario execution or provider activation is inferred.
