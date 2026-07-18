# ADR: Ticket Workflow Orchestrator And Domain Provider Boundary

Status: Accepted
Date: 2026-07-17
Decision Makers: Nexum PSA product owner and Codex

## Context

Ticket Workflow must evaluate facts and coordinate actions owned by Ticket, Asset, Commercial,
Sales, Storage, Economy, Email, Task, Knowledge, and UserManagement. Putting all logic inside
Ticket would duplicate business rules, while making a generic automation domain responsible for
synchronous authorization would weaken domain ownership and make blocked actions unpredictable.

## Decision

Ticket owns versioned workflow definitions, operational state, requirement trees, action policy,
internal escalation, review checkpoints, evidence links, and workflow audit history.

Participating modules expose whitelisted workflow providers. A fact provider returns typed,
batched facts and human-readable evidence for one Ticket. An action provider returns the existing
domain authorization decision and executes an idempotent domain command. Provider keys and schema
versions are stable configuration contracts; definitions never contain executable code, model
class names, or arbitrary queries.

Every protected request passes ordinary permission and organization scope, the domain guard, the
workflow decision, and a final transaction-time re-evaluation. Workflow can narrow access but can
never grant a missing permission or bypass a domain invariant. Signal and Notification may consume
committed events but are not the synchronous enforcement engine.

## Rationale

This keeps data and validation with the module that understands it, gives Ticket one explainable
orchestration point, provides identical decisions to Blade and API clients, and allows future
providers without changing the requirement storage schema.

## Consequences

- Ticket gains provider registries and cross-module integration tests.
- Every participating module must maintain stable provider keys and permission-aware actions.
- Missing or failing providers fail closed for guarded mutations and show a safe explanation.
- Published workflow versions pin provider schema versions and require an explicit compatibility
  migration when a provider contract changes incompatibly.
- The Ticket view may request many facts, so providers must batch queries and avoid N+1 behavior.
- Domain commands remain independently callable only through their existing guards plus the shared
  workflow action decision where a Ticket context exists.

## Alternatives Considered

- Copy cross-domain data and rules into Ticket: rejected because facts become stale and ownership
  is duplicated.
- Let every controller interpret workflow JSON: rejected because UI/API decisions would diverge.
- Use Signal as the synchronous workflow runtime: rejected because asynchronous automation cannot
  be the authorization boundary.
- Create one unrestricted scripting engine: rejected because it would expose unsafe queries and
  make migrations and audits difficult.

## Follow-Up

- Implement the provider contracts and registry in the core Feature Slice.
- Add provider contract tests for every registered fact and action.
- Document provider keys, operators, schema versions, failure behavior, and API decision format.
- Review query counts on Ticket detail and workflow simulation before release.
