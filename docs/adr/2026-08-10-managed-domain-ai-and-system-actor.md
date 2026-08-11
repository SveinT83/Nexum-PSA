# ADR: Managed Domain AI And System Actor

Status: Accepted
Date: 2026-08-10
Decision Makers: Svein / Codex

## Context

The original Supplier Email Purchase Import ADR required a dedicated capability-empty agent,
manually approved workload chain, and configured technical User. Runtime inspection shows the
structured executor never exposes agent tools or data sources and all domain writes already occur
through Storage actions after strict validation. Requiring administrators to reproduce those
runtime constraints in configuration created an unnecessarily complex setup.

## Decision

This ADR supersedes the AI-agent capability and configured technical-User decisions in
`docs/adr/2026-08-04-supplier-email-purchase-import-ownership-and-ai-decision-boundary.md`. It
reaffirms all other ownership, profile, evidence, validation, idempotency, audit, and no-receipt
decisions from that ADR.

Integration owns a managed structured workload for the Storage Supplier Order purpose. Storage
selects an existing Storage-domain agent; Integration derives provider/model and executes a
capability-isolated view that exposes no tools, data sources, API scopes, role permissions, token
abilities, or model-initiated actions. The selected agent's general configuration is not mutated.

Managed external execution is always privacy-washed, no broader than `pseudonymized`, strict-schema,
and domain-scoped. Generic coordinator/API workloads and unstructured AI calls keep the existing
organization governance path. A managed domain workload cannot issue tokens or opt into direct
external/full-context processing.

Commercial values that match personal-data patterns, such as numeric order numbers, are replaced
with opaque request-local tokens before an external call and restored only in memory after the
structured response returns. The provider never receives the original value, and neither the
original-to-token mapping nor the raw response is persisted.

User Management owns a stable, non-login system actor identity. Storage ensures the actor and its
exact direct permissions internally, then uses the existing User-typed action and audit contracts.
Administrators do not select or maintain the actor. It is excluded from normal user management and
cannot authenticate, receive invitations, or be changed through user APIs.

AI remains proposal-only. Storage remains the only owner of Item and Purchase Order mutation and
applies all existing source, evidence, identity, arithmetic, warehouse, limit, idempotency, and
lifecycle gates.

## Rationale

- Places capability isolation where it is enforceable: the execution boundary.
- Avoids duplicate agents and workload expertise in a Storage settings workflow.
- Preserves model/provider selection on the domain agent.
- Keeps database audit foreign keys without attributing unattended writes to an arbitrary person.
- Prevents employee turnover or role maintenance from silently stopping a configured automation.
- Keeps external payloads narrower than ordinary agent chat context.
- Preserves the deterministic-first, editable, reversible supplier-order workflow.

## Consequences

Positive:

- Normal setup becomes agent selection plus fallback behavior.
- Agent chat capabilities cannot leak into structured supplier-order execution.
- Audit history names a stable automation identity.
- Existing Storage action signatures and relational audit links remain usable.

Negative:

- User Management must distinguish human users from protected system actors.
- Integration now has two governance paths: generic organization-governed workloads and narrowly
  managed structured domain workloads.
- Managed-purpose authorization must remain allowlisted in code and tested per domain.
- A future broader managed-domain mechanism still requires its own RFC.

## Alternatives Considered

- **Keep manual configuration.** Rejected because it exposes internal implementation details and
  caused the approved workflow to be unusable without specialist knowledge.
- **Let the selected agent use its ordinary tools.** Rejected because email content is untrusted and
  extraction needs no tools or writes.
- **Attribute automation to the last policy editor.** Rejected because it misrepresents unattended
  execution and breaks when that person changes role or leaves.
- **Use nullable actor fields.** Rejected because existing audit relationships and actions benefit
  from a stable actor identity.
- **Create a completely separate actor table.** Rejected because it would require broad changes to
  established User foreign keys and action contracts.

## Follow-Up

- Implement the approved RFC through its three Feature Slices.
- Keep the system actor permission set explicit and covered by regression tests.
- Keep managed purposes allowlisted; do not accept an arbitrary request-supplied purpose.
- Complete human review `HR-2026-08-10-003` before release or active rollout.
