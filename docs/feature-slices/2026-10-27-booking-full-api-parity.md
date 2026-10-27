# Feature Slices: Full Booking Browser/API Parity

Status: Done On Dev
Date: 2026-10-27
Parent: ../rfc/2026-10-27-booking-full-api-parity.md (Approved)
Owner: Codex. Product/review owner: Svein Tore.
Execution: sequential after RFC approval; no parallel implementation authorized.
Human-review checklist: HR-2026-10-27-BOOKING-API, Pending/planned.

## Goal

Complete supported public/staff Booking via typed APIs with shared behavior,
fresh authority, replay/concurrency evidence, exact read-back and documentation.

## User-Visible Behavior

Public clients discover services/slots and submit requests; authorized staff
configure services and inspect/confirm/decline with native routing/hours/Calendar
and customer notification behavior preserved.

## Scope

### BK01 - Reads, context and authority

Status: Done On Dev. Operations P01-P03, A01-A04, A08-A10.
Implement route loading branches, current native/token guards, public/staff-safe
resources, defaults/options and bounded lists with initial Swagger.
Data: Booking/service/user/profile/Calendar reads. Existing FindBookingSlots can
ensure a personal calendar; explicitly document/test that native initialization
instead of claiming every availability GET is pure or adding new read-side writes.
Permissions: public-safe anonymous output; exact booking.view/manage/review per
context capability, narrowed by proposed booking.read.
Tests: successful reads/options/schema canaries, fresh/revoked/inactive/foreign
rights, bounds/timezone/DST, automatic identity protection and eligible choices.
Done: every read/context matrix row has a successful typed API result and test.

### BK02 - Shared setting writes and command claims

Status: Done On Dev. Operations A05-A07, A13. Depends on BK01.
Share native validation/normalization/persistence and full setting/pool writes.
Add reviewed exact additive encrypted receipt/dispatch schema, expected-current-
state preconditions and default-off API write gates. No secret/raw-input retention.
Data: settings/pool and additive command/claim tables; no backfill or role grants.
Permissions: booking.settings.write + booking.manage; command read also requires
original operation/response authority after revocation checks.
Tests: every field/forced constant, slug/soft-delete uniqueness, pool transitions,
replay/stale/conflicting keys, rollback and isolated native MySQL uniqueness.
Done: exact setting read-back/web parity, migration/indexes and native probes recorded.

### BK03 - Intake, decisions and truthful dispatch

Status: Done On Dev. Operations P04, A11-A12. Depends on BK02.
Share public validation/spam/minimal acknowledgement and atomic terminal decisions.
Coordinate Calendar-owned final availability/create across web/API with deterministic
locks, automatic fallback and unchanged ordinary Calendar overlap semantics.
Use durable notification claims and submitted/failed/unknown reconciliation.
Data: Booking requests/events, Calendar events/links/participants, command/dispatch
claims and existing Notification/Email pipeline. Tests mock customer delivery.
Permissions: current anonymous intake; booking.requests.review + booking.request_review.
Tests: spam/privacy/throttle, retry storms, confirm/decline races, competing requests,
automatic fallback, competing Calendar writes and crash/unknown notification outcomes.
Done: native MySQL proof, one terminal decision/event/dispatch and safe exact read-back;
no guarantee inferred from SQLite or absence of errors.

### BK04 - Full contract, handbook and handoff

Status: Done On Dev. Depends on BK03.
Reinventory actual routes/embedded workflows, finalize typed Swagger and exact
method/path/ability/schema comparison, trusted HTTPS successful synthetic calls,
cross-module regression and verification evidence. Publish authoritative Knowledge
sources and read back actual BookStack bodies/employee scope; record blockers.
Data: docs/generated Swagger and selected Knowledge/BookStack records only.
Permissions: supported documentation authority, no access expansion.
Tests: Booking/Calendar native/API, affected Commercial/UserManagement/Notification,
Integration/catalog/Swagger and Knowledge source-sync. Outbound delivery mocked.
Done: every current workflow verified, handbook publication proven, RFC/ADR/TODO/
matrix/HR reconciled; Dev/Main/production and pending manual/activation gates explicit.

## Out Of Scope

Future unimplemented Booking products, Main/production promotion, role grants,
live customer tests and operational API/provider/scheduler activation.

## Documentation

Each slice updates its evidence/status and relevant source guide. BK04 verifies
BookStack publication. TODO tracks any concrete blocker with owner/next action.

## Done Criteria

All four slices pass complete workflow parity, exact scopes/Swagger/read-back,
replay/native concurrency and handbook verification. HR remains Pending until
Svein Tore explicitly confirms human checks; Main/production remain separate.

Authorization: Svein Tore, 2026-10-27: "Godkjenner og merge til main".
Approved Dev implementation and scoped Main promotion; production and named manual
scenario acceptance remain separate.

Verification: 2026-10-27-booking-api-verification.md; all four sequential slices
implemented and verified. Manual review Pending; scoped Main merge authorized.
