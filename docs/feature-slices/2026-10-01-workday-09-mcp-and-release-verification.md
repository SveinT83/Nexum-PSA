# Feature Slice 09: Employee API Contract And Consumer Handoff

## Calendar API parity follow-up (2026-10-05, approved by Svein Tore)

Svein approved the calendar/modal UX and requested complete API support for a later Nexum PSA
MCP server. Continue Slice 09 under the existing approved API-parity scope.
Add GET /api/v1/workdays/{work_date}/entry with workdays.read and workday.view_own:
read the selected date without creating it, returning the persisted day or null, version,
record timezone, effective planned/free intervals, first free up-to-one-hour suggestion,
adjacent own reservations and edit/correction capability. Reuse the calendar timeline query.
Plan/suggestion data is never a recorded interval or employee confirmation.
Existing versioned draft replacement provides add/edit/remove of minute intervals and breaks.
Preserve idempotency, explicit preview/confirmation, ownership, retention and overlap rules.
Publish a typed OpenAPI contract and complete consumer examples; verify personal bearer
create/edit/remove/retry/conflict/read-back and route-to-spec parity.
Impact: Workday query/routes/OpenAPI/tests/docs; shared expiry calculation is reused by reads
and draft creation. No new storage, grants, credentials, scheduler, queue or external integration.
MCP implementation remains a later task. Status: Done On Dev.

Status: Done On Dev; 35 API operations verified (2026-10-05); calendar/modal UX approved, remaining human checks In Review.
Date: 2026-10-01
Owner: Codex; product/reviewer: Svein Tore
Parent: [Workday RFC](../rfc/2026-10-01-daily-workday-confirmation.md)
Delivery contract: [Implementation plan](../plans/2026-10-01-workday-implementation-plan.md)
Human review: [HR-2026-10-01-WORKDAY](../human-review.md) - In Review; calendar/modal UX approved by Svein Tore on 2026-10-05; broader pilot checks remain.
Dependencies: Slices 01-08; authoritative PSA API/OpenAPI and isolated employee-token fixtures.

Scope revision approved by Svein Tore on 2026-10-02: MCP work is postponed. NexumMCP is
being replaced by LiteLLM. This slice delivers provider-independent PSA APIs and consumer
documentation; no NexumMCP/LiteLLM/MCP connection or adapter is a completion dependency.

API follow-up verified on Dev (2026-10-05): 35 published operations; 13 API tests /
432 assertions and 60 regression tests / 475 assertions passed (73 tests / 907 assertions).
GET workdays/{work_date}/entry supplies the calendar context without creating actual time.
Existing draft replacement was verified for minute create/add/edit/remove, retries, overlap,
stale versions, preserved breaks and read-back. UI approval by Svein Tore is recorded.
See [API parity verification](../plans/2026-10-05-workday-entry-api-verification.md).

## Goal

Prove the authorized employee API workflow and supply a complete, documented API contract for later LiteLLM/MCP tooling.

## User-Visible Behavior

A test employee can read/update a plan, record/correct absence and work, explicitly confirm and read back the result through the API; Superuser sees the confirmed overview.

## Scope

- Generate and validate the published Workday OpenAPI contract, including routes/abilities/errors, version/preview/idempotency and completeness semantics. Compare route inventory to published spec and record build identification.
- Prepare a provider-independent API consumer handoff for later LiteLLM/MCP work. List exact operations, schemas, scopes, ownership, retries, explicit confirmation, pagination and errors. Do not claim an adapter or live tool connection is delivered.
- Verify real personal bearer authentication through the Laravel HTTP stack using isolated synthetic records. Execute plan/day/absence writes, explicit confirmation, retry and read-back; test cross-user and wrong-scope denial.
- Keep read-only coordinator workloads read-only. Do not reuse a shared privileged connector for employee impersonation or infer binding from submitted IDs.
- Run focused cross-module regression and authenticated browser/HTTP smoke checks; fix failures or report a concrete blocker instead of marking the release complete.
- Complete Knowledge/API/SOP documentation and the human-review evidence. Publish no production/product claims before actual release; prepare a public-safe website handoff only when appropriate.
- Prepare exact migration/build/cache/queue/scheduler/rollback instructions from implemented changes. Svein owns commit/push authorization and Main promotion; no Main mutation is implied by this plan.

## Out Of Scope

MCP/LiteLLM adapters or live gateway testing, Tripletex synchronization, new model/provider permissions, broad coordinator setup, automatic human-review completion, production activation or deployment without authorization.

## Data Touched

Generated API documentation, synthetic test records in isolated test scope, consumer handoff and evidence manifest; no credential copies or real employee content in artifacts.
Names for new storage/actions/routes are implementation proposals, not claims of existing tables.
Confirm exact migrations and existing state on authoritative Dev before runtime changes.

## Permissions

Personal token scopes intersect current employee permissions. Separate explicit workdays.read-all for oversight. Existing external-processing policy remains enforced.

## Tests

- Route-to-OpenAPI parity and anonymous/wrong-token/source-revoked denial.
- Personal bearer API read/write/correction/confirm/retry sequence with persisted identity/version read-back.
- Negative shared-token/foreign-worker tests and unchanged coordinator denial behavior.
- UI, keyboard/form validation and live channel/device checks remain the separate human pilot checklist.
- Read-only trusted HTTPS checks of the exact Dev build plus scheduler runner and worker verification when required.
- Run the narrow affected Laravel suites on Dev with synthetic fixtures; no local PHP fallback.
- Inspect authenticated UI/API/HTTP read-back for the implemented behavior; an unauthenticated login
  redirect does not prove the feature works.

## Documentation

Final evidence manifest, Workday Knowledge/API, API consumer handoff, updated TODO/RFC/ADR/slices and HR-2026-10-01-WORKDAY.
Update this slice and the parent TODO row in the same session as verification or a concrete blocker.

## Done Criteria

All eight implementation slices are verified on Dev; all supported API operations have matching generated OpenAPI/scopes and employee-token workflow evidence. MCP/LiteLLM integration is explicitly deferred and does not block this API delivery. Human review and Main/production remain separate, named gates.
Record changed files, exact tests/results, migrations/commands, HTTP/UI/API evidence and remaining
human checks. Passing automated tests never marks human review complete. Keep production runtime
off until the required review and separate rollout approval. Do not leave visible stubs for later slices.

## Delivery evidence (2026-10-02)

All 34 API operations have matching published OpenAPI/security/scopes, and 196 tests / 1912
assertions pass on Dev. Personal bearer read/write/confirmation/retry/read-back and anonymous/
wrong-scope denial are verified independently of any gateway. See the
[consumer guide](../integrations/workday-api-consumer.md) and
[verification](../plans/2026-10-02-workday-slice-09-api-verification.md).
No migration, live token expansion, employee activation or gateway connection was performed.
MCP/LiteLLM tooling is deferred by Svein; HR-2026-10-01-WORKDAY remains Pending for the
combined pilot and Main/production gates. The filename is retained for existing references;
its old MCP-completion prerequisite is superseded by the approved scope revision above.
