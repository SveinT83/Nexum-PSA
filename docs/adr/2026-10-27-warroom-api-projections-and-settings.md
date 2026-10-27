# ADR: Warroom API Projections And Settings

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore (RFC approved 2026-10-27), Codex
Parent: ../rfc/2026-10-27-warroom-full-api-parity.md

## Context

Warroom composes source domains, currently through direct table queries. It owns
global dashboard settings but no source record workflows. New bearer APIs must
not turn operational summaries into a broader data-access channel. Concurrent
browser/API settings writes also need a common exact-result contract.

## Decision

Propose shared pure actor-authorized projections and explicit allowlisted
resources for browser/API. Reuse Calendar visibility and read-only mailbox scopes.
Retain personal ownership filters in My Day. Unknown/unavailable, empty, hidden
and denied are separate source states. All source actions keep domain ownership.
Use explicit Warroom read/settings abilities intersected with native authority.
Settings use one shared validator/action, deterministic revision, singleton lock
and encrypted durable actor/token-bound command results committed atomically.
Read-back and replay recheck current rights; GET projections do not mutate state.
Domain definitions stay in routes.php; api.php is a loader-only compatibility shim.

## Rationale

One query/action boundary prevents divergent web/API behavior, source-access
leaks, inconsistent totals and browser writes racing API revisions. Durable
receipts provide truthful exact results after retry without extra effects.

## Consequences

Narrower native-source rights can intentionally reduce visible web counts/panels.
One additive commands/locking migration and native concurrency checks are needed.
Minimal views must handle capability/source states and revision conflicts.
No grant, source ownership transfer or provider activation follows from API access.

## Alternatives Considered

Return controller view data directly: retains table-level leaks and unstable types.
Implement independent API SQL: drifts from web behavior and source rules.
Treat every missing source as zero: falsely reports healthy operations.
API-only locks: do not protect against browser races.

## Follow-Up

Implemented and verified WR01-WR04 on Dev, including ordinary-only mailbox KPI
scope so emergency access cannot bypass Mail's audit/use guard. Runtime/Swagger,
tests, native/HTTPS and actual BookStack read-back are recorded in the verification
report. HR-2026-10-27-WARROOM-API is Reviewed by Svein Tore, 2026-10-27 ("Godkjenner"); later Main/production actions require separate authorization.
The exact additive migration is installed on Dev; no Main/production action.

## Named Human Acceptance (2026-10-27)

Svein Tore explicitly accepted the completed Dev handoff/checklist: "Godkjenner".
HR-2026-10-27-WARROOM-API is Reviewed. Individual browser scenarios were not
independently observed by the agent. Commit/push, Main promotion and production
deployment are separate actions and were not authorized or performed here.
