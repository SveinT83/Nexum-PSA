# ADR: Storage API Commands And Current Authority

Status: Accepted
Date: 2026-10-26
Decision Makers: Codex under Svein Tore's full existing-browser API authorization.
Parent: docs/rfc/2026-10-26-storage-full-api-parity.md

## Context
Storage API coverage was incomplete and bearer scopes alone could outlive current
staff permissions. Retried inventory, import and profile actions must not duplicate
effects or turn an unknown AI result into a fresh execution.

## Decision
Declare Storage routes in its routes.php and retain api.php as loader only.
Use fresh internal-user/browser permissions together with explicit token scopes.
Pure API reads do not bootstrap warehouses or policies. Narrow receiving and edit
contexts reuse shared browser queries without widening inventory/admin access.
Share canonical supplier master data, queries, profile creation and all domain actions.
Add an encrypted durable API command ledger and native mutex. Local writes and result
receipts commit together; root locks precede supplied-revision checks. External claims
commit before execution, and failed/unknown handoffs keep the original key.
Retained results record and recheck every additional read authority used to produce
them, including import evidence and Ticket context, on replay and operation lookup.
AI repair and API automation execution have separate default-off runtime switches.

## Rationale
This follows the existing domain API command model while preserving Storage's
immutable receipt ledger and supplier-order workflow. Shared actions keep mailbox,
supplier identity, stock, Ticket/Economy and profile validation ownership intact.
A token is a limit on present user authority, not a replacement for it.

## Consequences
One additive migration; preserve command receipts and APP_KEY on recovery.
Global API serialization favors integrity over throughput; browser domain locks remain
authoritative and are also held before API revision checks. Production load is unmeasured.
Command completion is distinct from asynchronous import/provider completion.
No existing grants, provider switches, workers, supplier records or numbering are changed.
Read-only profile/picking scopes and narrow form contexts support limited operators.

## Alternatives Considered
Token-only access was rejected because current user revocation must apply.
Separate API business implementations were rejected because they would bypass shared
inventory/import/profile rules. Process/cache-only replay was rejected because it
does not survive worker or process interruption. New supplier CRUD and numbering
redesign were excluded because existing domain ownership and #286 work must be preserved.

## Follow-Up
Implementation, affected regressions, native/HTTPS/Swagger verification and actual
handbook publication/read-back are complete on Dev; see the verification report.
Human checklist is Reviewed by Svein Tore (2026-10-26). Main promotion is authorized;
production rollout/provider
activation and #286 dependency reconciliation remain with Svein Tore.
