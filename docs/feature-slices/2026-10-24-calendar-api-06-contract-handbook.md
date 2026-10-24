# Feature Slice: Calendar API 06-contract-handbook

Status: Done On Dev
Date: 2026-10-24
Parent: ../rfc/2026-10-23-calendar-full-api-parity.md
Owner: Codex
Human review: HR-2026-10-23-CALENDAR-API

## Goal
Exact OpenAPI inventory, complete tests, source documentation and verified BookStack publication.

## User-Visible Behavior
Official typed API covers All matrix rows in the approved gap matrix with browser-equivalent authorization.

## Scope
Exact OpenAPI inventory, complete tests, source documentation and verified BookStack publication. Module routes, shared authoritative actions, schemas and relevant tests.

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

Final integrated evidence: ../plans/2026-10-24-calendar-api-verification.md. Human acceptance: HR-2026-10-23-CALENDAR-API is Reviewed by Svein Tore, 2026-10-24 ("Godkjent. Merge"). Explicit approval clears the API merge gate; no individual scenario execution or provider activation is inferred.
