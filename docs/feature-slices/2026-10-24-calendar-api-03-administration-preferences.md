# Feature Slice: Calendar API 03-administration-preferences

Status: Done On Dev
Date: 2026-10-24
Parent: ../rfc/2026-10-23-calendar-full-api-parity.md
Owner: Codex
Human review: HR-2026-10-23-CALENDAR-API

## Goal
Shared administration actions, access audit/preview and own calendar preferences.

## User-Visible Behavior
Official typed API covers CAL-10 through CAL-13 in the approved gap matrix with browser-equivalent authorization.

## Scope
Shared administration actions, access audit/preview and own calendar preferences. Module routes, shared authoritative actions, schemas and relevant tests.

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

Verification: 41 tests / 361 assertions pass across Calendar parity, existing Calendar and UserPreferences. Shared administration actions preserve browser redirects; previews bind exact sharing/archive choices. Settings revisions and own preferences preserve omitted fields, theme and weekly availability. Final contract-generation sweep remains in slice 06.

Final integrated evidence: ../plans/2026-10-24-calendar-api-verification.md. Human acceptance: HR-2026-10-23-CALENDAR-API is Reviewed by Svein Tore, 2026-10-24 ("Godkjent. Merge"). Explicit approval clears the API merge gate; no individual scenario execution or provider activation is inferred.
