# Feature Slices: Full Knowledge API Parity

Status: Done On Dev (all six scopes); Merged to Main in PR #331; production unchanged.
Date: 2026-10-27
Parent: ../rfc/2026-10-27-knowledge-full-api-parity.md
Owner: Codex. Reviewer/release owner: Svein Tore.
Human-review entry: HR-2026-10-27-KNOWLEDGE-API (Reviewed by Svein Tore, 2026-10-27).

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
real customer publication, production deployment or provider activation. Main promotion
was separately authorized by Svein Tore and is complete in PR #331.

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
Svein Tore has explicitly accepted this API scope; its checklist is Reviewed.
Separate older revision/sync reviews and production authorization remain outstanding.
Main merge is explicitly authorized, without production rollout or runtime activation.

## Verified Main Merge (2026-10-27)

https://github.com/SveinT83/Nexum-PSA/pull/331 is merged at 5dab62d56c7663368de11d0f5961e08d7e269bd3.
The merged tree equals the tested candidate: c134e03cc5cb5c1be5193f15993b5906d62f4102.
No Main implementation action remains. Human scenarios and production gates remain separate.
This status-only follow-up does not change runtime code; application tests were not rerun.

## Named Human Acceptance (2026-10-27)

Svein Tore explicitly accepted the completed Knowledge API handoff with
"Godkjenner og merge til main". HR-2026-10-27-KNOWLEDGE-API is Reviewed.
This records the named reviewer's acceptance of the presented scope; individual
scenario executions were not separately reported and are not invented. Main was
already merged in PR #331, with merge evidence reconciled in PR #332.
The older HR-2026-09-04-001 and HR-2026-09-03-003 reviews remain independent and
Pending. This acceptance does not authorize production deployment or provider activation.
This follow-up changes documentation/acceptance status only; application tests were
not rerun because executable code, configuration and OpenAPI are unchanged.
