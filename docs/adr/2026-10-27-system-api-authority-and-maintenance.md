# ADR: System API Authority And Maintenance Outcomes

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore (approved 2026-10-27), Codex
Parent: ../rfc/2026-10-27-system-full-api-parity.md

## Context
System browser settings and destructive queue operations have no official API.
Native authority is permission-based. Console output is not durable execution proof;
queue tables do not prove worker health, and retries can cause external effects.
Branding is a shared settings aggregate with non-transactional file side effects.

## Decision
System owns routes/actions/queries/storage; reuse current actor policy plus separate
granular Sanctum scopes. Share validation/locking between browser/API. Use opaque
settings versions and durable actor/token-bound receipts. Stage logos and commit
settings before deleting obsolete files. Queue actions require explicit targets,
expiring preview/confirmation, durable no-repeat claims and default-off API execution.
Use fixed supported Laravel commands only. Report signal issued, re-enqueued,
completed, failed and unresolved distinctly; never infer process restart or delivery.
Preserve driver truth and verify adapters before declaring deployment parity.
No job payload/raw exception/credential, arbitrary command or new scheduler API.

## Rationale
Full parity needs positive outcomes with equivalent authority and replay-safe effects.
Existing owners remain authoritative. API availability must not release historical
backlog or invent health. Driver effects cannot be rolled back by a receipt transaction.

## Consequences
Additive command/confirmation storage and native concurrency testing are needed.
Crash-after-dispatch stays unresolved until reconciled, never automatically retried.
Scopes do not grant native rights or activate runtime work. Settings-only actors
remain supported. Unknown outcomes and failing baseline tests remain visible.

## Alternatives Considered
Read-only API fails operational parity. Browser redirects are not typed contracts.
Generic Artisan/shell endpoints add unrelated authority. Blind timeout retry duplicates
effects. Hard-coded Admin-role gates exclude permission-authorized actors.

## Follow-Up
RFC/ADR approved, SY-S1 through SY-S4 complete on Dev, baseline failures resolved,
19 contracts/read-backs and actual handbook/access verified. Keep
HR-2026-10-27-SYSTEM-API Pending until named human acceptance. #262 stays separate.
