# RFC: Full Booking Browser/API Parity

Status: Approved
Date: 2026-10-27
Owner: Codex. Product/release owner and intended named reviewer: Svein Tore.
Human-review checklist: HR-2026-10-27-BOOKING-API (Pending; planned scope).
Coverage: ../plans/2026-10-27-booking-api-coverage-matrix.md and .json.
Architecture: ../adr/2026-10-27-booking-api-authority-and-confirmation.md (Proposed).
Slices: ../feature-slices/2026-10-27-booking-full-api-parity.md (Draft).

## Context

Svein requested 100% Booking API support. Authoritative Dev has 13 browser route
rows and zero Booking API operations. Existing Booking and Calendar module suites
pass 37 tests / 315 assertions. This is baseline evidence, not API completion.
The approved July Booking RFC and July 28 slice define current routing/workflows;
they do not approve this new API authority/command contract. Existing Knowledge
and other contributor work is preserved; this checkpoint starts no implementation.

## Goals

Provide every supported public/staff read, write and action through typed versioned
APIs, including configuration/options, current native permissions intersected with
token abilities, exact read-back, Calendar checks and customer notices. Full parity
requires complete route/workflow evidence, Swagger, tests and verified handbook
publication, not an endpoint count.

## Non-Goals

New direct reservation/holds, request-only mode, cancellation/rescheduling/deletion,
payments, Resource/ServiceVisit execution, portal history, skill/department/workload/
round-robin routing and multiple/holiday opening windows are separate product work.
Model constants do not prove supported workflows. No role grants, live customer test
sends, runtime activation or production deployment are included. Scoped Main promotion
and its commit/push are explicitly authorized by Svein Tore.

## Current Behavior

Public /booking lists active staff-confirmed settings backed by orderable active/
published Commercial services and valid technicians. Customers choose Calendar-based
slots, acknowledge privacy and submit contact details; a configured honeypot records
spam. Company/profile work hours intersect service opening windows, step, notice and
horizon. Routing is fixed, automatic or customer-choice. Automatic routing exposes
only times, stores a concrete person, and may choose another eligible free person at
confirmation. Fixed/customer-choice retain their person. Pending requests reserve no
capacity. Staff confirms into a linked busy Calendar event or declines with a reason;
customer mail uses the existing Notification/Email account pipeline.

Native permissions are booking.view, booking.manage and booking.request_review.
AdminAccess is active-account/role-or-permission based, not a hardcoded Admin role.
Settings expose slug/status/public copy, duration/step/notice/horizon, opening window,
work-hours source, routing/pool, location/instructions/honeypot. Writes force
booking_mode=staff_confirmed and allow_new_clients=true. Confirmation checks status
and availability before its transaction; decline lacks an atomic terminal transition.
Notification timestamps report queue/submission evidence, not inbox receipt.

## Proposed Change

1. Keep route ownership in Booking/routes.php, using separate public and protected
   branches loaded by the existing versioned API entry point. Controllers, requests,
   resources, actions and tests remain inside Booking. Proposed operations: four
   public and thirteen protected; exact paths and native mappings are in the matrix.
2. Public JSON offers catalogue, safe service/form context, bounded slot queries and
   request submission through shared FindBookingSlots/StoreBookingRequest behavior.
   Keep 60/min reads and 10/min submissions or a stricter reviewed shared limiter;
   API/web must not offer independent quotas that evade native limits. Preserve
   unbookable 404, privacy acknowledgement, dynamic honeypot, date/timezone/duration,
   slot alignment, notice/horizon and eligible-person validation. Customer-choice
   exposes only intentional public name/ID fields; automatic/fixed JSON must not
   reveal private identities or Calendar details.
3. Require Idempotency-Key on public submission and protected mutations. Public
   acknowledgement/replay returns only a minimal high-entropy submission reference
   and generic thanks context, with no PII, staff identity, spam status or history.
   No anonymous request-by-ID/key GET is added: the current thanks page reads no
   private request. Spam returns equivalent safe acknowledgement without mail/event
   creation. Public claims must not permit cross-client response/data disclosure.
4. Protected summary/settings/requests/detail/timeline use bounded lists and typed
   allowlisted results equivalent to native pages. Never serialize raw_payload,
   arbitrary metadata, IP/user-agent, secrets or provider exception bodies. Existing
   review may return a safe Calendar event ID/link; expanded Calendar content still
   requires its native authority. Distinguish submitted/queued/failed/unknown notices
   and independently confirmed receipt. Do not infer delivery from timestamps.
5. Context exposes exact defaults, validation constraints and native service/user
   choices. Each capability checks its native permission: view, manage or review.
   Recheck fresh active internal identity and explicit token scopes on every call;
   scopes do not grant native rights. Do not invent booking.view as an additional
   prerequisite for manage/review when the native route does not require it.
6. Extract setting validation/normalization/persistence into shared module actions/
   requests used by web/API. Preserve all supported fields, option restrictions,
   forced constants, pool sync, unique service/slug rules including soft-delete
   collisions, and native active/draft/archive toggle behavior. Reject unsupported
   fields and use expected-current-state preconditions for update/toggle.
7. Proposed scopes are booking.read, booking.settings.write, booking.requests.review
   and booking.commands.read. Add catalog entries only with matching implemented
   routes/tests. Receipt lookup also requires original operation and response-field
   authority; permission/token revocation takes effect on replay. Public endpoints
   remain restricted to current anonymous-intake capabilities.
8. Add durable Booking-owned command/dispatch claims with unique replay identities,
   keyed input fingerprints and encrypted allowlisted results. Staff claims bind
   actor/token/operation/key; public claims retain minimal safe submission context.
   Local mutations and command results commit atomically. Lock/reload a request
   before confirming/declining so concurrent terminal actions produce one decision,
   one audit transition and at most one Calendar event/notification submission.
9. Final availability resolution/event creation must use one serialized Calendar-
   owned booking boundary shared by browser/API. Lock candidates deterministically
   before rechecking; coordinate relevant Calendar writers on the shared lock where
   needed. Calendar currently allows explicit overlaps in ordinary editing: preserve
   that behavior rather than introducing global overlap rejection. Native probes
   must establish two competing requests, automatic fallback, and competing Calendar
   create/update ordering. Do not claim a no-overlap guarantee from SQLite or an
   API-only lock. A broader Calendar workflow change needs an RFC amendment.
10. Dispatch customer notices after local commit using durable claim/evidence and
    the existing Notification/Email path. Never blindly resend after an unknown
    provider/queue outcome. New API submission and staff mutation gates default off
    until authorized operational activation; when enabled, mutations preserve the
    required native email effects. Existing web functionality remains active.
11. Generate typed requests/success/errors, constraints, pagination, abilities,
    preconditions, idempotency and 401/403/404/409/422/429/503 outcomes. Compare actual
    runtime methods/paths with generated Swagger and validate successful results.
    Public operations explicitly override global bearer security declarations.

## Impact Analysis

Booking owns routes/resources/actions/receipts. Calendar owns availability/events/
links and reservation serialization. Commercial owns service eligibility;
UserManagement owns active technicians/profile hours. Notification/Email retain
transport/account control, Integration owns ability catalog/Swagger, and Knowledge/
BookStack owns handbook publication. Re-read dirty shared files immediately before
narrow edits; preserve existing Knowledge and contributor implementation.
Risks: public identity/PII leakage, scope escalation, stale settings, terminal-state/
Calendar races and duplicate/unknown mail. Preserve DST, Calendar privacy and pending
requests' non-reservation behavior. GitHub live search found no open Booking issue;
historical #184 is existing routing work, not API approval.

## Data And Migration Plan

Plan additive Booking command/dispatch schema with unique identity, safe timestamps,
keyed fingerprints and encrypted sanitized results. Final filenames/indexes follow
native MySQL inspection in the approved slice. Run only reviewed exact Dev migrations,
not every pending migration. No request backfill, state rewrite, role grant, client/
contact creation or old-email reprocessing. Verify isolated native uniqueness/locking/
rollback/ciphertext. Later deployment needs the exact migration, generated Swagger,
normal optimize:clear and authorized API gate configuration. No frontend build or
new scheduler/worker is expected; inspect changed jobs before deciding a restart.
Rollback disables API effects and retains APP_KEY, requests/audit and receipts;
never drop history to make an uncertain operation retryable.

## Testing Plan

Every matrix row needs successful API/native parity, real bearer/native denials,
inactive/system/portal/revoked identity checks and safe schema canaries. Cover all
settings, pool transitions, slug collisions, stale/conflicting retries, anonymous
spam/privacy/throttle, date/DST/alignment/notice/horizon and identity isolation.
Test confirm/decline races, two requests competing for capacity, automatic fallback,
competing Calendar writers, dispatch failure/crash/unknown and denied receipt replay.
Run Booking, Calendar native/API contracts/parity and affected Commercial,
UserManagement, Notification/account-mail, Integration/catalog/Swagger and Knowledge
source-sync regression. Add isolated native MySQL concurrency probes and trusted
HTTPS synthetic successful write/read-back with mocked delivery. Any unproven
required guarantee is a blocker. Baseline 37/315 passes; full suite/future API tests
have not run and no new API is claimed verified.

## Documentation Plan

Update Booking README/booking-overview, add Booking API guide and relevant Calendar/
API-management notes with prerequisites, exact menu/actions, steps and troubleshooting.
Sync authoritative Knowledge sources and verify actual BookStack bodies/employee
access without widening scope. OpenWebUI retrieval is separate. Record publication
blocker/owner/next action in TODO. Maintain matrix, evidence, RFC/ADR/slices and HR
entry together. Planning requires no handbook publication. Consider public website
handoff after implementation with Dev-only/do-not-publish notes until production.

## Open Questions

No unresolved product choice for parity with supported native Booking. Explicit
approval of this concrete RFC is required before Level 3 implementation. Named
human review HR-2026-10-27-BOOKING-API remains a separate Main/production gate.

## Approval

Approved by Svein Tore on 2026-10-27: "Godkjenner og merge til main".
Implementation and isolated Main verification complete; production and named manual
scenario acceptance remain separate.

Authorization: Svein Tore, 2026-10-27: "Godkjenner og merge til main".
Approved Dev implementation and scoped Main promotion; production and named manual
scenario acceptance remain separate.

Implementation evidence: ../plans/2026-10-27-booking-api-verification.md.
