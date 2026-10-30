# ADR: Personal Employee Workday On The Existing Nexum Gateway Registration

Status: Accepted
Date: 2026-10-30
Decision Makers: Svein Tore (product direction and execution approval); Codex (reversible implementation)

## Context

Employees must use the existing ChatGPT plugin and nexumpsa connection in LiteLLM for their own
Workday. The main registration historically carried a shared static PSA credential. An incident
introduced a separate nexumworkday registration; its employee token was subsequently missing.
Neither a shared credential nor a user_id request field establishes personal employee identity.

## Decision

Keep the existing registration and plugin. For Workday, employee work-plan and plan-block
operations, select the existing gateway-authenticated caller's personal user/server credential.
The resolved personal Authorization header is authoritative over static and client-supplied
upstream headers. Missing identity/credential fails closed. Do not change PSA's authentication,
actor, permission, ownership, validation, version or idempotency rules.

Provision only verified incident employee bindings, using native personal tokens with
workdays.read/write/confirm. Reuse the owner's independently verified personal credential for the
owner binding. The employee manages no additional LiteLLM key or Workday integration. Retire the
obsolete separate registration after the main route is verified.

Preserve other domains' existing configuration; their shared-credential identity migration remains
separate. Maintain a narrow adapter/spec patch and immutable rollback preimages, and verify cold
public discovery and actual calls after changes.

## Rationale

This meets the approved single-connection workflow using already supported personal authentication.
It avoids a new delegation protocol, accepting caller-selected employee IDs, or giving a shared
privileged actor access to another employee's work. PSA continues to own and enforce its contract.

## Consequences

The gateway needs a valid personal binding for each employee using these APIs. A listed tool does
not prove that binding or native scopes/permissions are present. The incident rollout covers the
two verified employees and the existing owner; it is not universal employee onboarding.
The adapter is scoped to an exact main server/origin/spec and must be reviewed when that mapping
changes. Opaque Sanctum tokens must not be constrained to an invented fixed token length.
The eleven own-day tools need complete body schemas and stable Idempotency-Key forwarding.
Other employee-sensitive operations retain their own scopes rather than inheriting extra grants.

## Alternatives Considered

- A separate Workday registration: superseded by Svein's explicit single-connection requirement.
- Shared static actor with a user_id field: incompatible with existing employee ownership.
- New signed delegation/OAuth system in PSA: unnecessary for this bounded repair.
- Global BYOK conversion of every PSA operation: changes unrelated callers and is outside this scope.

## Follow-Up

See RFC 2026-10-30-existing-nexum-workday-gateway and HR-2026-10-30-NEXUM-GATEWAY-WORKDAY.
Human ChatGPT/save acceptance, original 422 payload reconstruction, other employee onboarding and
unrelated domain identity migration remain separate evidence/scope. Main/deployment approval is
recorded independently and never marks a human checklist Reviewed.
