# RFC: Own Workday Through The Existing Nexum Gateway Connection

Status: Approved
Date: 2026-10-30
Owner: Codex
Related: Issues #385 / #386; HR-2026-10-30-NEXUM-GATEWAY-WORKDAY

## Context

The existing ChatGPT plugin connects to LiteLLM's nexumpsa registration. The shared legacy
upstream credential does not identify the employee operating their own Workday. A separate
nexumworkday registration was introduced previously, but the user explicitly requires the existing
Nexum connection and plugin, without a separate employee-facing Workday/LiteLLM access setup.
The old personal token for Norum is absent; current reads fail upstream 401.

## Goals

Use the existing plugin, gateway authentication and nexumpsa registration. Preserve the verified
caller's employee identity for Workday, its own work-plan context and relevant plan-block actions.
Employees do not manage another LiteLLM key or select another Workday integration.
Keep native actor, scope, ownership, validation, version and idempotency checks authoritative.
Fix Jo Eskil's explicitly reported profile UTC/Oslo conflict without rewriting historical time.

## Non-Goals

No acting_user_id field, shared-account impersonation or new PSA delegation/authentication protocol.
No migration of unrelated shared PSA domains, new login system, broad native grants, employee time
fabrication, Main merge/deployment or unrelated provider/runtime activation.

## Current Behavior

The main server has static credentials and no personal bindings, although its allowed tool set
already includes Workday operations. The technician team lacks these main-server tool grants.
The separate server has eleven own-day tools and personal bindings, but the employee credential
is invalid. The generator's request-body/idempotency repair is restricted to that separate server.
Workday API correctly normalizes record-local times; the stored Jo Eskil profile is UTC while
legacy preferences and Calendar are Europe/Oslo. A saved day keeps its original timezone.

## Proposed Change

Apply the existing personal credential store to employee-sensitive operations within nexumpsa,
selected by the gateway-authenticated user ID. A narrowly scoped gateway adapter resolves the
stored user/server credential and makes it the authoritative upstream Authorization header.
Missing identity/binding fails closed; client-supplied upstream headers and the shared credential
cannot override it. Other domains retain their current configuration.

Provision only exact verified existing employee bindings needed for this incident, using personal
Nexum tokens with the existing three Workday scopes. Reuse the owner's independently verified
personal credential for the owner binding. Keep broader grants out of scope.
Permit the eleven supported own-day tools in the existing technician grant where native rights
already allow them. Identity-sensitive work-plan calls use personal identity too; their separate
API scopes remain required and are not automatically added.

Extend resolved request-body schemas and stable Idempotency-Key forwarding to the corresponding
main-server operations. Preserve existing tools/aliases, grants, credentials and provider checks.
After main-route verification, retire the obsolete separate route without deleting recovery state.

Use the existing UserWorkPlan action to align Jo Eskil's profile to Europe/Oslo with the current
opaque revision and complete unchanged weekly hours. Preserve all five custom Calendar exceptions
and every existing Workday/revision/receipt. Keep UTC storage and elapsed/billing minutes unchanged.
The prior display formatter and eight views remain tested Dev source; Main promotion is separate.

## Impact Analysis

Gateway: the mounted manager adapter, versioned spec, existing main-server registration/team and
encrypted personal user/server bindings. PSA: supported token issuance and existing work-plan action;
no new route, permission definition, schema or delegation contract. Existing unrelated domain access
does not imply that complete identity migration has occurred. No queue/scheduler/build is required;
gateway adapter/spec changes require a restart and cold public verification.

## Data And Migration Plan

Take restrictive, bounded preimages of gateway source/spec and exact touched registration/team/
credential rows. Transfer any new token encrypted to the gateway and never print/store plaintext.
Verify exact native user and existing permissions before creation; keep credentials out of evidence.
No PSA schema migration. Back up affected profile/projection rows before the supported correction.
Rollback only owned changes, revoke only newly issued tokens, preserve subsequent contributor work.
Do not reverse intentional historical changes or replay employee work.

## Testing Plan

Meaningful adapter tests: exact target allowlist, normal-operation preservation, authoritative
personal credential, no fallback/header override, missing user/binding, per-user separation and
concurrent request isolation. Schema/header tests cover required bodies, valid/invalid idempotency
and isolation of other servers. Compile the patched manager.
Public cold main-route list/entry reads under the existing user/team and OAuth admission where
available; compare native identity/context and ensure admin/cross-employee failures remain denied.
Use a reversible synthetic Dev employee/day for valid Save/retry/separate read-back, not real time.
Run UserWorkPlan, UserDateTime and WorkdaySaveValidation regressions on authoritative Dev.
Read production profile/plan and unchanged history back independently after the correction.
Actual employee ChatGPT/browser acceptance remains human review.

## Documentation Plan

Update TODO, incident plan, API consumer guidance and affected Knowledge source. Publish relevant
employee instructions to existing BookStack articles, preserve access and read back actual content.
Maintain gateway operational context and a bounded rollback/evidence manifest.

## Open Questions

No new product decision is required for these existing own-employee semantics. If exact employee
identity cannot be verified from existing bindings, leave that account blocked and report it.
Historical saved-day timezone changes and unrelated domain credential migration are separate scope.

## Approval

Svein Tore explicitly required the existing Nexum connection in LiteLLM and existing ChatGPT plugin,
approved preserving verified personal employee identity, then instructed: "Ja kjør på og så med
dette tidssone feilen må også fixes" on 2026-10-30. This authorizes the stated scope and reversible
implementation defaults. It does not mark human review completed or authorize Main operations.
