# Workday Gateway Read-Back - 30 October 2026

Status: Historical diagnostic record; superseded by the verified delivery below.
Owner: Codex (diagnosis); Svein Tore (decide replacement of missing personal credential).
Related: GitHub #385 / #386; HR-2026-10-29-LOCAL-TIME remains Pending.

## Diagnostic evidence at the initial investigation

The owner relayed Norum's 29 October 13:21 report that workdayIndex was listed but denied.
The gateway's existing operational record documents a later repair at 13:52 Oslo on that day:
a separate personal BYOK server nexumworkday and eleven own-Workday tools, with successful
public list/date-entry reads. This later repair does not establish current availability.

Fresh public gateway calls on 30 October under the existing Norum/Vegard technician user/team
now return HTTP 200 tool envelopes with isError=true and upstream HTTP 401 for BOTH
workdayindex and workdayentry (date 2026-10-30). Therefore these are failed reads, not success.
The cold MCP catalog still contains 50 tools, including eleven nexumworkday-* tools. The Save
schema retains the Idempotency-Key argument. Gateway administration remains denied with 403.

Strict host-key-verified production SSH and minimal Laravel read-only checks confirm:
- Native employee user 13 matches the previously verified exact account, is active and is not a system actor.
- workday.view_own, workday.manage_own and workday.confirm_own are still granted.
- Workday remains enabled.
- Previously issued personal token 16 no longer exists. The employee has zero personal tokens.
  Production currently has eight tokens in total, maximum existing token ID 15.
- No matching token activity audit entry or token-16 administration-path access-log match was found
  for 29-30 October. This does not prove who removed it, when, why, or whether a restore occurred.

The current block is invalid/missing personal authentication, not missing native Workday grants.
Do not restore a removed credential without resolving whether its removal was intentional.
Svein approved replacement on 30 October, then clarified the required product direction:
use the existing Nexum connection in LiteLLM directly through the existing ChatGPT plugin;
no separate employee-facing Workday/LiteLLM access. The replacement was not executed.
A fresh gateway metadata read confirms nexumpsa is not BYOK, has a static credential and
zero personal bindings. Resolve this existing connection's verified employee identity design
before implementation. This direction does not authorize a shared actor to impersonate employees.
Do not widen team/native grants or fall back to the shared legacy nexumpsa credential.

## Verification and boundaries

Authoritative Dev: WorkdaySaveValidationTest passed 9 tests / 163 assertions (80.49 seconds).
It covers Oslo winter/summer completed-time acceptance, future-time rejection without partial
writes, exact 422 fields, own identity/480-vs-300 plans, retries and separate read-back.
This is not a full-suite result or evidence that the reported original 422 has been explained.

Three bounded public gateway probes each created a five-minute diagnostic key for the existing
user/team and revoked it in finally; all three revocations succeeded. No native token, personal
binding, real employee time, profile/timezone, grant, runtime switch or production source changed.
The first probe stopped on failed reads. The second intentionally continued to inspect the
catalog; its generic VERIFIED_READ_ONLY marker is NOT read success because both business calls
reported errors. Use the explicit upstream 401 results, independently corroborated by production.
No valid employee-time write was attempted. No GitHub comment was posted and both Issues stay open.
No commit, push, Main merge or deployment.

## Original next actions (superseded by the completed delivery)

1. Follow Svein's existing Nexum connection -> LiteLLM -> existing ChatGPT plugin direction.
   Credential replacement is authorized within that direction, not a separate access workflow.
2. Define the verified employee identity path on the existing shared nexumpsa connection before
   changing authentication. Integration/authorization redesign requires an approved RFC under
   AGENTS.md and docs/processes/rfc-process.md. No implementation or new credential has run.
   Then verify own list/context/save/read-back and cross-employee/revocation boundaries.
   A real valid Workday write still needs an authorized synthetic or employee acceptance scenario.
3. #386's original three legacy draft 422 responses still require their exact structured field
   errors/payload shape and verified caller identity. Today's 401 is separate evidence.
4. #385's existing display fix is Done On Dev. Main/production availability, the Jo Eskil
   profile/work-plan UTC versus Oslo conflict and human acceptance remain unchanged and pending.
5. HR-2026-10-29-LOCAL-TIME stays Pending. No automated result marks a human check Reviewed.

## Current disposition

The existing main Nexum route is repaired and verified; PR #387 is merged and the scoped
production patch is independently verified. See 2026-10-30-workday-gateway-timezone-delivery.md
and its verification JSON. Human acceptance and historical 422 evidence remain separate.
