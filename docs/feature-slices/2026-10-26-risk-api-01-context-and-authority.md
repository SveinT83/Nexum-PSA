# Feature Slice: Context, resources and authority

Status: Done on Dev / Human Reviewed by Svein Tore, 2026-10-26
Date: 2026-10-26. Owner: Codex.
Parent: ../rfc/2026-10-26-risk-full-api-parity.md (Approved by Svein Tore).
Human review: HR-2026-10-26-RISK-API (Reviewed).
Scope matrix: R01, R02, R04, R07, R15.

## Goal
Complete the approved context, resources and authority scope with official operational API parity.

## User-Visible Behavior
The supported browser workflow has the same actor/parent/owner/field rules and
persisted side effects through the API, with typed discovery and exact read-back.

## Scope
R01, R02, R04, R07, R15; use the parent RFC and matrix for exact operations and behavior.

## Out Of Scope
New product systems, provider/queue activation, force purge/restore, Main/production.

## Data Touched
Existing Risk models/settings; minimal Risk command/mutex records for local replay.
Exact additive Dev migration: 2026_10_26_010000_create_risk_api_commands.php.
No Risk business-row backfill. Preserve durable evidence on rollback.

## Permissions
Current approved Risk permission/ownership table; API scopes are ceilings only.
No automatic grants. Recheck current actor/parent/owner on requests and replay.

## Tests
Success/denial, strict validation, omission/null, exact read-back, pure GET,
history/defaults/approval effects, stale revision and replay as applicable.
Native lock/unique/concurrency and trusted HTTPS/Swagger/PDF at final verification.

## Documentation
Update parent/matrix/TODO/human review and relevant Risk Knowledge/source.
The final slice verifies actual BookStack content and existing staff scope.

## Done Criteria
Mapped behavior implemented on Dev, named tests pass, exact routes/schemas are
accounted for and relevant native/documentation evidence is recorded.
Human review is Reviewed by Svein Tore, 2026-10-26. Main/production remain separate.

## Verified Delivery

Implemented and tested on authoritative Dev, 2026-10-26. See
../plans/2026-10-26-risk-api-verification.md and .json for named tests, native
locking/concurrency, typed contracts, HTTPS/PDF and independent BookStack evidence.
26 Risk tests / 3246 assertions; affected regression batch 163 tests / 4923 assertions
(overlapping suites, not additive totals). Exact migration applied on Dev as batch 27.
Human review is accepted; no Main/production promotion is implied.
