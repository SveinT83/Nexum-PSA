# Feature Slices: Full Nextcloud Browser/API Parity

Status: Done On Dev / all five slices verified
Date: 2026-10-27
Parent: ../rfc/2026-10-27-nextcloud-full-api-parity.md.
Owner: Codex. Approver/reviewer: Svein Tore.
All five slices are Done On Dev. Main merge is authorized; human-review remains Pending.

## Goal

Complete the parent RFC target and its 19 browser rows plus embedded workflows.

## User-Visible Behavior

Authorized API consumers can perform existing connection, mapping, folder, Talk and
full sync workflows with browser-equivalent behavior and exact verifiable results.

## Scope

1. Authority/connections: typed reads/context, shared validation and connection CRUD,
   safe PATCH/defaults/credentials, additive receipts/audit/stale-precondition schema.
2. Mappings: shared user/group/calendar/folder actions, all skip/map/import branches,
   Contact bridge/tenant checks, collection/item read-back, deletes/replay/stale cases.
3. Folders: bounded live browsing, root/documents selection, deterministic/AI preview
   and exact local apply through existing configured matching and AI policy.
4. Provider actions: full sync/health distinct from calendar-only sync; fresh execution
   rights/leases; Talk config/test; default-off new execution; truthful attempts and
   ambiguous-result recovery; preserve webhook/Notification/calendar compatibility.
5. Acceptance: full affected/native/HTTPS checks, exact runtime/Swagger HTTP contracts,
   Knowledge/BookStack content/access read-back, TODO/RFC/ADR/matrix and review state.

## Out Of Scope

Parent non-goals apply: provider proxy, new Talk destination/SSO/file platform,
production/Main activation or promotion and historical reconciliation.

## Data Touched

Nextcloud connection/mapping/log/conflict and new command rows; existing Calendar
claims/actions, Contact/Client bridge, Integration catalog/Swagger/AI and Notification.
No data backfill or grants. Final migration filename is determined after approval.

## Permissions

Fresh native active administrator, effective current full-route permission,
record/tenant/field rights and explicit token grants. Additional credential/provider
rights are cumulative. Existing calendar consent and inbound HMAC remain independent.

## Tests

Each slice includes positive/denied HTTP, persistence/read-back and regressions before
continuing. Follow the parent Testing Plan for affected suites and native checks.
Run on authoritative Dev with fake provider/AI calls, never real customer effects.

## Documentation

Update affected module/Knowledge/API sources per slice; verify actual BookStack body
and intended employee access before calling handbook complete. Synchronize evidence
and living coordination state; record concrete blockers and responsible owner.

## Done Criteria

- [x] Every browser/embedded row maps to implemented typed operations and test evidence.
- [x] All 14 legacy calendar contracts and signed webhook behavior remain verified.
- [x] Slice and full affected Dev tests pass; failures resolved or explicitly reported.
- [x] Native concurrency/replay/rollback/encryption and HTTP/schema read-back pass.
- [x] Swagger/catalog and employee handbook match verified functionality and access.
- [x] Human-review stays Pending until named acceptance; Dev/Main/production separate.
