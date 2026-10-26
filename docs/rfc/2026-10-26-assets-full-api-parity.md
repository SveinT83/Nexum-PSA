# RFC: Full Assets Browser/API Parity

Status: Approved (direct implementation authorization by Svein Tore, 2026-10-26).
Date: 2026-10-26
Owner: Codex. Product/release owner: Svein Tore.

## Context
The browser offers manual Assets, filtering, classification, ownership, settings,
alerts and provider import actions. The five existing HTTP operations cover only
basic CRUD and omit classification on update, discovery and administration.

## Goals
Every supported Asset browser read/write/action has a typed, authorized API,
Swagger contract, meaningful tests and exact result/read-back evidence.

## Non-Goals
New deletion, remote control, RMM rules/escalation (#228), model namespace moves,
provider activation and production deployment. Related Tickets is an empty-state
placeholder in the current browser; no invented relationship workflow.

## Current Behavior
Routes live in Asset/routes.php; Livewire has form, alert sync/summary and
Integration-owned RMM imports. Browser lists are staff-wide behind asset.view.
The polymorphic Asset namespace remains unchanged.

## Proposed Change
Reuse shared Asset validation/actions/queries, add context/lookups/docs, full
list filters, classification read/write, alert list/summary and scoped sync,
settings read/write, provider import preview/execute and durable operation read-back.
Keep existing update enrichment fields compatible. Scopes never replace freshly
loaded active internal actor rights. Add separate settings and sync scopes;
provider API execution is default-off, preview/explicit confirmation required.
Local writes use a transaction/mutex; keyed replay has durable encrypted receipts,
current authorization and optional revision fencing for existing update clients.
Provider work has a committed claim before execution, no automatic replay after
interruption, sanitized partial/unknown results, exact scope and read-back.

## Impact Analysis
Asset owns routes/actions/contracts. Integration retains provider mapping logic,
credentials, alert occurrence/routing and permission policy; thin shared adapters
reuse it without new escalation rules. Clients/Sites/legacy Client Users/Vendors
remain authoritative references. No credential, permission or provider activation.
Regression scope: Asset, WorkContext, Integration RMM/Swagger/API management,
Clients, Contact and Notification. No new UI or frontend build.

## Data And Migration Plan
One additive Asset command/mutex migration. No business-row backfill. Keep receipts
and APP_KEY on rollback; revert code only after stopping commands. Existing shared
queue backlog is outside scope; no scheduler/worker activation required.

## Testing Plan
Red/green gaps; positive/negative scopes and current actors; complete filters and
classification, ownership boundaries, defaults/legacy values, strict fields,
settings, revisions/replay, provider disabled/preview/partial/interrupted outcomes,
no live provider writes in tests. Compare every route/method with generated Swagger.
Native MySQL locks/uniqueness, trusted HTTPS reads, clean Main candidate regression.

## Documentation Plan
Asset API/settings/lifecycle and API Management sources, exact Knowledge/BookStack
publication/read-back, matrix, verification, TODO and human-review register.

## Feature Slices
1. Discovery, query/details/alerts and authorization.
2. Shared manual persistence, settings, replay/revision.
3. Provider preview, explicit execution, operation evidence and web reuse.
4. Complete Swagger, regressions, handbook and scoped Main promotion.
Each slice must meet the common authorization, tests, documentation and read-back
criteria. Implementation status is tracked in TODO and verification evidence.

## Open Questions
None within the requested existing-browser scope.

## Approval
Svein Tore directly requested: Assets domain API, Kjor pa; 100 percent support for
all browser operations, Swagger update and migration to MAIN, 2026-10-26.
This authorizes implementation and scoped Main promotion. It does not constitute
completed human scenario review or production deployment approval.

## Implementation Verification

All four slices and A01-A14 are Done On Dev. See the exact verification report/JSON.
Dev migration batch 28 and isolated Main candidate verification are complete.
Main promotion is authorized and being finalized. Human scenario review remains
Pending under HR-2026-10-26-ASSETS-API; production/provider activation is separate.
