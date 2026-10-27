# Feature Slices: Full Knowledge API Parity

Status: Done On Dev (all six scopes); isolated Main candidate verified; merge authorized.
Date: 2026-10-27
Parent: ../rfc/2026-10-27-knowledge-full-api-parity.md
Owner: Codex. Reviewer/release owner: Svein Tore.
Human-review entry: HR-2026-10-27-KNOWLEDGE-API (Pending).

## Goal And User-Visible Behavior
All supported Knowledge web and portal operations are available through a typed official API
with the same actor/object authority, data, validation and operational side effects.

## Sequential Slices
1. Route ownership, current actor/token authorization, bounded list/detail/context/lookups,
   structure CRUD, article proposals and safe view/read contracts.
2. Encrypted commands, replay/preconditions and operation lookup; no sensitive plain-text receipts.
3. Exact revision history/preview/approval/rejection/publication/retry/rollback and Ticket isolation.
4. Conflict state/actions and existing library Sync Now with provider safeguards and read-back truth.
5. Settings, article tag creation/assignment/removal and portal current-membership reads.
6. Complete typed Swagger, exact coverage tests, affected regression/native/HTTPS verification,
   handbook publication/read-back and honest review/deployment handoff.

Every slice includes its tests and relevant source documentation, rather than postponing these
until the final batch. Each slice must be fully verified before continuing to the next one.

## Data Touched
Existing Knowledge/hierarchy/revision/tag/default/sync tables through shared actions; one
additive local command/lock schema. No business backfill or role grants.

## Out Of Scope
#265/#261 audiences/AI retrieval, #275 Documentation revisions, general BookStack administration,
real customer publication, Main promotion, production deployment or provider activation.

## Permissions And Tests
Use the parent RFC's explicit native/token/Ticket/portal contracts. Cover positive lifecycle,
negative isolation/revocation, replay/ambiguity, concurrent changes, provider fake read-back,
actual response schemas, exact runtime routes and affected module regressions.

## Done Criteria
- [x] Every observed route and embedded Livewire/Integration workflow maps to a verified API.
- [x] Every supported HTTP method has complete typed request/response/error coverage.
- [x] Current actor/token/object, narrow Ticket and portal access remain enforced.
- [x] Replay, concurrency, publication and provider outcomes have exact independent evidence.
- [x] Required Knowledge and actual BookStack content/access are verified or concrete blockers tracked.
- [x] TODO/coverage/evidence/HR are current; pending manual checks and Dev/Main/production are explicit.

## Verification And Remaining Human Gate

See plans/2026-10-27-knowledge-api-verification.md/.json. All supported operations,
contracts, source/BookStack read-back and Dev migration/native/HTTPS evidence are verified.
Human scenarios remain Pending; only Svein Tore may mark the checklist Reviewed.
Main merge is explicitly authorized, without production rollout or runtime activation.
