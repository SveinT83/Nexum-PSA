# RFC: Simplified Storage Supplier Order AI

Status: Approved
Amended by: `docs/rfc/2026-08-11-operational-supplier-order-automation-setup.md` for the ordinary
policy form and its system-managed defaults, then by
`docs/rfc/2026-08-11-automatic-ai-supplier-profile-bootstrap.md` for verified first-supplier profile
bootstrap. The managed agent, workload, actor, privacy, and validation architecture in this RFC
remains active.
Date: 2026-08-10
Owner: Svein / Codex

## Context

Supplier Order Automation currently exposes implementation concepts as mandatory setup. An
administrator must create a second agent without capabilities, approve several Integration records,
create an internal workload, and select a human technical User before Storage accepts AI fallback or
automatic Item creation. The structured executor already ignores agent tools and applies a strict
schema, minimized payload, privacy washing, and Storage post-validation. The duplicated agent and
human-user setup therefore add operational complexity without improving the user decision.

The administrator has already selected an AI provider and created a Storage-domain agent. Supplier
orders are editable operational records, AI is fallback after deterministic profiles, and no email
workflow receives goods or changes on-hand stock.

## Goals

- Let an administrator choose the existing active Storage-domain agent directly.
- Make AI fallback a normal Storage policy choice without manually creating a workload.
- Enforce tool-free, non-writing structured execution inside Integration regardless of the agent's
  ordinary chat tools, data sources, API scopes, roles, or action setting.
- Replace the selectable human automation User with a non-login system actor managed by Nexum.
- Keep source minimization, privacy washing, strict schema, evidence, arithmetic, identity,
  idempotency, Item limits, Purchase Order guards, and the no-receipt boundary.
- Keep technical thresholds, learning, consensus, timeout, output, cost, outage, retry, and retention
  behavior safe and system-managed instead of presenting them in the ordinary Storage form.

## Non-Goals

- Let the model call agent tools, APIs, URLs, data sources, or write actions during extraction.
- Let AI write Item, Vendor, Purchase Order, shipment, receipt, Stock Unit, Movement, or on-hand data
  directly.
- Automatically receive goods, submit orders, merge Items by name, or bypass Storage hard gates.
- Simplify generic coordinator/API workloads or unrelated AI workflows in this change.
- Remove the advanced organization-wide AI governance screens.

## Current Behavior

The Storage policy stores an `automation_user_id` and an `ai_workload_profile_id`. Item creation,
profile learning, and automatic finalization reject a missing, disabled, or insufficiently
permissioned User. AI rejects an otherwise active Storage agent when that agent has ordinary tools,
data sources, API scopes, or action capability. External execution also requires manually created
workload, model, agent, provider, and installation records.

## Proposed Change

The normal Supplier Order Policy selects an active agent whose `default_domains` contains
`storage`. Integration creates or updates one managed internal structured workload for that agent.
The managed workload is domain-scoped, has no API abilities or token bindings, uses the agent's
provider/model, and is active only while referenced by the Storage policy.

Managed structured execution is a separate constrained capability view. The executor never exposes
the agent's ordinary tools, data sources, API scopes, role access, or action capability. External
managed execution is forced through `privacy_relay` with a maximum `pseudonymized` profile; local
Ollama execution remains `local_only`. Direct external mode and full-context payloads are not
available through this shortcut. The Storage policy revision and approving administrator form the
domain-scoped approval; generic coordinator workloads retain the full governance workflow.

Nexum creates one protected User Management system actor with a stable key, invalid non-routable
email, random password, no interactive login, and only the direct permissions required by this
workflow. Storage resolves this actor internally and keeps the existing User foreign keys and
audit relationships. The actor is excluded from ordinary User Management and API lists and cannot
be invited, edited, assigned roles, disabled, or authenticated interactively.

The normal UI shows plain-language order handling, warehouse, supplier and Item behavior, AI use,
agent, business limits, and notifications. It derives the outcome from order handling and never
accepts technical AI or runtime controls from the browser. The UI explains that Supplier Order AI
receives sanitized order evidence and cannot use the selected agent's ordinary tools.

## Impact Analysis

- **Storage:** policy migration, request/action/query changes, managed actor resolution, AI agent
  selection, compact settings UI, import/finalization behavior, tests, and Knowledge.
- **Integration:** managed structured workload marker, workload provisioning, readiness and outbound
  policy branch, audit metadata, tests, and Knowledge.
- **User Management/Auth:** protected system-actor identity, interactive-login denial, list/mutation
  exclusion, tests, and Knowledge.
- **Permissions:** no technician permission is widened. The system actor receives only
  `storage.purchase_manage`, `storage.purchase_import_profile_manage`, and `documentation.create`.
- **Routes/API:** no new public route or API ability. Existing admin routes remain protected.
- **Email/Signal/Documentation/Notification:** no route or ownership change. Existing Storage calls
  and source/audit boundaries remain.
- **Queue/scheduler:** no new worker or scheduled command. Existing Supplier Orders runtime remains.

Risks include accidentally exposing broad agent capabilities, silently widening external AI data,
creating a login-capable service identity, stale managed workloads, and losing audit attribution.
The strict structured executor, forced privacy route/profile, no-token/no-ability invariant,
non-login system actor, stable managed key, policy reference, tests, and metadata audit address
these risks.

## Data And Migration Plan

Add nullable managed-purpose metadata to `ai_workload_profiles`, nullable `ai_agent_id` to the
Storage policy, and protected system-actor fields to `user_management`. Backfill `ai_agent_id` from
an existing linked workload where possible. Keep `automation_user_id` and
`ai_workload_profile_id` for compatibility and audit, but make both implementation-managed.

The migration creates no external request and does not activate AI. Existing policy revision 3
remains shadow/off until an administrator saves the simplified policy. Rollback restores the old UI
contract but retains historical actor/workload rows until a reviewed forward cleanup.

## Testing Plan

- A Storage-domain agent with ordinary capabilities can back a managed structured workload, while
  those capabilities never reach the provider request.
- Non-Storage, inactive, provider-less, model-less, or secret-less agents are rejected clearly.
- Managed external workloads force privacy relay/pseudonymized and reject direct/full-context use.
- Generic internal/coordinator workloads retain their existing governance behavior.
- The system actor is idempotent, permission-minimal, hidden from normal user/API lists, immutable,
  and unable to authenticate.
- Policy save provisions actor/workload without receiving their IDs from the browser.
- AI off deactivates the managed workload; changing agents moves the reference safely.
- Automatic Item/profile/PO actions use the system actor and retain current hard gates.
- UI coverage proves the plain-language normal fields, honest readiness messages, and absence of
  Automation User, Internal Workload, learning, consensus, provider, timeout, token, retry, and JSON
  controls.
- HTTP coverage proves forged technical fields are replaced by the server-owned safe preset.
- Run focused User Management, Integration, and Storage suites, then the full Storage suite and
  relevant broad tests on Dev.

## Documentation Plan

Update Storage Supplier Order Automation, Integration AI governance, User Management Knowledge,
`docs/TODO.md`, the superseding ADR, Feature Slices, and `docs/human-review.md`. Sync materially
updated Knowledge sources after verification.

## Open Questions

None. The approved scope is Supplier Order Imports. The managed mechanism may be reused by another
domain only through a later approved RFC.

## Approval

Approved by Svein Tore on 2026-08-10 in the Codex task. The approved product direction is one
Storage agent, AI as deterministic fallback, automatic constrained execution, no selectable human
automation User, automatic workload management, and a compact normal settings flow.
