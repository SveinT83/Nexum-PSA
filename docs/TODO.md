# tdPSA Development TODO

## Main Release Assembly (2026-10-06)

Status: Release Verified; production acceptance pending. Owner: Codex; production reviewer: Svein Tore.
User authorized Main merge of SSO, Workday, Tripletex and other completed Dev changes,
with remaining practical acceptance in production. Unfinished Vault remains excluded.
Next: deliver the authorized Main merge, then Svein deploys and performs the recorded production checks.
Review: HR-2026-10-06-RELEASE; details: plans/2026-10-06-main-release-verification.md.
Existing feature review items remain open; merge authorization is not test completion.


## Internal Keycloak SSO (2026-10-05; Dev pilot verified 2026-10-06)

Status: Done On Dev for the bounded pilot; remaining human checks before general rollout.
RFC approved by Svein Tore on 2026-10-05. Migration applied on Dev in batch 17; SSO is enabled for the controlled Dev pilot.
Owner: Codex (client setup and guided pilot); Svein Tore (interactive sign-in and human review).
Related: [RFC](rfc/2026-10-05-internal-keycloak-sso.md).
Svein confirmed internal employees only; Customer Portal keeps current login.
Live Keycloak discovery passed normal TLS validation for the tronderdata issuer.
Implemented: explicit linking to existing accounts, local role/status authority, retained
local login and existing MFA/step-up requirements; no automatic provisioning.
Next action: complete remaining local-MFA/emergency-admin/manual review in HR-2026-10-05-SSO during Svein's production acceptance authorized under HR-2026-10-06-RELEASE. Client setup, explicit linking, real SSO login and both logout directions are verified.
Pilot evidence: docs/plans/2026-10-06-internal-sso-pilot-verification.md.
Svein Tore confirmed the intended work account and successful SSO login on 2026-10-06; the remaining human-review checks stay open.
Scope ownership: new Sso classes/tables/tests plus additive route/provider/view hooks.
Preserve all existing UserSecurity writers, Vault classes, Fortify actions and Core User.
Use the existing guarded two-factor capture/controller and no remember-token writes.
No Vault activation, cutover or security-policy change is part of SSO.
Dependency: HR-2026-09-04-003 / Vault UserSecurity remains active and is not superseded.
Slices 01-03: Done On Dev for the bounded pilot. Human review remains separate.
Verification: docs/plans/2026-10-05-internal-sso-verification.md (24 SSO tests / 252 assertions;
existing MFA/portal regressions pass; native migration and HTTPS smoke checked).
Additional verification 2026-10-06: 222 tests / 1,535 assertions passed across the broad authentication/portal run and new isolated provider-outage emergency-admin test; browser layout, field masking and wrong-password denial checks passed. Live MFA/recovery and real emergency-admin checks still need suitable human-operated accounts.
Human-review checklist HR-2026-10-05-SSO remains In Review; Main merge is explicitly authorized
under HR-2026-10-06-RELEASE, with practical production acceptance still outstanding.
Dependency audit: the two previously reported league/commonmark advisories are remediated
by the 2026-10-06 release patch to 2.10.2. Strict locked audit reports zero advisories.


## Implementation and deletion approval - 2026-10-05

Svein Tore explicitly authorized implementation and automatic deletion synchronization in both
directions. Create, update and delete are now in scope. Deletion of a mapped group propagates
only after baseline/concurrency and lock checks; removing one local interval updates the
remaining aggregate, deleting the last one removes the linked provider row. Preserve an
audited tombstone so retries/restores do not resurrect deleted time. Never treat an incomplete
list, inaccessible record, 403 or retention expiry as deletion. An external deletion requires
a complete authorized rescan plus identity/absence verification; delete-versus-edit conflicts
remain operator exceptions. Provider period approval/locks are never bypassed.
Implementation: The bounded time-sync pilot is implemented on Dev: explicit employee mapping, effective Save, duration editing, a working on/off switch, durable bidirectional create/update/delete reconciliation and scheduled scans. Live CRUD and import editing were verified on Svein's explicitly authorized employee; all synthetic provider time was removed. Remaining broader rollout checks are listed in the pilot verification and human-review entry. Product approval does
not authorize Main or production actions. HR-2026-10-05-WORKDAY-TRIPLETEX is In Review; only the initial save/company check has human confirmation.
The earlier documentation-only and undecided-deletion statements below are historical and
superseded by this explicit approval.


## Automatic Workday And Tripletex Time Sync (2026-10-05)

Status: Functional bounded pilot Done On Dev; broader rollout checks remain open.
[Approved RFC](rfc/2026-10-05-workday-tripletex-automatic-time-sync.md),
[ADR](adr/2026-10-05-workday-tripletex-save-and-sync.md),
[pilot slice](feature-slices/2026-10-05-workday-tripletex-functional-pilot.md),
[verification](plans/2026-10-05-tripletex-time-sync-verification.md).

Implemented and verified: one account, encrypted credentials, explicit employee/activity/start-date
mapping, effective Save without confirmation, duration editing, automatic two-way create/update/delete,
durable recovery/baselines, the working on/off switch, reports/reminders and five-minute scheduled scans.
Live tests used Svein's explicitly authorized employee; all synthetic provider hours were removed.
Final complete Dev suite: **223 tests / 2021 assertions passed**.
The OS scheduler executed the connector at 21:00:07 UTC; the account was then paused again.
Runtime deployment flags are enabled on Dev; routine synchronization is controlled by the account switch.

- [ ] Human UI/pilot review: HR-2026-10-05-WORKDAY-TRIPLETEX remains In Review and blocks Main/production promotion.
- [ ] Automatic overnight date splitting. For the pilot, split clock entries at midnight.
- [ ] Dedicated conflict-resolution and established-mapping migration interfaces.
- [ ] Large-volume lease/timeout/backoff verification and full restore drills.
- [ ] Live locked-period/project entitlement tests; company had no projects in this pilot.
- [ ] Confirm the commercial authentication/distribution model with Tripletex before customer rollout.

The original four-slice target remains the complete rollout plan; the functional vertical pilot
does not close these remaining items. The older date-entry/confirmation work is preserved for
legacy API compatibility, while ordinary current UI saves are effective immediately.


## Daily Workday Confirmation (2026-10-01)

API parity follow-up (2026-10-05): Done On Dev. Owner: Codex.
Svein Tore approved the calendar/modal UX. The date-entry API now returns saved state plus the
effective plan and first free time without creating a day. Minute create/add/edit/remove and
existing confirmation/correction writes are verified with personal bearer tokens.
35 operations match the published OpenAPI; 73 tests / 907 assertions passed across API and
regression runs. Consumer examples and later MCP operation mapping are documented.
See [API parity verification](plans/2026-10-05-workday-entry-api-verification.md).
Next action: remaining explicit pilot/operational checks under HR-2026-10-01-WORKDAY.
MCP server/connection implementation is a future task, as requested.

Owner: Svein Tore / Codex. Status: calendar timeline and modal entry Done On Dev;
human review In Review (2026-10-05). Approved RFC/ADR; Slices 01-09 remain Done On Dev (API scope).
The day calendar fills the workspace. Clicking free time opens Register time; clicking a saved
block opens Edit time. The initial first-free-hour selection remains available through Time entry.
Closing retains pending values without saving; server errors reopen the modal with input preserved.
Exact-minute block proportions, overlap rejection and existing versioned writes remain.
Evidence: all 60 focused Laravel tests pass across recorded runs; 8 JavaScript tests pass.
Synthetic browser checks cover opening, edit/new payload preservation, closing/Escape, focus
return, pending input, overlap and server validation reopening. No employee data was written.
See [modal verification](plans/2026-10-05-workday-modal-verification.md),
[calendar verification](plans/2026-10-04-workday-calendar-timeline-verification.md)
and [review guide](plans/2026-10-03-workday-dev-pilot-review.md).
Calendar/modal UX approved by Svein Tore on 2026-10-05; the API follow-up above is verified.
Authenticated end-to-end browser/device and remaining pilot checks are still open.
Dev employee activation remains on; retention execution, Main and production remain separate.

Scope revision (2026-10-02, Svein): prioritize complete PSA APIs; postpone MCP. LiteLLM replaces
NexumMCP as the intended external direction. Slice 09 verifies/contracts the APIs without any
MCP/gateway prerequisite. Personal employee authorization and explicit confirmation remain.
Svein explicitly approved the full plan and authorized implementation on 2026-10-01.
Product direction agreed; documentation requested by Svein on 2026-10-01.
See [RFC](rfc/2026-10-01-daily-workday-confirmation.md) and
[ADR](adr/2026-10-01-workday-time-evidence-and-billing.md).
Scope: actual daily work/break confirmation and named oversight of who works on what;
this is not an invoice basis. Include existing-time reconciliation, calendar suggestions,
profile-controlled reminders and explicit Task conversion. AI suggestions are optional.
Svein confirmed on 2026-10-01: Superuser oversight through dedicated permissions reusable
by future HR roles; employee API read/write/correction/explicit-confirmation parity for
MCP/AI tools; and Workday notification choices in the user profile. Preserve attributable
employee identity, private drafts, source authorization, idempotency and persisted read-back.
PSA owns domain actions/API; future LiteLLM/MCP tooling consumes the API. Page/login monitoring,
autonomous time approval and duplicate billing remain excluded.
Svein accepted Workday ownership alongside UserManagement profiles and confirmed that
the employee's own confirmation completes the Nexum day, with no manager approval step.
Historical scope, superseded by the 2026-10-05 Workday/Tripletex RFC above:
transfer/sync confirmed actual time through Tripletex, with approval in Tripletex. Reuse DataExchange/Integration ownership, stable revision
references, explicit employee/activity mappings, duplicate prevention and external read-back.
Keep delivery and external approval separate from Nexum confirmation; surface correction
conflicts for externally approved/locked time. The connector is future work and does not
block the manual/API Workday workflow. Its capabilities and live operation are unverified.
Svein further limited the current workforce extension to simple absence and a simple
work plan. Workday owns explicit absence records; Calendar shows linked blocks and owns
recurring plan/availability behavior; UserManagement retains normal weekly working hours.
Include full/partial-day sickness, already agreed holiday/time off, a weekly plan with
dated/recurring exceptions such as education days, operational phone-duty availability,
and UI/API parity. Actual phone-provider queue control is deferred. Do not infer absence
or actual time from education blocks, and do not duplicate independently editable records.
Defer holiday requests/approval, configurable first-come-first-served priority and maximum
concurrent holiday rules, mandatory holiday periods, annual/carryover/flex balances and
advanced rota planning. Preserve the full target for later Tripletex/HR planning.
Svein set Workday time/absence retention to three years, including associated history.
Count from the work date/absence-period end; corrections and sync retries do not extend
retention. Include expiry of owned copies and restore handling, while preserving source-
domain records and external Tripletex data under their own policies. No cleanup is active.
The product questions raised in this discussion are settled.
The [implementation plan](plans/2026-10-01-workday-implementation-plan.md) is prepared.
It includes the discovered profile-hours/Calendar-default reconciliation prerequisite,
per-slice API parity, storage/access/version contracts, focused test evidence requirements
and deployment/rollback steps. Open worklog Issues #288/#289/#290 remain separate;
no GitHub changes or coordinator grant expansion are included.
Slice 01 is implemented and verified on Dev: 75 tests / 599 assertions; six API operations
read back over trusted Dev HTTPS. No migrations or normal-user activation. See
[verification](plans/2026-10-01-workday-slice-01-verification.md) for files, commands and limits.
Slice 02 is Done On Dev (2026-10-02): manual days, own confirmation/correction, settings and nine API operations; 74 tests / 596 assertions passed. Two additive Dev migrations applied; both activation switches remain off. See [verification](plans/2026-10-02-workday-slice-02-verification.md). Slice 03 is Done On Dev (2026-10-02): own simple absence, one neutral Calendar projection, work-conflict warnings and six API operations; 121 tests / 960 assertions passed. Two additional Dev migrations applied; three new tables empty and both activation switches off. See [verification](plans/2026-10-02-workday-slice-03-verification.md). Slice 04 is Done On Dev (2026-10-02): source reconciliation and two API operations; 271 distinct tests / 2236 latest assertions passed. One additive Dev migration applied; all Workday tables empty and both switches off. See [verification](plans/2026-10-02-workday-slice-04-verification.md). Next: authenticated pilot browser verification and human review; MCP/LiteLLM tooling is deferred. Authoritative Dev and GitHub preflight confirmed no competing Workday item.
Slice 05 delivery (2026-10-02): confirmed overview, detail/history, Report discovery and reusable oversight rights are Done On Dev, default-off. [Verification](plans/2026-10-02-workday-slice-05-verification.md) records 136 passing tests / 1392 assertions, three API reads and the Superuser-only Dev permission migration. Existing tokens were not changed. Next: authenticated pilot browser verification and human review; MCP/LiteLLM tooling is deferred. Human review remains Pending until the complete pilot is ready.
Slice 06 delivery (2026-10-02): personal plan-aware reminders, Profile notification choices and four own API operations are Done On Dev, default-off. [Verification](plans/2026-10-02-workday-slice-06-verification.md) records 219 distinct tests / 2091 assertions, synthetic channel delivery, verified external scheduler and one additive Dev migration. Both switches remain off and no employee notifications were sent. Next: authenticated pilot browser verification and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY remains Pending until complete-pilot review.
Slice 07 delivery (2026-10-02): explicit internal Task conversion is Done On Dev, default-off. [Verification](plans/2026-10-02-workday-slice-07-verification.md) records 187 distinct tests / 1618 assertions, three API operations, transactional duplicate prevention and one additive Dev migration. No Task/time/billing fixtures or token changes were retained. Next: authenticated pilot browser verification and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY remains Pending until complete-pilot review.

Slice 08 delivery (2026-10-02): three-year cleanup and restore operations are Done On Dev, default-off. [Verification](plans/2026-10-02-workday-slice-08-verification.md) records 228 distinct passing tests after the reminder metadata assertion update, original-source preservation, synthetic restore rehearsal and a fresh native employee-row lock probe. Metadata-only UI/API preview is implemented. WORKDAY_RETENTION_ENABLED is independent and false; no live purge, migration, employee activation or token change occurred. Dev inventory has zero Workday roots and 105 historical diagnostic copies, so restore_ready correctly remains false pending approved cleanup. External backup rotation/archive handling remains an explicit pre-activation check. Next: authenticated pilot browser verification and human review; MCP/LiteLLM tooling is deferred. HR-2026-10-01-WORKDAY stays Pending until complete-pilot review.

Slice 09 delivery (2026-10-02): all 34 operations have verified published OpenAPI and exact scopes;
196 tests / 1912 assertions passed, including real personal bearer workflows and anonymous/unscoped
denial for every operation. See [verification](plans/2026-10-02-workday-slice-09-api-verification.md)
and [consumer guide](integrations/workday-api-consumer.md). No migration or activation. MCP is
explicitly deferred by Svein and LiteLLM is the intended external direction. No gateway dependency
blocks the API handoff. HR-2026-10-01-WORKDAY remains Pending for the combined pilot.

Shared Dev and GitHub scope rechecked before Slice 02; no competing Workday runtime implementation was present.
Existing blocked Vault work remains separate; preserve its shared-file changes.
Human-review checklist: HR-2026-10-01-WORKDAY (Pending, Dev active; authenticated browser verification awaits login); blocks
Main promotion/merge and production migration/deployment/activation, not approved
Dev-only implementation/testing. No runtime approval is inferred from this plan.
Svein requested continued implementation and manual review only when the complete pilot needs it.

All slices below are owned by Codex with Svein as product/review owner. Slice 01 is Done On Dev;
02-09 are Done On Dev (API scope). Employee pilot active on Dev since 2026-10-03; cleanup remains off.
They share HR-2026-10-01-WORKDAY and execute one at a time in the listed order.

| Slice | Planned outcome | Status |
| --- | --- | --- |
| [01](feature-slices/2026-10-01-workday-01-simple-work-plan.md) | Simple Work Plan And One Working-Hours Source | Done On Dev; employee pilot active |
| [02](feature-slices/2026-10-01-workday-02-manual-days-and-api.md) | Manual Workdays, Confirmation And Employee API | Done On Dev; employee pilot active |
| [03](feature-slices/2026-10-01-workday-03-absence-and-calendar.md) | Simple Absence And Consistent Calendar Display | Done On Dev; employee pilot active |
| [04](feature-slices/2026-10-01-workday-04-source-reconciliation.md) | Existing Time And Calendar Evidence Reconciliation | Done On Dev; employee pilot active |
| [05](feature-slices/2026-10-01-workday-05-confirmed-oversight.md) | Confirmed-Time Oversight And Reusable Rights | Done On Dev; employee pilot active |
| [06](feature-slices/2026-10-01-workday-06-profile-reminders.md) | Workday Reminders And Profile Notification Choices | Done On Dev; employee pilot active |
| [07](feature-slices/2026-10-01-workday-07-explicit-task-conversion.md) | Explicit Internal Task Conversion Without Duplicate Time | Done On Dev; employee pilot active |
| [08](feature-slices/2026-10-01-workday-08-three-year-retention.md) | Three-Year Retention And Recovery Operations | Done On Dev; preview available, cleanup off |
| [09](feature-slices/2026-10-01-workday-09-mcp-and-release-verification.md) | Employee API Contract And Consumer Handoff | Done On Dev; API pilot active |

## External API Consumer Ownership (2026-10-01; revised 2026-10-02)

Owner: Svein / Codex. Status: Product-direction documentation Done On Dev.
Svein removed the PSA-owned MCP server and its Secrets/Vault purpose.
On 2026-10-02, Svein selected LiteLLM instead of NexumMCP and deferred MCP work; current
delivery focuses on PSA APIs without any external gateway/MCP verification prerequisite.
See [the accepted decision](adr/2026-10-01-separate-nexummcp-ownership.md).
MCP-only requirements are removed from PSA scope; API consumers remain supported.
Vault, existing credentials and independent PSA integration/security needs remain.
Before queued #270/#272 runtime work resumes, Codex must identify the concrete
PSA-only requirements and exclude MCP-only dependencies. This supersedes conflicting
MCP assumptions in older rows below, not their independent PSA requirements.
The existing Vault Slice 04 onboarding blocker and human-review gates remain open.
Verification: source/document inventory and documentation read-back; no runtime change,
Laravel tests, migration, activation, GitHub publication, commit or deployment.

This file is the shared coordination list for tdPSA development. Use it to delegate work across contributors and keep implementation, tests, and Knowledge/BookStack documentation moving together.

## Controlled History And Commercial Time Export (2026-09-27)

Owner: Codex / Svein. Status: Done On Dev / Human Review And Target Activation Pending. Svein approved the full RFC
`docs/rfc/2026-09-27-controlled-history-and-commercial-time-export.md` on 2026-09-27.
One workstream: Report pagination/OpenAPI repair, Integration context enforcement, Commercial
quick consumption and pseudonymous contract links, then verified NexumMCP contract handoff.
Final evidence: 98 distinct tests / 855 latest assertions pass across focused contract, Commercial,
Task, Ticket time and API-key suites. Generated OpenAPI is read back over trusted Dev HTTPS and
matches the source contract; all four anonymous worklog routes return 401. No full-app suite run.
No production settings, token grants, catalog updates, commit or deployment are included.
Human-review checklist: HR-2026-09-27-WORKLOG (Pending; production release gate).
Next: Svein completes HR-2026-09-27-WORKLOG, then owns Main promotion and production rollout.
NexumMCP receives the contract/delta; it owns package/catalog update and target connection testing.
Target Dev policy remains off with no approved coordinator_api workload. Live production data
cannot be called verified until authorized setup, target deployment and a complete manifest pass.

## Missing Provider Message Trash Repair (2026-09-17)

Owner: Codex / Svein. Status: Done On Dev / Browser Review Pending.
User-requested Level 1 incident fix under the approved Mail provider-authoritative placement policy.
When Trash proves the selected source UID absent in its current namespace, hide only that stale
placement; retain failed-operation evidence, other placements, personal state and Ticket evidence.
Provider errors and identity drift remain visible. Existing unrelated Dev work is preserved.
Verification: 40 tests / 334 assertions pass on Dev, including Livewire and the regression that failed
before the fix. No migration/build required. Review: HR-2026-09-17-EMAIL-MISSING.
Next action: Svein verifies the browser behavior before production release. Not committed or deployed.

## Email Account Save Production Repair (2026-09-17)

Owner: Codex / Svein. Status: Done On Dev / Production Schema Repaired / Browser Review Pending.
Authorized incident repair under the approved Mail RFC. Production recorded migration 104000 but lacked the baseline table and epoch indexes, causing account creation HTTP 500.
Forward repair `2026_09_17_110000` passed 7 SQLite tests / 42 assertions, native MariaDB 1 / 12, and account form workflows 2 / 43.
Applied only this migration on Dev and production after a protected production snapshot and maintenance pause. Read-back: epochs mode, 14 baselines, 3 foreign keys, unchanged personal state; public /up HTTP 200 and maintenance off.
Next action: Svein saves the personal mailbox and confirms the real connection result under `HR-2026-09-17-EMAIL-SAVE`. Main promotion and Git push remain separate.
Recovery instructions: `app/Modules/Email/Docs/knowledge/email-unread-schema-recovery.md`.

## Working Rules

- Pick one item, add your name or initials under `Owner`, and keep the status updated.
- Finish the current registered item before starting another ordinary Issue, RFC, ADR, or Feature
  Slice. If it cannot progress, record the exact blocker, owner, next action, and resume condition
  before switching.
- Keep one primary implementation per contributor. Explicit parallel slices must be non-overlapping
  and separately registered.
- Reconcile this file with GitHub, planning-artifact status lines, human review, and actual Dev
  evidence whenever work starts, blocks, is superseded, passes verification, or completes.
- `Done On Dev` does not require commit or push. Record human review, Main promotion, migration,
  deployment, runtime activation, and production verification as separate gates.
- Keep domain code inside `app/Modules/{Domain}` and follow `AGENTS.md`, `module-architecture.md`, and `ui-guidelines.md`.
- Every completed or materially updated domain feature must update Knowledge documentation and mark it for BookStack sync.
- Add or update tests for behavior changes before marking an item done.
- Do not rewrite unrelated dirty files. Work with the current branch state.

## Active Planning Artifact Register

Current Slice 04 work (2026-09-14): authenticated planning and post-review evidence integration.

Current execution checkpoint (2026-09-18): **4 of 17 main parts Done On Dev**; 04 Blocked on pending-admin 2FA onboarding.
Owner: Codex / Svein. 04-A recovery and pending TOTP lifecycle/history verified on SQLite and both native drivers.
Active work: confirmed TOTP lifecycle and its 04-C authority-write dependency. The incompatible
three-statement reset mapping is repaired; guarded SQL/retained-readback regression passes on SQLite.
Full SQLite guards pass 9 / 110; native mysql 1 / 22 and final mariadb 1 / 23 pass.
The store now has one atomic authority/UserSecurity transaction boundary; its real guarded-write
orchestration test passes 1 / 239 with a deliberately synthetic authority coordinator. Witnessed
execution fails closed without a coordinator and ordinary resume cannot resume authority work.
The concrete approval/witness/source-guard coordinator remains unimplemented and unbound.
Broader SQLite regression completed: 894 / 31475, exit 0, no failures/errors/skips,
at /tmp/vault-authority-composition.xqpBIb/; both frozen manifests match.
Svein resolved the privileged invitation decision on 2026-09-18; RFC/Slice clarified and ADR
2026-09-18-vault-privileged-invitation-activation accepted. Acceptance now preserves pending
Admin/Superuser accounts without login while ordinary invitations retain their flow.
Boundary tests 5 / 57 and installed-guard/HTTP tests 10 / 88 pass. Acceptance matrix
/tmp/vault-invitation-acceptance.SfxDWz/ ended exit 2: SQLite 909 / 31620 passed; native mysql
failed during synthetic baseline seeding because migrated guards were already installed.
The owned test fixture now restores and attests all guards after seeding; corrected SQLite
integration passes 1 / 43. Corrected native mysql passes 1 / 43, exit 0, no failures/errors/skips.
Native mariadb also passes 1 / 43; /tmp/vault-invitation-native.peaG8S/ ended exit 0
at 2026-09-18T10:28:25Z. Both manifests matched before subsequent diagnostic test edits.
No production guard was weakened; administrator activation remains unfinished.
New blocker: second-admin activation requires two governance-ready candidates, but the pending
invitee cannot confirm TOTP through the ACTIVE/session-only enrollment path. Diagnostic tests
18 / 72 pass (/tmp/vault-pending-admin-onboarding.xml), including a ready-post-state control.
Owner/decision: Svein must approve narrowly scoped pending-account TOTP onboarding or another
explicit policy; the accepted password/pending/no-login invitation decision is not reopened.
Automation is PAUSED and read back. No running test; no security rule was relaxed.
Administrator activation through the concrete authority coordinator remains unfinished; do not
treat the accepted decision or password-establishment tests as completion of that write flow.
Caller inventory also confirms CustomerPortal invitation acceptance still uses direct user DML
and unconditional controller login; its enumerated enforced invitation boundary remains unwired.
Complete that existing 04-A flow with protected pending/no-login and ordinary/replay/rollback
coverage before enforcement. The internal invitation matrix does not prove that separate caller.
No confirmed-factor action is exposed and no main-row completion is claimed.
Workflow repair is Done On Dev. Generation/authority/actorless history still prevents closing 04-A.
See [the current delivery handoff](plans/2026-09-17-vault-delivery-workflow.md) for the
active batch, terminal evidence, acceptance criteria and exact next action. Sequence 35999 passed;
do not restart it. The linked handoff owns automation/test status. HR-2026-09-04-003 remains Pending;
this is not a practical UI review invitation. No operational migration or runtime activation.
The older dated continuation notes below are historical evidence, not current execution instructions.

Latest continuation (2026-09-14): finalized quorum creation read-back is verified on
SQLite and both native drivers. Native mysql PASS 1 / 199 (2614.47s); the durable
mariadb verification 29962 ENDED exit 0, PASS 1 / 199 (2582.19s), no failures/skips.
Logs and JUnit: /tmp/vault-quorum-verification.MXLGiY/. The earlier lost mariadb
output remains unclassified; these results replace the need to recover it.
Graph creation read-back now also requires the original standard-proof window through
plan finalization and retained factor metadata at that finalization time. Withdrawal
before use denies; later withdrawal preserves historical evidence. Existing canonical
source, audit, guard and approval classification checks remain in force.
A real migrated SQLite regression failed before this change (1 / 204 assertions,
39.70s), then the focused corrected selection passed 3 / 308 (69.47s), exec 85285
exit 0. Both changed PHP files pass Pint. Native sequence 17084 ended exit 1:
mysql failed (1 test / 176 assertions, 2477.89s); mariadb did not start.
The fixture's rollback catch masked the underlying RuntimeException with a boolean
assertion. It now rethrows unexpected exceptions; no production behavior was changed.
Diagnostic 96875 ended exit 1: SQLite 1 / 219 passed (40.91s), then mysql failed
1 / 175 (2493.87s) with PDO SQLSTATE 45000 / vault_action_plan_source_denied.
Logs remain in /tmp/vault-graph-factor-diagnostic.QpxIXq/. A shared short-lived proof
across all six scenarios was a timing dependency; each scenario now creates its own
real proof in a distinct synthetic session and asserts freshness before plan creation.
This is a fixture-isolation correction, not yet proof of the original denial's cause.
No production TTL, guard, clock or memory cap was relaxed. Corrected SQLite PASS
2 / 317 (82.26s), exec 12950 exit 0; Pint passes. Native mysql PASS 1 / 226
(3092.82s) and mariadb PASS 1 / 226 (3111.11s). Sequence 1964 ended exit 0;
durable per-driver logs/JUnit are in /tmp/vault-graph-fixture-isolation.pru8De/.
Both driver paths use the isolated MariaDB 10.11.14 service, not Oracle MySQL.
The earlier failed runs remain recorded; the corrected runs resolve both regressions.
Continuation (2026-09-15): the historical expired-on-retry evidence reader now
reads effective terminal/source identity, same-session/purpose replacement, original
creation provenance, commit window and closed reciprocal audit/trust under owned locks.
The expiry finalizer uses this read-back before returning; later replacement activation
or current account/gate changes do not erase the retained revocation. Other terminal
causes, full generation statement provenance and current authorization remain separate.
Initial focused SQLite 2 / 87 and source/lifecycle suite 62 / 4113 passed. Expanded
trust-tamper tests and all Vault units PASS 858 / 28124 (69.92s), exec 1903 exit 0.
Pint passes all three PHP files. Native sequence 70273 ended exit 1: mysql
4 failed / 3 passed, 181 assertions, 216.71s; mariadb did not start. The two new
history tests passed, but retained native fixture graphs exhausted the original private
service's 4 GiB cap (SQL 1041/5 OOM), then the process exited and later connects failed.
The same private data directory was recovered without deleting diagnostic databases,
raising limits or touching operational MariaDB PID 692. Private service PID 772606,
session 78212, retains skip_networking=1, 64 MiB buffer pool, 20 connections and the
original 4 GiB limit. Four legacy multi-purpose tests now release each finished owned
fixture after its assertions, rather than retaining every trigger graph until teardown.
Corrected focused SQLite PASS 7 / 339 (12.67s), exec 67447 exit 0; Pint passes.
Native rerun 11204 also ended exit 1: mysql 3 failed / 4 passed, 140 assertions,
203.82s; mariadb did not start. Per-purpose cleanup alone did not fix the native
allocation problem: enrollment's raw writer still exhausted the original 4 GiB cap.
The private service was recovered again against the same retained data, PID 775248
(session 24058), without changing the cap, network isolation or operational services.
Five existing writer/lifecycle tests now use the already-reviewed exact eight-trigger
cached base representation on native drivers, with the existing full live catalog
attestation and no capability upgrade. Historical metadata-corruption tests retain
their direct representation. Unexpected lifecycle exceptions are no longer hidden by
rollback-sentinel assertions. No product predicates, TTL or permissions changed.
Full SQLite verification 91698 ended exit 0: 858 / 28124, 73.01s. The previously
failing native enrollment lifecycle now PASS 1 / 100 (246.31s), probe 41843 exit 0.
Native sequence 30154 ended exit 0: mysql PASS 7 / 387 (926.41s), mariadb PASS
7 / 387 (871.68s), on the same private service without another restart or limit change.
Logs/JUnit: /tmp/vault-expiry-history.b0CCp8/{mysql,mariadb}-cached.{log,xml}.
Retain failed original/*-fixture-release logs and the successful enrollment probe.
The expiry read-back/finalizer and corrected native fixture matrix are verified.
Retained terminal integration is now implemented in both graph and quorum creation
readers. A shared metadata helper requires the exact actor/session/purpose, proof window
at finalization, and a consistent active terminal shape, or verified expired-on-retry
source/commit/audit history. Pending proofs and unsupported terminal owners fail closed.
Creation trust, factor statement provenance and current authorization remain separate.
Focused SQLite terminal/expiry tests PASS 3 / 232 (5.55s); five files pass Pint.
Broad Vault units plus both real graph/quorum creation integrations PASS 862 / 28770
(196.97s), sequence 65272 exit 0; log/JUnit: /tmp/vault-expiry-history.b0CCp8/sqlite-terminal-binding.*.
Native terminal integration sequence 33244 also ended exit 0: mysql 1 / 69 (64.77s),
mariadb 1 / 69 (65.20s), zero errors/failures/skips, both on private MariaDB 10.11.14.
Logs/JUnit: /tmp/vault-retained-terminal.XPpz06/{mysql,mariadb}.{log,xml}.
These completed sequences must not be restarted. No runtime is activated.
Quorum's retained factor-use timing gap is now corrected: the standard generation
must remain valid through actual plan finalization, not merely request time. The new
real migrated regression failed before the fix (1 / 199, 40.63s), then passed after
it (1 / 231, 42.67s). It covers peer unlock and cooling recovery, disabled/superseded
before-finalization denial, later-withdrawal retention and exact guard restoration.
Related factor/terminal units PASS 2 / 117 (0.22s); both changed PHP files pass Pint.
Native sequence 31153 ended exit 1: mysql failed 1 test / 123 assertions (2447.66s);
mariadb did not start. The rollback fixture masked an unexpected RuntimeException
with assertTrue(false), so this result does not yet identify the failed predicate.
Logs/JUnit: /tmp/vault-retained-terminal.XPpz06/quorum-factor-mysql.{log,xml}.
The fixture now rethrows unexpected errors without changing product code or TTLs.
Diagnostic sequence 56642 ended exit 1: SQLite PASS 1 / 231 (41.69s), mysql
FAIL 1 / 122 (2400.19s), SQLSTATE 45000 / vault_proof_finalization_denied at the
actual action-plan evidence finalizer. The exact denied subpredicate is not measured.
Logs/JUnit use quorum-diagnostic-{sqlite,mysql} in the same directory; preserve them.
The fixture reused one short-lived proof across independent plan scenarios. Each of
its six scenarios now authenticates a separate synthetic session and creates its own
real guarded proof, asserting active state and freshness before plan creation. This
removes that timing dependency without changing product TTLs, guards or semantics.
All original rollback, audit, corruption and factor-history checks remain; trusted
audit counts account for six proofs and three retained plans. Pint passes. Corrected
SQLite sequence 55565 ended exit 0, PASS 1 / 244 (46.93s). Native sequence 71372
also ended exit 0: mysql PASS 1 / 244 (3397.41s), mariadb PASS 1 / 244 (3393.85s),
zero JUnit errors/failures/skips on the same private MariaDB 10.11.14 service and limits.
Logs/JUnit: /tmp/vault-retained-terminal.XPpz06/quorum-fresh-{sqlite,mysql,mariadb}.{log,xml}.
Do not rerun these completed sequences. No runtime activation or operational change.
Next retained UserSecurity work: a primary password-rehash audit reader now binds the
finalized execution, exact consumed one-subject ledger, human/session/epoch identity,
original effect window, released old gate and reciprocal primary audit/trust. It reads
no current credentials or mutable account epoch and does not itself verify the parent's
complete proof-invalidation set. The UserSecurity store reads it back after gate
finalization so a missing/mismatched primary audit rolls back the owning transaction.
Three PHP files pass Pint. Focused migrated SQLite rehash integration passed
3 / 294 (97.25s), exec 8160 exit 0, primary-history-sqlite.{log,xml} in the same
directory. Coverage includes pending/rolled-back denial, exact ownership, missing
catalog, tampered pending trust and audit time, with exact guards restored before
reads. The expanded test now uses a second real protected rehash to prove retained
evidence survives a later gate/epoch change, not a synthetic account edit.
Expanded SQLite rehash/store/resume verification ended exit 0, PASS 21 / 533
(543.98s), exec 63528; primary-history-expanded.{log,xml} in the same directory.
It proves retained evidence after a second actual protected rehash and all existing
store/resume workflows. Native fixture support now uses the existing owned private
DB wrapper, exact SHOW CREATE guard restoration and the approved compiled assembly;
no operational database or credential is used. Fixture SQLite verification passed
1 / 103 (38.55s), exec 40372 exit 0, primary-native-fixture-sqlite.{log,xml}.
Native rehash verification is complete: mysql PASS 1 / 98 (2324.55s), mariadb
PASS 1 / 98 (2505.14s), both with zero JUnit errors/failures/skips. These are the
with_action_plans datasets on private MariaDB 10.11.14, not Oracle MySQL or all
three assemblies. Durable logs/JUnit:
 /tmp/vault-retained-terminal.XPpz06/primary-history-{mysql,mariadb}.{log,xml}.
The local wrapper handle was lost after desktop refresh; ended remote runner/child
and the complete durable results establish completion, not a recovered wrapper exit.
Do not restart this verified sequence. Next: retained auth-security proof-set and
terminal provenance under the same active Slice 04. Factor/recovery work remains open.
Retained password-rehash terminal read-back now has a dedicated closed owner reader:
the exact primary audit and consumed OLD/NEW epoch subject, every old-epoch proof across
sessions, effective source/terminal/commit, and reciprocal audit finalized by the parent.
One unfinished sibling denies the whole set. Earlier expiry requires its own completed
evidence; other UserSecurity flows and unsupported historical owners still fail closed.
The shared graph/quorum terminal helper dispatches only this supported rehash branch;
no credential writer, runtime binding, permission or operational schema was changed.
The first real two-session standard-proof/rehash regression passed SQLite 1 / 140
(48.14s), exec 97242 exit 0. Pending parent and pending sibling trust deny; audit failure
rolls back the real rehash and proof revocations; a later actual rehash preserves history.
Expanded standard/enrollment integration passed SQLite 4 / 572 (173.15s), exec 3559
exit 0. A missing-source-table regression first failed 1 / 24 (0.20s); the terminal
dispatcher now closes that failure without exposing a bound database diagnostic.
The first broad invocation named a nonexistent test file and ran no tests; its log is
retained. Corrected all-Vault-unit plus actual graph/quorum integration verification
passed 862 / 28821 (203.03s), exec 71555 exit 0, with zero failures. All four changed
PHP files pass Pint; permissions are 0644 sveintore:projectusers and diff checks pass.
Native sequence 71921 ended exit 1: mysql standard PASS 1 / 136 (3282.78s);
enrollment errored 1 / 78 (2473.98s), total 5756.88s. MariaDB-driver stage did not run.
The enrollment fixture incorrectly used later rehash effects-start as historical live
proof use; that proof had expired by then. Revocation of an expired active proof is
valid, but claiming that later instant as live proof use is correctly denied.
The fixture now retains the actual proof-creation commit finalization per session,
asserts that instant precedes proof expiry, and uses it for historical read-back.
No product lifetime, guard, clock, or reader predicate was relaxed. Pint/diff checks pass.
Corrected SQLite verification passed 2 / 294 (95.85s), exec 78652 exit 0.
Native sequence 57635 ended exit 0. Corrected mysql enrollment PASS 1 / 140
(3205.67s); mariadb standard/enrollment PASS 2 / 280 (6454.74s), all with zero JUnit
errors/failures/skips. Earlier mysql standard PASS 1 / 136 (3282.78s) remains valid
product evidence; its newer timestamp fixture also passed SQLite. Both PHP driver
paths use private MariaDB 10.11.14, not Oracle MySQL. Do not rerun these results.
Next bounded continuation: prove inert never-activated pending creation history so
it cannot incorrectly invalidate a completed rehash's old-epoch proof census.
Aborted creation and other terminal owners remain separate, closed branches.
Preserve rehash-proof-set-mysql.{log,xml}; corrected logs/JUnit use
rehash-historical-time-{mysql,mariadb}.{log,xml} in /tmp/vault-retained-terminal.XPpz06/.
Sequence 57635 is complete; preserve its results and do not repeat it.
The inert-pending census gap is now implemented with a separate metadata-only reader.
It requires the exact never-activated pending proof, still-building creation window,
closed human/source audit, and wholly pending reciprocal trust. It grants no access,
does not finalize anything, and reads no live credentials. The rehash census excludes
only that verified inert source; unsupported abort histories continue to deny.
A real third-session pending creation reproduced the gap before the change:
SQLite failed 1 / 79 (39.79s), exec 60813. Corrected standard/enrollment integration
passed 2 / 324 (98.03s), exec 41442 exit 0, including forged trusted-projection denial.
Closed metadata vectors passed 1 / 499 (0.95s), including expired pending evidence,
all typed audit references, malformed identity, missing/duplicate rows and serialization.
The four implementation/metadata PHP files pass Pint and retain 0644 sveintore:projectusers.
Broad verification 63009 ended exit 0: 863 tests / 29320 assertions (206.03s), covering
all Vault units and actual graph/quorum creation integrations. Logs/JUnit use
rehash-inert-pending-* in /tmp/vault-retained-terminal.XPpz06/.
A further real-writer lifecycle regression now covers all three proof purposes: actual
pending creation, inert read-back, activation exclusion, outer rollback and committed
activation. SQLite PASS 1 / 36 (1.84s), sequence 53534 exit 0; Pint passes the test file.
Native sequence 10308 ended exit 0: mysql PASS 1 / 48 (151.70s) and mariadb PASS
1 / 48 (148.30s), zero JUnit errors/failures/skips. Both PHP drivers use private
MariaDB 10.11.14, not Oracle MySQL. Owned synthetic fixtures used the exact approved
cached base guards. This verifies the new reader lifecycle, not a repeat of the full
migrated rehash integration. Logs/JUnit use inert-real-writer-{sqlite,mysql,mariadb}
in the same directory. All five affected PHP files retain 0644 sveintore:projectusers.
No test from this sequence remains running; do not repeat completed verification.
Continuation (2026-09-15): DatabaseVaultAbortedProofCreationReader now validates
original creation metadata/audit, the exact building_abort revocation and the winning
db_time_expired cleanup, including all three reciprocal trust rows, ordinary build
windows, monotone commit sequences and closed typed references. It reads only stable
canonical system identity, not credentials or current authorization/factor state, and
never installs a fabricated evidence context. Partial cleanup remains distinct/denied.
The existing abort finalizer now reads this full history before returning; failed
read-back must roll the whole caller transaction back. No runtime binding is added.
Initial actual migrated SQLite cleanup integration PASS 1 / 281 (84.21s), exec 87323.
Pending plus aborted metadata vectors PASS 2 / 1743 (6.97s), exec 43940, including
changed audit/reference/owner/timing facts, duplicate/missing rows and serialization.
The new finalizer binding and post-finalization read-failure rollback injection PASS
SQLite 3 / 2027 (89.38s), exec 97795 exit 0. All four affected PHP files pass Pint and
retain 0644 sveintore:projectusers. Logs/JUnit use aborted-creation-* in
/tmp/vault-retained-terminal.XPpz06/.
Sequential verification 72096 completed exit 0. Broad SQLite PASS 869 / 31085
(418.08s), native mysql PASS 1 / 334 (7388.09s), native mariadb PASS 1 / 335
(7834.61s); all JUnit errors/failures/skips are zero. Preserve the
aborted-creation-history-{mysql,mariadb}.{log,xml} results; do not repeat this sequence.
Both native driver paths used separate synthetic databases on private MariaDB 10.11.14,
not Oracle MySQL. The complete cleanup reader/finalizer is verified on both drivers.
Continuation (2026-09-15 20:19 UTC): the password-rehash census now separately checks
a completed aborted creation through that reader and the complete abort-capable catalog.
The original exact source-count comparison is retained. No terminal owned by the current
rehash may silently disappear; partial cleanup and unsupported terminal owners still deny.
A new actual cleanup-to-rehash regression failed before this binding (1 / 81, 42.99s),
at the expected retained proof-set denial. Only the expired pending origin is synthetic;
cleanup, two protected rehashes and all their audit/finalization writes use actual stores.
Standard/enrollment regressions, both actual protected rehashes and original/winning
cleanup trust tamper cases PASS 2 / 384 (117.50s), sequence 75141 exit 0. Both changed
PHP files pass Pint and retain 0644 sveintore:projectusers. Sequence 90516 completed exit 0:
broad SQLite PASS 868 / 31071 (387.21s), including all six integrated rehash cases
(1032 assertions); native mysql PASS 2 / 358 (8432.08s); native mariadb PASS 2 / 358
(8370.44s). Both native JUnit reports have zero errors/failures/skips. Preserve
rehash-cleanup-{mysql,mariadb}.{log,xml}; do not repeat this completed sequence.
Both PHP drivers use private MariaDB 10.11.14, not Oracle MySQL.
Logs/JUnit: rehash-cleanup-* under /tmp/vault-retained-terminal.XPpz06/.
Continuation (2026-09-16 01:23 UTC): revoked-pending creation has a separate historical
read entry point. It requires the exact still-building origin with wholly pending audit
and its real finalized revoke-only terminal; it cannot claim completed cleanup. The
rehash census checks this distinct branch and retains the exact source-count invariant.
The new actual revoke-only-to-rehash regression failed before binding (1 / 81, 40.31s).
Synthetic origins are explicit; revocation, two protected rehashes and resumed cleanup
use actual guarded stores. Metadata vectors PASS 3 / 2507 (9.78s), including original
pending, completed cleanup and revoked-pending standard/both enrollment purposes.
Sequence 79254 ended exit 1: both new integrations passed the initial retained census,
then failed in the test's audit-tamper setup (2 / 192, 82.72s). The revoke writer returns
a transition ID, not its evidence-commit ID; the fixture now resolves the exact transition's
commit before reading its audit. This is a fixture correction, not a product-policy change.
Four PHP files pass Pint. Sequence 51609 completed exit 0. Its broad SQLite stage PASS
872 / 32507 (591.60s), zero JUnit errors/failures/skips: all Vault units, all eight rehash
cases and actual completed-creation cleanup. This resolves the test-fixture failure;
retain the failed run for diagnosis. Actual revoke-only retention, both protected rehashes,
tamper rejection and later resumed cleanup pass for standard and enrollment proofs.
Native mysql PASS 2 / 362 (8893.39s), zero JUnit errors/failures/skips. Preserve
rehash-partial-cleanup-mysql.{log,xml}; do not repeat the completed driver.
Native mariadb PASS 2 / 362 (8871.50s), zero JUnit errors/failures/skips, verified
2026-09-16 06:49 UTC. Preserve rehash-partial-cleanup-mariadb.{log,xml}; do not repeat.
Both PHP drivers use private MariaDB 10.11.14, not Oracle MySQL.
Logs/JUnit: rehash-partial-* in /tmp/vault-retained-terminal.XPpz06/.
This remains a password-rehash reader, not a generic UserSecurity owner. Other security
flows and factor statement provenance remain open; partial-cleanup integration is now
verified on both native PHP driver paths. No runtime consumer or new authority is enabled.
Continuation (2026-09-16): retained account history is being extended to the exact
single-subject human password-update and login-identifier-change flows. They have no
primary Vault audit; their original subject ledger and exact transition audits remain
required. The rehash-only entry point retains its existing restriction. Actual guarded
account changes reproduced the missing retained owner before the change: 2 failures /
27 assertions (64.21s), account-history-before.{log,xml}. The shared proof census is
unchanged except for explicit owner dispatch; no writer, guard, runtime binding or schema
is widened. Sequence 92484 PASS 2 / 71 (73.06s) on SQLite. Expanded negative tests initially
hit the schema's equal expected/consumed-count CHECK instead of the reader (sequence
34731, 2 failed / 1 passed, 123 assertions, 69.03s). The isolated corruption fixture now
changes both counts together; no constraint or product rule was weakened. Corrected
sequence 71349 PASS 3 / 291 (84.08s), zero JUnit errors/failures/skips: ownership and
redacted diagnostics, wrong flow/subject/epoch/count, unfinished parent, extra forbidden
subject fields, pending gate, terminal trust tamper, and retention after a later change.
All six changed PHP files pass Pint; new files are 0644 sveintore:projectusers.
Sequence 47253 completed exit 0. Broad SQLite PASS 875 / 32798 (660.81s), zero JUnit
errors/failures/skips: all Vault units, ten integrated rehash/account cases and actual
completed-creation cleanup. Native mysql PASS 2 / 67 (4752.73s), zero JUnit errors,
failures or skips. Preserve account-history-mysql.{log,xml}; do not repeat that driver.
Native mariadb PASS 2 / 67 (4987.46s), zero JUnit errors/failures/skips, verified
2026-09-16 09:59 UTC. Preserve account-history-mariadb.{log,xml}; do not repeat this
completed sequence. No test process from sequence 47253 remains running.
Logs/JUnit: account-history-{broad,mysql,mariadb}.{log,xml} in the same temporary directory.
Both native PHP drivers use private MariaDB 10.11.14, not Oracle MySQL.
Other UserSecurity flows, multi-subject and actorless execution
history, factor statement provenance and authority/quorum consumers remain open.
Evaluation checkpoint (2026-09-16): Svein questioned repeated status-only progress.
The existing verification was allowed to finish; no additional test sequence or feature
code was started during evaluation. Automatic continuation is now PAUSED (read back).
Slice 04 remains In Progress, not complete or ready for practical human review.
Before resuming feature implementation: reconcile a bounded Slice 04 completion list
with approved requirements, measure native setup/catalog/runtime costs, and agree the
continuation approach with Svein. Repeated full catalog compilation/attestation is a
candidate cost center, not a measured diagnosis. Preserve all approved security gates.
No new restriction was installed in the credential write path. Complete owner dispatch,
factor statement provenance, authority/quorum consumption and recovery remain open.
Prior broad creation verification remains 898 / 35894 SQLite, mysql 7 / 772 and
mariadb 7 / 772. Previous native quorum results do not cover the new graph change.
Authority classification/reader dispatch, recovery eligibility and root provenance,
historical proof terminal/generation statement provenance and lifecycle work stay open.
Slice 04 In Progress; product rows 05..16 unfinished; runtime remains disabled.
HR-2026-09-04-003 Pending / not practical-review-ready.
Checkpoint: docs/plans/2026-09-14-vault-action-plan-source-readback.md.
Automatic continuation is PAUSED for delivery-workflow evaluation with Svein.

The previous bounded UserSecurity optimization is verified: final sequence 13919 ended
successfully, nine native drift denials per driver and 60 focused tests / 1270 assertions.
The earlier broad run passed 1248 / 48064 (89 explicit opt-in native skips); sequential
private native creation cleanup passed mysql 1 / 298 and mariadb 1 / 299 without reset.
This is controlled private-allocator verification, not operational/default-allocator acceptance.
Action-plan base and graph/authority child guards are verified but unbound: actual DDL
and synthetic external facts PASS 5 / 642 on SQLite, mysql and mariadb independently.
Canonical decoder PASS 28 / 142; all Vault units plus source integration PASS 804 / 26201.
Authority hydration PASS 12 / 424; graph entry hydration PASS 6 / 53, every family.
The shared graph operation matrix and retained-subject checks PASS 6 / 386.
Locked authority/graph readers PASS 1 / 28 on SQLite and each native driver.
Final all Vault units plus both source/read-back integrations PASS 823 / 27026 (67.07s),
no skips; exec 90514 ended. Live enabled/runtime-approved flags remain false.
Next: owning plan/proof/audit verification, complete quorum assembly, guarded plan/approval
stores and finalization under the same active slice. No new product feature is started.
Current detail: docs/plans/2026-09-14-vault-action-plan-source-readback.md.
See docs/plans/2026-09-13-vault-mariadb-trigger-memory-rework.md for retained evidence.
Runtime off; HR-2026-09-04-003 remains Pending and not practical-review-ready.

Active Slice 04 continuation (2026-09-13, pending-proof creation cleanup):
The closed building_abort source and coupled revoke-only terminal definitions are verified.
Standard and both enrollment purposes require exact pending creation trust and canonical system
execution. Source/phase tests PASS 4 / 144; actual source + production audit + terminal/rollback
test PASS 1 / 54 (1.367s). Broad PASS 834 / 26,669 (9:25.716), mysql PASS 1 / 54 (1:03.258),
mariadb PASS 1 / 54 (53.147s); combined 836 / 26,777, no skips/deferred failures.
Execs 52891 and 29996 finished exit 0. Final six-file Pint/diff/permissions PASS; flags false.
Logs: /tmp/vault-building-coupling-{broad,mysql,mariadb}-20260913.log.
The historical fixture is synthetic retained metadata, not operational cutover.
DatabaseVaultPendingProofAbortWriter now locks root/authority/security gate, exact expired
creation/proof/audit/trust and human/system metadata, allocates a newer sequence, writes a
new terminal source plus actual audit, finalizes and reads back proof/trust. It requires the
caller's exact owned transaction and never commits or reads credentials. It refuses an
existing terminal claim and leaves creation evidence pending for the next cleanup stage.
New writer test PASS 1 / 66 (1.954s): all purposes, missing-guard/unowned/replay denial and
outer rollback after successful revocation restore proof/source/audit/trust/sequence together.
Sequential regression finished exit 0, exec 54945: shared units PASS 820 / 25,597 (1:00.707),
mysql writer PASS 1 / 66 (59.357s), mariadb writer PASS 1 / 66 (56.157s). Combined current
units plus writer drivers 822 / 25,729, no skips/deferred failures. Earlier full integrated
regression and paired-terminal driver results above remain valid for the unchanged shared guards.
Final writer/test Pint and diff PASS; writer is 0644 sveintore:projectusers.
Logs: /tmp/vault-pending-abort-writer-{units,mysql,mariadb}-20260913.log.
Driver filter: test_pending_abort_writer_owns_no_transaction_and_rolls_back_with_its_caller.
Creation-evidence cleanup is now implemented: exact retained building_abort binding and locked
reader, fixed preparation/finalization entry points, atomic coordinator and staged-loser repair.
The coordinator revokes a pending proof and aborts its creation in one transaction, or resumes
only exact retained trusted revocation without rewriting terminal history.
Migrated SQLite PASS 1 / 248 (1:17.387), exec 93048 finished: standard and both enrollment
purposes, live/replay denial, ten reciprocal-provenance cases, three write-boundary rollbacks,
fresh completion, retained resumption and staged-loser repair. Pure actual trust-assembly parser
PASS 1 / 7. Initial integration PASS 1 / 176 preceded the expanded denial cases.
Broad Vault/UserManagement and integrated consumers PASS 836 / 26,983 (11:00.262).
Sequential exec 79041 ended on mysql fixture failure (36:17.943, 84 assertions); mariadb did
not start. The second live creation had waited behind the first purpose's expensive rollback
matrix and was legally expired when checked, so its expected early-cleanup denial was invalid.
The test now checks all live fixtures first and asserts their DB-time freshness explicitly.
No runtime, TTL or eligibility rule changed. Corrected SQLite PASS 1 / 251 (1:22.818),
exec 72135 finished. MySQL-driver retry PASS 1 / 251 (43:21.718), resolving the fixture failure.
Exec 86813 ended with a MariaDB memory failure, not a passing driver sequence.
See docs/plans/2026-09-13-vault-mariadb-trigger-memory-rework.md for exact failures,
bounded reproductions, reverted experiments and the next implementation action.
The later routine-backed implementation and current test/resource state supersede the
pre-routine checkpoint here; see the linked memory-rework plan above. The compiled
catalog and unmodified native writer pass isolated verification, and SQLite plus compiler/
catalog/parser regression passes 16 / 317. Full native and broad verification remain.
Latest cleanup test correction pins only the native live-denial fixture clock and restores it;
SQLite PASS 1 / 260; full MariaDB PASS 1 / 283 (1:16:42.10), including catalog drift and
all creation-cleanup scenarios. Exec 83489 then failed on MySQL with OOM (1 / 36);
broad verification did not start. Six fresh-connection rolled-back flows pass on the same
cold private server, but still reach about 1.6 GB RSS. The owned native test assembly now
installs compiled triggers directly rather than loading the raw graph first. That change
alone still failed the second driver (57013: mysql PASS 1 / 282, mariadb FAIL 1 / 37).
The expanded thirteen-trigger/nineteen-routine assembly now includes large UserSecurity
conditions. Compiler/parser/SQLite PASS 15 / 329; native truth/type parity passes both
drivers. Six fresh-connection actual-writer probes pass (35559); pure bounded source reuse
and exact-byte comparison reduce repeated full-flow latency from 90-94s to about 38s
without caching catalog/authority results. Pure tests PASS 15 / 71. Sequence 40988 ended
at broad ALL directories: 1233 passed, 15 failed, 89 opt-in native skips, 47873 assertions.
Native stages did not start. Outdated authority fixture/manifest diagnostics and the profile
validation error bag are corrected; targeted regression 10502 PASS 40 / 898 (467.16s).
Sequence 70540 ended: mysql PASS 1 / 298, mariadb FAIL 1 / 53 with native OOM; broad
did not start. Profiling shows SQL cache retention plus allocator-retained mappings after
those objects are freed. Process-only MALLOC_ARENA_MAX=2 on the private server reduces the
first real-flow probe to VmPeak 1851596 kB (PASS 44.887s); it is not yet an accepted fix.
Sequential native exec 35363 ENDED exit 0: mysql 1 / 298 (3520.61s), mariadb 1 / 299
(3610.78s), same private process without restart/cache flush, VmPeak 2990928 kB.
This verifies the controlled allocator configuration, not an operational/default-allocator fix.
Corrected SQLite broad exec 41507 ENDED
exit 0: 1248 passed, 89 opt-in native skips, 48064 assertions, 2587.01s. All fifteen
prior failures now pass; native skips are not additional driver coverage.
The subsequent narrow UserSecurity optimization reduces full attestation from three to one
per invocation, with fresh validation on the next call. Regression fails before/passes after;
pure tests PASS 19 / 112. Corrected two-driver drift probe 84467 passes nine denials each.
Late in-call compiled-to-raw replacement failed before the extra private check and passes
afterwards; actual helper methods pass both drivers in 63558. Whole-flow probe 7663
PASS (mariadb 33.419s, mysql 30.975s). Final sequential verification 13919 ENDED exit 0:
nine final-code denials per driver and focused SQLite/consumer PASS 60 / 1270 (559.09s).
The previous optimization checkpoint is closed; continue the action-plan source work above.
The 4 GiB ceiling, all application checks and runtime-off state remain unchanged.
This is not a deferred-failure or review-ready checkpoint.
The canonical memory-rework plan tracks exact current state.
Do not repeat the failed abort-only factoring, optimizer-search-depth or emulated-prepare
experiments. Do not raise resource limits, skip verification, or start dependent product work.
HR-2026-09-04-003 Pending/not practical-review ready. No operational installation, runtime
activation, real credentials, commit/push, Main or production change.

Latest Slice 04 checkpoint (2026-09-13, inert replacement-transition cleanup):
Implemented and verified: closed db_time_expired / expired cleanup for retained expired_on_retry
standard step-up and both TOTP-purpose transitions. Exact typed pending target audit/trust,
original/replacement proof provenance, canonical system actor and two-CAS completion are required.
Both proofs remain unchanged. Current proof/factor/epoch freshness does not reserve inert evidence.
UserSecurity/authority/quorum-owned paths remain closed. Shared proof effects require finalized
commits in the abort assembly; other assemblies remain unchanged. The exact staged-loser repair
also covers this family without rewriting its winner or original transition.
SQLite parser-stack and expression-depth failures were resolved by removing redundant result/
OR-branch parentheses and grouping shallow conjunction runs only. Every eligibility leaf, lazy
CASE branch and SQL NULL semantic remains intact. No compile-limit or permission relaxation.
Focused migrated transition/source/expiry/loser PASS 4 / 269 (2:06.321), exec 2362.
Parser/conjunction including mixed SELECT/CASE PASS 4 / 20 (0.152s).
Broad shared-consumer PASS 829 / 26,471 (9:00.651); mysql PASS 1 / 124 (29:48.343);
mariadb PASS 1 / 125 (27:19.098). Sequential exec 9032 finished exit 0.
Combined broad plus both drivers: 831 / 26,720, no skips/deferred failures.
Logs: /tmp/vault-transition-{broad,mysql,mariadb}-20260913.log.
Final 17-file Pint and diff PASS; six new files are 0644 sveintore:projectusers.
Live flags false; cleanup/finalizer/transition reader autoload but remain unbound.
Owned private server /tmp/tdpsa-vault-maria.ALNcq7, pid 335932: zero non-system schemas
verified after all tests finished; server shutdown, socket absence and exact-directory removal
are verified. No test or private server from this checkpoint remains active.
Next bounded work: the approved pending-proof building_abort source and revoke-only transition,
then creation-evidence cleanup. Keep standard and both enrollment purposes exact, require the
canonical system actor and inert creation provenance, and never turn pending creation into
trusted evidence. Source, audit, proof revocation and cleanup must ultimately be one transaction.
Remaining source/owner/consumption/classification and product slices still precede human review.
HR-2026-09-04-003 Pending/not practical-review ready. No operational migration, runtime
activation, real credentials, commit/push, Main or production change.

Previous Slice 04 checkpoint (2026-09-13, staged losing-cleanup repair):
This bounded flow is implemented and verified. The explicit abort assembly has a closed second
branch: only a staged db_time_expired attempt-cleanup loser may be aborted by a strictly newer
abort_lost_race / cleanup_repair commit. Its exact different finalized winner, original aborted
attempt, typed audits/trust and monotonic sequences are required. Retained winner validation
does not require its historical build TTL to remain live. The ordinary expiry branch retains
its existing predicates. A locked reader and preparer/finalizer/coordinator extensions retain
the atomic two-CAS order; winner/original history is read-only. No generic recursive cleanup.
Eight PHP files pass Pint/diff; the two new files are 0644 sveintore:projectusers.
SQLite source/expiry regressions PASS 2 / 88 in initial exec 17677; the new third test first
failed because its read-only predicate inspection did not consume the write context. The test
now throws inside that inspection context, as intended, rolling back the whole preparation.
Runtime was unchanged by that fix. Expanded staged-loser test PASS 1 / 57 (32.123s), exec 93973:
both winner sequence orders, nine source/context variants, retained winner after simulated
TTL expiry, second-CAS rollback, replay, and unchanged original/winner history are verified.
Combined current SQLite coverage 3 / 145. No skipped or deferred failure remains there.
MySQL-driver staged-loser integration PASS 1 / 57 (34:52.271), exec 97857, process finished.
MariaDB-driver staged-loser integration PASS 1 / 58 (30:03.894), exec 13066, process finished.
Broad shared-consumer regression PASS 824 / 26,327 (8:20.105), exec 5700, process finished.
Combined broad plus both drivers PASS 826 / 26,442, no skips/deferred failures. All tests ended.
Final eight-file Pint/diff pass; flags false, cleanup/finalizer/reader autoload but are unbound.
Owned private server /tmp/tdpsa-vault-maria.AF4DZG, pid 317910: zero non-system schemas
verified, server stopped, socket absence verified, and exact temporary directory removed
after recording all results. No test or private server from this checkpoint remains active.
Next bounded implementation: inert step_up_proof_transition expiry cleanup, starting with the
existing expired_on_retry replacement-transition family and its exact pending audit/trust.
Keep both original and replacement proofs unchanged. Shared proof terminal-write helpers
currently select by source kind; before admitting transition-abort targets, fence their effects
to NEW.state=finalized so abort never accidentally revokes/activates a proof. Read the complete
replacement and UserSecurity transition contracts; keep UserSecurity/origin-owned paths closed
until their own release rules exist. No generic widening of the source matrix.
Remaining source-release matrices, owner/consumption/classification and product slices still
precede practical review. HR-2026-09-04-003 remains Pending/not practical-review ready.
No operational migration, runtime binding/activation, real credentials, commit/push or Main.

Previous Slice 04 checkpoint (2026-09-13, complete inert-attempt expiry flow):
Closed phases in VaultExpiredAttemptAbortSourceGuards and the new
VaultExpiredAttemptAbortFinalizerBinding now support target building-to-aborted, target trust
pending-to-aborted, then cleanup building-to-finalized and its trust finalized LAST. Shared
proof/trust guards and exact catalog accept this only as an explicit complete abort assembly.
No same-table recursive commit writes: DatabaseVaultExpiredAttemptAbortFinalizer performs two
CAS statements under the caller's locks/transaction. DatabaseVaultExpiredAttemptCleanup owns
one transaction across the existing preparation and finalization. No runtime/scheduler binding.
The sealed integration test now verifies the complete flow, failed second CAS rollback,
complete-finalizer outer rollback, replay denial, pending/trusted visibility, untouched live
target, and full coordinator rollback including source/audit/trust/sequence allocation.
SQLite first two-test run PASS 2 / 75 (54.237s), exec 44868; coordinator extension PASS 2 / 81
(54.891s), exec 13036. Final expanded flow PASS 1 / 57 (30.110s), exec 19711; source-only
regression in the two-test run remains 1 / 31. Nine PHP files pass Pint/diff; new files 0644.
MySQL flow integration PASS 1 / 57 (34:47.701), exec 95516, process finished.
MariaDB flow integration PASS 1 / 58 (29:56.582), exec 77146, process finished.
Broad shared-guard regression PASS 819 / 26,020 (5:39.222), exec 35353.
Existing issuance/enrollment/denied-attempt/password-rehash tests now have explicit existing and
with_abort provider cases. Additional same-assembly cases PASS 4 / 250 (2:11.206), exec 11147.
Only tests changed after the broad run; runtime version is identical. Combined broad, new
consumer cases and two driver contracts PASS 825 / 26,385, no skips or deferred failures.
All test processes completed. Live flags false; coordinator/finalizer autoload but are unbound.
Owned server /tmp/tdpsa-vault-maria.Z6SYMg, pid 300237: zero non-system schemas verified,
server stopped, socket absence verified and exact temporary directory removed after recording
all results. No test/server from this checkpoint remains active.
Next bounded unit: the approved staged losing-cleanup winner/loser rule (abort_lost_race or
later strictly higher-sequence cleanup), with competing staged-cleanup fixtures and exact
winner evidence. Do not relax original expiry predicates. Other source-release matrices and
remaining owner/consumption/classification work also remain closed and must precede product
slices. HR-2026-09-04-003 Pending/not practical-review ready. No operational migration or secrets.

Previous Slice 04 checkpoint (2026-09-13, locked expired-attempt cleanup preparation):
DatabaseVaultExpiredAttemptAbortPreparation now requires the caller-owned transaction and
exact full proof/UserSecurity/attempt/audit plus abort-source guard catalogs. It locks root,
authority, security gate, target/source/audit/trust, human/generation and canonical system actor,
allocates a newer sequence, then creates building cleanup/source and an actual typed pending
audit. It never self-commits, changes the target or claims trusted completion. No runtime binding.
VaultExpiredAttemptAbortPreparationTest uses the sealed real migrated owner/audit assembly;
only pre-existing attempt rows are synthetic. Actual pending audit writes, full rollback of
source/commit/audit/trust/sequence, live-target rejection, owned-transaction enforcement and
missing-guard rejection pass SQLite 1 / 26 (27.898s), exec 54075. Initial test passed 1 / 23
(27.398s), exec 27723. Lock ordering now matches existing root/authority/security-gate writers.
Both new PHP files pass Pint/diff and are 0644/project-owned.
MySQL integration PASS 1 / 26 (32:50.149), exec 7718, process finished.
MariaDB integration PASS 1 / 27 (26:26.092), exec 40639, process finished.
Final broad PASS 819 / 25,989 (5:53.699), exec 96504. Combined 821 / 26,042, no skips or
deferred failures. All test processes completed. Live flags both false; preparer autoloads
but is unbound. Owned private server /tmp/tdpsa-vault-maria.0GhoRC, pid 283802: zero non-system
schemas verified, server shut down, socket absence verified and exact temporary directory
removed after recording test evidence. No test/server remains active from this checkpoint.
Next: exact target/trust abort CAS and cleanup commit/trust finalization LAST, integrated with
the existing closed guard catalogs and the same owned transaction. Never update the evidence
commit table recursively from its own trigger. Remaining owner/product work precedes review.
HR-2026-09-04-003 Pending/not practical-review ready. No operational migration or real secrets.

Previous Slice 04 checkpoint (2026-09-12, expired-attempt abort source):
VaultExpiredAttemptAbortSourceGuards adds three immutable source guards, restricted to exact
expired step_up_attempt_evidence, canonical disabled system actor, a newer live cleanup commit,
matching retained attempt/audit/pending trust and one-use context. Every other target/trigger
stays denied. This is source INSERT only, not the target-abort/finalizer/writer or scheduler.
VaultExpiredAttemptAbortSourceGuardTest uses actual isolated migrated schemas with explicitly
synthetic pending rows. SQLite PASS 1 / 30 (25.940s), exec 84362. Expanded raw-write test first
errored on the expected inactive SQLite context exception (1 / 21, exec 85082); assertion now
checks that exact denial, runtime unchanged. Final SQLite PASS 1 / 31 (26.246s), exec 52524.
Both new PHP files are 0644/project-owned; Pint and git diff --check pass.
MySQL-driver integration PASS 1 / 29 (30:10.631), exec 66020, process finished.
MariaDB-driver integration PASS 1 / 30 (25:29.937), exec 90454, verified 2026-09-13.
Final broad regression PASS 818 / 25,963 (5:20.185), exec 79172. Combined 820 / 26,022,
no skips or deferred failures. All test processes have completed. Owned socket-only server
/tmp/tdpsa-vault-maria.bEhONM, pid 267583: zero non-system schemas verified, server stopped,
socket absence verified and exact temporary directory removed. No active test to resume.
No operational DB migration, runtime activation, real credentials or production change.
Next implementation after verification: exact locked abort coordinator, typed cleanup audit,
target building-to-aborted and target trust pending-to-aborted, then cleanup commit/trust
finalized LAST in one transaction. Account for MariaDB's same-table trigger write restriction:
coordinate the two commit transitions explicitly; do not UPDATE another evidence commit from
its own table trigger. No generic release bypass. Remaining product work precedes review;
HR-2026-09-04-003 Pending/not practical-review ready. Heartbeat remains active.

Previous Slice 04 checkpoint (2026-09-12, retained-history/writer integration verified):
VaultIntegratedFoundationAuditCutoverTest uses actual migrated owner tables, two synthetic
historical foundation events, whole SQLite cutover rollback and the real foundation recorder
after completion (outer rollback, successful atomic trust and exact raw-INSERT denial).
First SQLite run 17495 denied the incomplete test guard fixture (1 / 3); the existing TOTP
replay installer is now included, with no weakened runtime check. SQLite PASS 1 / 30 (35.570s),
exec 89188. MySQL PASS 1 / 23 (46:55.925), exec 79006; all private migrations and the actual
retained-history/write/rollback checks completed. MariaDB PASS 1 / 24 (40:08.635), exec 31637.
Final broad PASS 817 / 25,932 (4:43.708), exec 3539, including the new SQLite integration:
combined 819 / 25,979, no skips/deferred failures. All test processes completed.
Private server /tmp/tdpsa-vault-maria.lP6OUt, pid 233214: zero non-system schemas verified,
then stopped and the owned temporary directory removed. No test from this checkpoint is active.
No real credentials, operational migrations, runtime activation or production changes.
Next bounded implementation: source-specific evidence-commit expiry cleanup for inert
step_up_attempt_evidence (approved abort matrix), with exact system actor, DB expiry, locked
source/target, separate cleanup commit/source/audit, target pending-to-aborted and cleanup
trust finalized LAST. Other source kinds stay denied until their own release predicates exist.
Read the abort contract at Slice 04 around lines 2600-2690 and the actual source/writer guards
before changes; do not invent a generic cleanup bypass or activate a scheduler/runtime binding.
Remaining typed owner/consumption/abort/classification and product slices still precede review.
HR-2026-09-04-003 remains Pending/not practical-review ready.

Previous Slice 04 checkpoint (2026-09-12, last-only thaw and completion): internal
FoundationAuditThawInstaller removes only the exact freeze after locked stage-4 manifest/
trust/view verification and exact runtime/permanent catalogs. FoundationAuditThawedCatalog
and closed readers reject missing/replaced guards; FoundationAuditCompletionContext performs
one-use revision-4-to-5 DML and full read-back. No classification, operational entry or runtime
activation. Seven files pass Pint/diff; three new PHP files 0644/sveintore:projectusers.
SQLite test 58418 reached completion but errored 2 / 240 on the test helper not catching the
expected replay LogicException. The test now asserts that exact error; runtime was unchanged.
SQLite retry PASS 2 / 264 (1:03.096), exec 80180. Full drivers PASS 6 / 768 (15:51.405),
exec 19220; broad PASS 816 / 25,902 (4:23.560), exec 47981. Combined 822 / 26,670, no skips or
deferred failures. Logs completed-cutover.log and completed-cutover-broad.log remain on the
retained private server. Live flags both false;
new thaw/completion classes autoload but remain unbound. The private server remains owned:
/tmp/tdpsa-vault-maria.lP6OUt/server.sock, pid 233214. Retain for this continuation; verify owned
schemas empty before shutdown/cleanup. No real credentials or operational database changes.
Next: integrated retained-history cutover with full runtime owner schemas and a positive
post-cutover audit writer, then remaining typed owner/consumption/abort/classification and
product slices. HR-2026-09-04-003 Pending/not practical-review ready.

Previous Slice 04 checkpoint (2026-09-12, frozen runtime-validator switch): internal
FoundationAuditRuntimeGuardInstaller accepts only exact legacy/gap/runtime catalogs behind
the full freeze and permanent trust guard. It requires the locked guard_installed manifest,
preserves history/header/trust, performs lossless timestamp precision preparation and verifies
each restart prefix. SQLite PASS 2 / 206 (1:02.686); full drivers PASS 6 / 598 (14:53.583),
exec 68316; broad PASS 816 / 25,902 (4:16.300), exec 10224. Combined 822 / 26,500, no skips or
deferred failures. Logs runtime-validator.log and runtime-validator-broad.log remain on the
same retained private server. This earlier checkpoint did not thaw or mark completion.

Previous Slice 04 checkpoint (2026-09-12, frozen guard-installed checkpoint): the one-use
FoundationAuditPermanentGuardContext verifies the permanent-only catalog and full locked
backfilled set/view before a fixed backfilled/revision-3 to guard_installed/revision-4 CAS.
Fresh read-back verifies the result; immutable manifest/provenance remain unchanged. No raw,
replayed, temporary/overlap, wrong-identity or ordinary-transaction path can advance it. A
separate reader verifies retained guard_installed without writing. Audit stays frozen; no
completed marker, thaw, runtime binding/activation or operational DDL was introduced.
SQLite empty/nonempty PASS 2 / 173 (35.373s), including full rollback and one-use/replay denial.
Four PHP files pass Pint/diff; new context 0644/project owner. Full drivers completed PASS
6 / 507 (11:29.426); broad regression PASS 816 / 25,902 (4:23.076), combined 822 / 26,409.
Owned server retained: /tmp/tdpsa-vault-maria.lP6OUt/server.sock, pid 233214. Resume, never duplicate
or overlap tested-area edits. Current validator verification above owns the retained server;
clean only this owned server. Next: last-only thaw/completed cutover and remaining typed
owner/consumption/abort/classification before product slices. Owner Codex; HR-2026-09-04-003
Pending/not practical-review ready. No real secrets, Main or production changes.

Previous Slice 04 checkpoint (2026-09-12, frozen permanent-guard handoff): internal
FoundationAuditPermanentGuardInstaller is implemented. It accepts only exact temporary,
overlapping or permanent INSERT catalogs while retaining the full freeze/retention/manifest
guards. Complete locked backfilled base/item/trust/view read-back precedes DDL and follows each
handoff. Permanent CREATE is verified before temporary DROP; missing-both, wrong bodies,
unexpected triggers, wrong identity/stage and wrong transaction ownership deny without repair.
SQLite DDL remains caller-owned/rollback-safe; MariaDB DDL remains outside owned DML transactions.
Header stays backfilled; no freeze removal, later-stage completion, runtime binding or activation.
Owner: Codex. Empty/nonempty SQLite/mysql/mariadb PASS 6 / 384 (10:49.177), including SQLite
DDL rollback and overlap restart. Final broad PASS 816 / 25,902 (4:18.436): 822 / 26,286,
no skips/deferred failures. Both runs finished; private server is retained only for the next
guard-stage tests above. Three files pass Pint/diff/0644 ownership. Live flags false; installer
autoloads and is unbound. Historical handoff checkpoint only, not full cutover completion.
Next: guarded guard_installed/completed cutover stages and last-only thaw after their exact
read-back, remaining typed owner/consumption/abort/classification and product work. No operational
DDL, real secrets, Main or production. HR-2026-09-04-003 Pending/not practical-review ready.

Active Slice 04 implementation (2026-09-12): connect the frozen foundation-audit DML phase
through DatabaseFoundationAuditBackfillCoordinator. It must own/resume exact retained identity,
capture and backfill under the same caller-owned cutover transaction, and independently verify
a backfilled no-op. No DDL, guard removal, later-stage advancement, runtime or operational entry.
Owner: Codex. Internal coordinator implemented; no outside/ordinary transaction may borrow
cutover ownership. Exact retained building/captured/backfilled identity dispatch, full fresh
base/item/trust/view reconciliation, missing-child backfill and captured-to-backfilled CAS
share one caller-owned transaction. A completed backfill no-op still checks the exact catalog.
Empty/nonempty rollback, captured partial resume, wrong identity and missing-guard denial
PASS on SQLite/mysql/mariadb: 6 / 268 (6:42.282), no skips. Foundation migrations/guards are real;
unrelated later owner-view tables remain structural fixtures, not full owner-cutover validation.
Final broad regression PASS 816 / 25,902 (4:07.583): current checkpoint 822 / 26,170,
no skips or deferred failures. All test sessions completed. Owned shxvgp/229116 server was
verified empty, shut down and removed; all private schemas/logs are cleaned. Runtime flags
remain false; coordinator unbound. Two PHP files pass Pint, diff/0644/project-owner checks pass.
No operational DDL/activation, real credentials, Main or production changes.
Next implementation: permanent-guard switch while frozen, then typed owner/consumption/abort/
classification work. HR-2026-09-04-003 Pending/not practical-review ready; continuation ACTIVE.

Current Vault continuation checkpoint (2026-09-12, full migrated factor-boundary verification COMPLETE):
Full mysql driver PASS 1 / 103 (24:49.424); mariadb driver PASS 1 / 104 (23:31.869).
Final broad units/five migrated SQLite integrations PASS 816 / 25,902 (4:14.510):
current checkpoint 818 / 26,109, no skips or deferred failures. Four test-support/boundary
files pass Pint, diff and 0644/project-owner checks. Both private schemas were dropped by
teardown; exact private server pO4ZmF/214040 was verified empty, shut down and removed.
All test sessions have finished. The first mysql failure and its test-only per-submission
TOTP correction remain recorded below as history; no runtime verification window changed.
Next: remaining typed owner/consumption/abort/cutover paths in active Slice 04, before product
slices. No operational DDL/activation, real secrets, Main or production. HR-2026-09-04-003
stays Pending/not practical-review ready; automatic continuation remains active.

Historical checkpoint below records the former active sessions, now all completed and cleaned;
do not resume them or recreate their removed private schemas/logs.
Previous Vault continuation checkpoint (2026-09-12, full migrated factor-boundary verification):
VaultIntegratedStepUpAttemptBoundaryTest now supports the explicitly owned private MariaDB test
database, not application credentials. VaultPrivateAuditDatabase attests the exact Connection,
actual schema/socket and skip_networking; the full test assembly requires that owner for MariaDB.
Empty synthetic bootstrap is prepared only after exact guard attestation/drop, and all protection
is restored before credential calls. MariaDB DDL stays outside owned transactions. Legacy whole-app
migrations use mysql, then the requested mariadb driver is restored for the Vault boundary (same
existing convention as VaultIntegratedProofWriterTest, not new app-wide migration support).
Initial mysql-driver run 88128 FAILED after 21:45.087 at the success assertion (15 assertions),
after real CAS/proof rollback checks passed. It returned no proof. The test reused its initial
TOTP across several separate submissions despite the slow private guard path. Current DB-time
code generation per new submission leaves runtime verification/skew unchanged. The mysql retry
92506 now PASS 1 / 103 (24:49.424); its schema was dropped by teardown. SQLite regression also
PASS 1 / 103 (43.006s), Pint/diff pass. Initial failure is retained as history, not deferred.
The mariadb-driver run is now ACTIVE, not passed or failed yet:

- Unified exec session: 49397 (resume with write_stdin; do not start a duplicate).
- Test PHP pid: 223684. Owned server pid: 214040 (operational MariaDB is pid 692; never touch it).
- Private directory/socket: /tmp/tdpsa-vault-maria.pO4ZmF/server.sock.
- Private schema: tdpsa_vault_audit_ab0ee45e2a29; first read-back showed 19 tables being migrated.
- Active log: /tmp/tdpsa-vault-maria.pO4ZmF/factor-mariadb.log; no result footer yet.
- Passed mysql log: /tmp/tdpsa-vault-maria.pO4ZmF/factor-mysql-retry1.log.
- Failed first-run log retained separately: /tmp/tdpsa-vault-maria.pO4ZmF/factor-mysql.log.

No overlapping migrations/tests or code edits in this area while the run is active. Capture the
mariadb result first; then run relevant broad regression. Verify all private schemas are dropped before shutting down
and removing only this owned server directory. Do not mark current driver verification complete.
No operational DDL/activation, real secrets, Main, production or UI changes. Slice 04 In Progress;
HR-2026-09-04-003 Pending/not practical-review ready, automatic continuation ACTIVE.

Previous Vault continuation checkpoint (2026-09-12, guarded rate anchors and complete internal rate assembly):
VaultRateAnchorContext/GuardDefinitions now allow only an exact one-row empty seed or an audited
threshold lock. Actor/bucket/session identity cannot change, DELETE is denied, REPLACE cannot
discard an existing anchor, and an active lock cannot be extended/reset. Locking requires exact
finalized evaluated-attempt/rate/trust identity and the real 5-session/20-actor rolling count;
the original captured clock determines both the count window and exact 15-minute expiry.
DatabaseVaultStepUpRateRepository uses those contexts for seed/lock writes. VaultRateGuardCatalog
makes the complete anchor+telemetry manifest mandatory for rate preparation and pruning, with
no runtime installation/repair. Cold SQLite connections prepare dormant anchor function names,
not write authority. The migrated attempt test assembly always installs the complete rate set.
Synthetic historical fixture rewrites now occur only between transactions, with a private-target
check and exact guard restoration, so MariaDB fixture DDL cannot auto-commit an active attempt.
Raw/no-op/DELETE/REPLACE, forged source, premature lock, wrong session and incomplete-catalog
denials pass. Final broad units/five migrated SQLite integrations PASS 816 / 25,903 (4:10.531).
Both private-driver retention+independent-worker contracts PASS 2 / 118 each (mysql 57.658s,
mariadb 53.276s): current checkpoint 820 / 26,139, no skips or deferred failures. Both thresholds,
post-lock clocks and non-extending skipped attempts still pass under real PHP-process contention.
Pint passes 12 files; diff/0644/project-owner checks pass. All test runs finished. Private server
75TWyF was verified empty, shut down and removed. Runtime flags remain false and stores unbound;
no operational DDL, real secrets, Main, production or UI changes. Next: full migrated-Maria factor
boundary, then remaining typed owner/consumption/abort/cutover paths before product slices.
HR-2026-09-04-003 remains Pending/not practical-review ready; automatic continuation ACTIVE.

Previous Vault continuation checkpoint (2026-09-12, bounded rate telemetry retention):
DatabaseVaultRateTelemetryPruner now deletes at most 100 rows by default (1..500 enforced), strictly
older than 24 hours, in a separate root-first owned transaction. Nested maintenance is rejected.
Its exact guard catalog and one-use row/evidence/installation/DB-clock context reject raw DELETE,
wrong identity, future cutoffs, changed triggers and non-finalized durable ownership. It never
changes anchors, factors, proofs, permanent attempt evidence or audit. Failure rolls back its batch,
not a previously committed attempt. No scheduler/container binding or operational DDL was added.
VaultRateTelemetryGuardDefinitions permits INSERT only inside the exact consumed attempt-source
context with matching evaluated evidence/building commit; UPDATE is always denied. The attempt
writer keeps source and telemetry within that context. The explicit test assembly can add these
guards, and the real migrated factor boundary now runs with them enabled. Rate-anchor raw DML
guards remain unfinished; do not treat the telemetry guard as the whole rate boundary.
Final broad Vault/UserManagement units plus five migrated SQLite integrations PASS 816 / 25,891
(4:14.237), no skips. New real-writer retention contracts PASS mysql 1 / 25 (19.585s) and
mariadb 1 / 25 (18.549s): current checkpoint 818 / 25,941. A failing MariaDB test exposed mixed
timestamp collations; canonical bytewise comparisons fix it on both drivers. A test-only historical
date vector also now uses DateTime instead of exceeding the reviewed runtime shift bound.
Pint passes eight affected PHP files, diff/0644/project-owner checks pass. All test sessions finished.
Private server 0Zse5X was verified empty, shut down and removed. Live runtime flags remain false;
pruner and attempt coordinator autoload but remain unbound. No operational migration, real secrets,
Main, production or UI changes. Next: raw anchor guards and final rate assembly, full migrated-Maria
factor-boundary coverage, remaining typed owner/consumption/abort/cutover paths, then product slices.
HR-2026-09-04-003 remains Pending/not practical-review ready; automatic continuation is ACTIVE.

Previous Vault continuation checkpoint (2026-09-12, combined factor/rate/proof transaction):
DatabaseVaultStepUpAttemptTransaction now coordinates standard step-up and both enrollment
purposes inside one owned transaction. Rate preparation locks generation metadata before anchors
without reading factors/replay, and gives UserManagement exactly one registered evaluation claim
on the same Connection and captured DB clock. Forged, reused, wrong-connection, locked and
later-transaction claims deny. The verifier's standalone methods retain their top-level boundary;
its explicit owned entry does not start a nested transaction. Standard issuance likewise has an
explicit owned entry, while enrollment uses its existing caller-owned repository.
Failed combined checks retain exact durable denial/rate/audit in the same transaction. Locked
attempts do not invoke hashing or read the factor/replay fields and cannot extend the lock.
Successful checks create/reuse a finalized proof; reuse leaves the original expiry unchanged.
An infrastructure/issuance failure rolls back the real replay CAS, proof, audit, rate and sequence;
the coordinator never commits an accepted counter without its proof. A missing server-side
enrollment generation fails closed rather than using the caller's challenge as authority.
Actual migrated SQLite covers real bcrypt/encryption/TOTP/CAS/writers, failures after real CAS
and real proof finalization, success/reuse/replay, all three proof variants and locked no-factor
reads: 1 / 101 PASS (40.882s). Standalone verifier regression 8 / 69 and owned-entry/rate unit
contracts 2 / 41 pass. Final broad units plus five migrated integration files PASS 815 / 25,862
(4:07.438), no skips. Extended private-driver contracts PASS mysql 1 / 81 (29.944s) and
mariadb 1 / 81 (27.087s), giving current checkpoint 817 / 26,024, no failed check deferred.
Driver tests use six independent PHP processes at the fifth-failure threshold and two racing
processes after nineteen real audited failures on distinct sessions. Exact root reads are observed
waiting for 100ms behind the owner lock; captured attempt times must follow release. Outcomes are
exactly one threshold lock and one skipped attempt, never undercounting/extending either threshold.
The unindexed fixture's filesort wait was not exposed by INNODB_LOCK_WAITS; the test now observes
the exact active root queries instead. This is not full migrated-Maria factor-boundary or power-loss
verification. Runtime flags remain false and new coordinator/rate store unbound; no operational
migration, runtime activation, real credentials, Main, production or UI writes.
All nine affected PHP files pass final Pint; git diff --check and new-file 0644/project-owner
checks pass. All test sessions finished. Owned server fF2u1U was verified empty, shut down and
removed; task transport patches are cleaned, preserving the unrelated audit-persistence patch.
Next: bounded telemetry pruning/raw rate-write guards, full driver-boundary coverage, then remaining
typed authority/consumption/abort and owner/cutover paths before the workspace/product slices.
HR-2026-09-04-003 remains Pending/not practical-review ready. Automatic continuation ACTIVE.

Previous Vault continuation checkpoint (2026-09-12, durable attempts and rate persistence):
DatabaseVaultStepUpAttemptWriter now creates immutable failed-attempt evidence, its evaluated-only
rate row and exact denied audit under one caller-owned transaction. Optional explicit source/
commit/trust guards bind actor/session, retained generation, reason, time, owner and audit refs;
audit trust is finalized last. Skipped/rate_limited attempts require an already-active anchor and
never add telemetry or extend a lock. Source UPDATE/DELETE, substituted audit and wrong/missing
rate rows deny. The default proof assembly remains compatible; optional-manifest probing now
preserves the original missing-guard diagnostic (two failed-before/passed-after regressions).
DatabaseVaultStepUpRateRepository locks root/authority/gate/user and both exact anchors, creates
missing anchors without overwriting existing state, then captures one DB rate instant. Its state
is one-use, bound to the actor/session/auth epoch and the exact transaction identity. The real
attempt writer, rolling counts and 15-minute lock update share that transaction. Five session
failures or twenty actor failures across sessions lock; active locks only emit skipped evidence.
Window counting uses an exclusive lower/inclusive upper bound, and excludes future telemetry.
Actual migrated SQLite tests cover both attempt kinds/evaluation branches, full outer rollback,
threshold rollback, fifth/twentieth failure, non-extending skipped attempts and stale/reused state.
Standalone integrated test PASS 1 / 129 (42.885s). Synthetic source/finalizer contracts PASS
3 / 200 per MariaDB driver; real writer/rate repository contracts PASS 1 / 25 per driver
(mysql 19.668s, mariadb 17.439s). These are not multi-worker concurrency or full-Maria migration tests.
Final broad units/four migrated integration files PASS 813 / 25,745 (3:28.338), no skips.
Together with the two source/finalizer/rate driver sets (4 / 225 each), the completed current
checkpoint is 821 / 26,195. All test sessions completed; no failed verification is deferred.
This checkpoint is internal implementation progress, not completion of Slice 04 or Vault.
Pint passes all 11 affected PHP files; diff and 0644/project ownership checks pass. Private server
M8tXPn was verified to contain zero test schemas, shut down and removed. Live Dev flags remain
false, both new stores autoload and remain unbound. No operational migration, real credentials,
runtime activation, Main, production or UI changes. Applied local transport patches are cleaned.
Next: connect rate preparation, UserManagement factor verification/replay and proof issuance in
one owned transaction (existing verifier/issuer wrappers each own a top-level transaction and must
not be nested). Finish concurrent attempts/pruning/raw rate-write boundaries, then remaining typed
authority/consumption/abort and owner/cutover paths before the workspace and other product slices.
HR-2026-09-04-003 stays Pending/not practical-review ready. Continuation ACTIVE; no human action.

Previous Vault continuation checkpoint (2026-09-12, standard and enrollment proof lifecycles):
DatabaseVaultStepUpProofLifecycleRepository connects real slot lookup, create/reuse/expired
replacement, source/audit writers and trusted final read-back under one owned transaction.
Reuse preserves the original row/expiry; supplied snapshots are re-read, wrong sessions and
premature expiry deny, and failure rolls back old/new proofs, audit and sequence together.
Non-expiry drift remains closed pending its original authority/UserSecurity mutation owner.
A failing-before/passing-after regression fixed revalidation's physical root/authority/gate/user/
generation lock order and denial while an authority/security fence is held. Actual migrated SQLite
now verifies bcrypt, encrypted TOTP, real replay-counter CAS, proof issuance and replay denial.
No public binding or runtime activation; this is not the complete rate/attempt/HTTP boundary.
DatabaseVaultTotpEnrollmentProofLifecycleRepository now also revalidates the exact pending
generation/result/counter and actor/session/epochs under root-first locks. It issues/reuses or
replaces an actually expired same-purpose proof via the real writers/finalizers. Both confirmation
and cooling-recovery purposes are tested; neither issuance nor reuse confirms the factor or
authorizes recovery. Wrong kind/generation/counter/epoch/session, future verification and held
security/authority fences deny without new evidence. Non-expiry drift needs its original owner.
Actual migrated SQLite verifier/replay/issuance passes 2 / 64 (57.319s) with test-owned bcrypt,
encryption key and TOTP secret. These are internal components, not the completed rate/attempt API.
Final broad Vault/UserManagement units and all three migrated integration files PASS
808 / 25,387 (2:48.334), no skips. Both repository contracts PASS mysql 2 / 114 (1:00.915)
and mariadb 2 / 114 (1:05.560): current final checkpoint 812 / 25,615. Six affected PHP files
pass Pint; git diff --check passes. New PHP files are 0644 with matching project ownership.
Both owned private servers bzY2sz and o5Tjmc were verified empty, shut down and removed;
no test sessions remain running. Applied task-owned transport patches are cleaned separately.
Live Dev flags remain false; both repositories and invalidation/primary-audit/login adapters are
unbound, and both new classes autoload. No migration, operational provisioning or production write.
Next concrete work within Slice 04: durable failed-attempt source/evidence/audit finalization before
joining rate enforcement, then the remaining typed authority/consumption/abort and owner/cutover
paths. The workspace and other approved product slices follow Slice 04. HR-2026-09-04-003 stays
Pending/not practical-review ready. Automatic continuation is PAUSED for delivery-workflow evaluation.

Previous completed Vault checkpoint (2026-09-12, primary rehash audit and verified login actor):
The explicit full assembly now supports user_security_password_rehashed: exact consumed subject,
actor/session/epoch and audit shape, binding during gate-owned execution finalization, primary trust
LAST. DatabaseVaultUserSecurityPrimaryAudit creates/reuses only the exact pending primary event;
the default store still denies rehash unless both explicit proof-invalidation and primary-audit ports
are supplied. UserSecurityVerifiedLoginActorContext exposes preauthentication identity only after
the domain's locked password preflight succeeds and while the same transaction/PDO is owned.
Dispatcher cleanup never creates a logged-in session or Vault step-up. The actual bcrypt boundary
now passes incorrect-password/no-write, audit-failure/full rollback, successful rehash and repeated
needsRehash=false no-op. The real store test covers proof invalidation, effect/audit rollback,
retained-source resume, wrong primary identity, direct trust/binding denial and trusted read-back.
SQLite CASE (not boolean short-circuit assumptions) owns nested coupling; dormant evidence UDF
names are prepared without authority for first-login/no-prior-proof connections. The old minimal
proof fixture gained the real vault_authority_epoch column; production predicates were not weakened.
A regression test first demonstrated later-transaction identity reuse on the same PDO. A fresh
opaque VaultImmediateTransaction ownership identity now binds the verified actor to exactly one
run; later transactions, PDO reconnection, nesting and failure cleanup are tested. The actor scope
also forbids cloning. Current broad SQLite verification PASSES 802 / 25,183 (1:52.972), no skips.
Existing store/resume and Account/Fortify regression PASSES 29 / 306 (7:05.877): completed current
SQLite/Feature checkpoint 831 / 25,489. Eighteen initially changed PHP files passed Pint; the
transaction-identity change and its two consumers also pass Pint. git diff --check passes.
Full migrated private mysql/mariadb runs completed: 1 / 236 (38:04.728) and 1 / 237
(37:51.741). Completed checkpoint total 833 / 25,962, no skips. Both private schemas were
removed; the empty socket-only bzY2sz server was reused for the newer repository tests above,
then verified empty, shut down and removed. Operational data was unchanged.
Live Dev read-back: both runtime flags false; invalidation/primary-audit ports and login scope unbound.
Task-owned transport patches are cleaned after application; the unrelated audit-persistence patch remains.
Next at that checkpoint: finish remaining credential/owner/cutover work,
then the workspace and remaining approved slices. HR-2026-09-04-003 remains Pending/not review-ready.
No operational migration, real credentials, Main or production changes. Automatic continuation active.

Previous completed Vault checkpoint (2026-09-12, integrated UserSecurity proof invalidation):
The prior full migrated proof-issuance runs completed: mysql 1 / 82 (27:11.347), mariadb 1 / 83
(27:30.750). Their schemas and owned /tmp/tdpsa-vault-maria.IFuqrv server were verified empty,
shut down and removed. That completed the previous checkpoint at 750 / 25,441 without skips.
DatabaseVaultUserSecurityProofInvalidationWriter now owns source/commit/audit creation, exact
retained request/subject checks, reason derivation and terminal read-back under the caller's
transaction. It enumerates every affected user's active sessions across both proof families and
can finish an already-audited building source after the unchanged original expiry. New source
scope stays time-limited. Failed members roll back the whole caller transaction; parent audit
trust and gate remain pending. Synthetic tests cover both enrollment purposes, human/system
executors, drift, reason mapping, multi-session rollback, unrelated users and expired resume.
MariaDB's unit fixture user primary key was corrected from TEXT to BIGINT, matching Nexum;
no application guard was weakened to bypass the resulting spurious collation error.
An explicit, UNBOUND UserSecurityProofInvalidation port now connects the full assembly to
DatabaseUserSecurityMutationStore. Its default active-proof denial remains unchanged. The opt-in
adapter requires the exact complete proof/UserSecurity/replay/audit manifest, revokes before
credential effects, resumes retained sources and lets the parent finalize trust/gate LAST.
Actual migrated SQLite issuance plus a real remembered-session credential mutation, full rollback,
forced commit and exact store resume pass 1 / 163 (36.872s). Bootstrap/factor facts remain synthetic;
this is not credential verification or operational provisioning. Dormant SQLite replay function
names are prepared without activating or resetting verifier authority; dedicated tests pass.
Before the store integration, broad SQLite passed 740 / 25,250 (2:59.631); selected writer contracts
passed mysql/mariadb 6 / 384 each (6:02.623, 6:00.113). Final units/core/writer/precision now PASS
743 / 25,367 (3:05.336). Strict full-assembly rejection passes mysql/mariadb 1 / 3 each (21.283s,
18.031s). Existing store/resume Feature regression passes 18 / 230 (7:19.272). Ten affected PHP
files pass Pint; git diff --check passes. Live Dev boot read-back confirms vault_enabled=false,
runtime_approved=false and invalidation_port_bound=false. Applied task-owned transport patches
were cleaned; the pre-existing audit-persistence patch was preserved. Full migrated integration
now PASSES: mysql 1 / 163 (34:59.595), mariadb 1 / 164 (34:44.574). Completed verification totals
777 / 26,698, no skips. Both private schemas were removed; the exact empty socket-only
/tmp/tdpsa-vault-maria.GlDm0B server was verified, shut down and removed. No tests remain running.
Legacy migrations used mysql before restoring the requested driver and isolated Email preflight
900s; flush=2 verified logical transactions, not crash durability. Operational DB was unchanged.
At that checkpoint, primary rehash audit, remaining credential flows and source/owner/cutover
integration still preceded the workspace. See the newer checkpoint above. Slice 04 and
HR-2026-09-04-003 remain unfinished/not review-ready. Runtime and the new port remain unbound/off.
No operational migration, real secrets, Main or production changes. Automatic continuation is active.

Earlier Vault checkpoint (2026-09-12, shared proof/audit assembly): complete parent
guard attestation and dormant SQLite UserSecurity function preparation are implemented. The full
variant requires exact proof/trust, all UserSecurity/replay, and runtime audit-table guards; partial
or altered assemblies reject. Dormant functions compile but never supply an inactive capability or
reset a live/consumed context. A real migrated SQLite writer test exposed the legacy audit validator's
rejection of Row04 events. The explicit replacement validator now preserves structure/reference/
metadata checks and shares the exact one-use owner/vocabulary predicate with the AFTER trigger.
No deployed legacy validator was replaced. Real schema standard/enrollment creation, trust and outer
rollback now pass; malformed matching-context rows fail on SQLite and both MariaDB driver names.
Final units plus both SQLite integration classes: 725 / 24,746 (2:40.945). Selected mysql/mariadb:
2 / 96 each (1:00.476 and 0:59.438), totaling 729 / 24,938, no skips. The earlier broader run including
existing UserSecurity store/resume passed 743 / 24,961 before the final additional code/date checks.
Ten PHP files pass Pint; git diff --check passes. Private MariaDB was removed after zero application
schemas remained; no tests remain running. HR-2026-09-04-003 records fixture/driver limits.
Next: internal UserSecurity proof invalidation writers, complete enumeration and exact owner resume;
then rehash-primary audit and other source/owner/cutover integration. Also complete the already-planned
audit timestamp precision transition: deployed foundation migration uses DATETIME(0) on MariaDB,
while Row04 requires DATETIME(6). Full actual-Maria writer integration must verify that frozen cutover,
not assume the synthetic TEXT fixtures cover it. denyActiveProofs stays closed. Runtime/public bindings
remain off; no operational migration, real secrets, Main or production changes. Human review is not ready.

Earlier Vault checkpoint (2026-09-12, UserSecurity proof-set finalizer): an explicit
non-operational assembly now finalizes exact pending terminal audit projections inside the owning
gate statement, before its existing execution/gate-LAST completion. A database-owned coupled marker
prevents a consumed standalone context from becoming a trust-write capability. The full physical
subject/source set and absence of active proofs in every changed user's sessions are checked.
Exact audit-event identity prevents borrowing another pending projection. MariaDB identity/context
comparisons now use binary equality after a real mixed-collation failure; no database settings were
relaxed. Units/core plus existing UserSecurity store/resume pass 738 / 24,628; mysql/mariadb each
pass 5 / 166 (748 / 24,960 combined, no skips). Eight PHP files pass Pint and git diff --check.
The actual-schema Row04 MariaDB contract also passes 4 / 22 (26:26.550): final combined evidence
is 752 / 24,982, no skips. No tests remain running; the private server was removed after zero
remaining schemas were verified. See HR-2026-09-04-003 for scope and fixture limits.
Next: integrate the shared exact guard catalog and dormant SQLite UDF registration across the whole
assembly, then the internal invalidation writers/all-proof enumeration and exact owner resume.
Password-rehash primary audit, other terminal causes, complete issuance and owner/cutover paths remain.
The existing UserSecurity store's denyActiveProofs remains fail-closed until that full integration
passes. HR-2026-09-04-003 is Pending/not practical-review ready; runtime and public bindings stay off.
No operational migration, Main, production or real credentials changed. Automatic continuation stays active.

Earlier Vault checkpoint (2026-09-12, UserSecurity proof transitions): one-use
source guards now bind the exact gate-owning execution, canonical human/system executor,
credential OLD-to-NEW epoch subjects and historical proof creation trust. The existing transition
row is the per-proof source; the closed 18-kind UserSecurity subject vocabulary is unchanged.
Coupled terminal finalization verifies the exact audit and revokes only that proof, retaining
the parent execution/gate and leaving terminal audit trust pending. Expiry cannot capture this
branch, and a replacement cannot activate while the security gate is retained. Real human audit
insertion and consumed epoch post-state, dependency drift, nested failure and outer rollback
are tested. Final units/core pass 716 / 24,272; selected mysql/mariadb cases pass 5 / 225 each,
totaling 726 / 24,722 without skips. Six PHP files pass Pint; git diff --check passes.
Next: complete coordinated UserSecurity audit-trust finalization and gate-LAST integration,
all-active-proof enumeration, guarded internal writers and exact owner resume. denyActiveProofs
remains fail-closed until that integration is complete. Other typed terminal causes, full issuance,
owner/origin and cutover integration still precede the workspace. HR-2026-09-04-003 remains
Pending/not practical-review ready. Runtime is disabled/unapproved; recorder/fresh-proof ports
are unbound. No operational migrations, Main, production or real credentials were changed.
Automatic continuation remains active; no further go-ahead is needed.

Earlier Vault checkpoint (2026-09-12, enrollment/expiry writers): the pending writer
is now DatabaseVaultPendingProofWriter and handles standard plus purpose-bound enrollment facts.
Enrollment issue time is captured from the database and expiry is exactly 300 seconds later;
its source/event/reason/reference mapping is distinct for confirmation and cooling recovery.
DatabaseVaultExpiredProofReplacementWriter creates a separate expiry commit, append-only source
and real typed audit, then invokes the coupled terminal finalizer. Tests run pending creation,
old revocation and new activation together through real internal writers for all three variants.
Nested audit failure and outer failure roll back old/new states, evidence and sequence allocation.
The synthetic legacy SQLite fixture was corrected to canonical BLOB session storage; runtime
comparison was not relaxed. Final units/core: 706 / 23,949 (1:59.602); relevant standard/new writer
cases pass 7 / 213 each on mysql and mariadb (3:16.025 and 3:14.862), totaling 720 / 24,375,
no skips. Four changed PHP files pass Pint and git diff --check is clean. No tests remain running;
the private MariaDB server was shut down/removed after zero private schemas were verified.
Next: complete distinct UserSecurity/authority/consumption/abort terminal branches and the full
issuance/lifecycle repository. UserSecurity's denyActiveProofs remains deliberately fail-closed
until all enumerated proof transitions, audit projections and its gate LAST finalizer are integrated.
The final issuance coordinator must revalidate credentials and create each pending replacement
inside that same owned transaction, not adopt an older persisted pending source. These internal
writers are not public issuance endpoints. Remaining owner/origin/plan/cutover integration precedes
workspace. HR-2026-09-04-003 stays Pending/not practical-review ready. Runtime, Main, production
and operational migrations are unchanged. Automatic continuation stays active without a new go-ahead.

Earlier Vault checkpoint (2026-09-12, expiry finalizers): the explicit integrated proof assembly now
couples expired-on-retry transition commit finalization, the old proof's single terminal claim,
and exact terminal audit trust LAST, before replacement activation. Both proof kinds/purposes,
active-slot uniqueness, competing claims, exact audit identity, direct-write rejection and nested/
outer rollback are covered. The expiry-only application finalizer checks current actor/session,
retained root/security locks, exact guard catalog and terminal read-back. A standard pending-proof
writer now allocates its own evidence sequence/commit, uses the one-use source context and real
audit insertion, and leaves activation to the creation finalizer. The opaque session is bound
directly to a fixed PDO statement, not copied into query-binding logs. Both writers remain internal.
Final Vault units/core: 701 tests / 23,755 assertions (1:54.296). The full earlier driver run
passes 19 / 2,144 each; the final added race/pending-writer cases pass 3 / 37 each on mysql and
mariadb. Together: 745 / 28,117, no skips. Actual migrated SQLite core compiles the integrated
guards; dependency-mutation fixtures do not establish operational deployment or full issuance.
Enrollment and expiry-source writers are now verified in the newer checkpoint above. Complete
the issuance/lifecycle repository and each distinct UserSecurity/authority/consumption/abort terminal branch. Then finish
the remaining plan/subject, owner/origin, cutover and authorization integration before workspace.
Never substitute a generic stale-proof bypass. HR-2026-09-04-003 remains Pending, not ready for
practical review. Live boot still has enabled=false/runtime_approved=false and both foundation
recorder/fresh-proof ports unbound. No operational migration, runtime activation, Main or production
change occurred. Automatic continuation is now PAUSED for delivery-workflow evaluation with Svein.

Earlier Vault checkpoint (2026-09-12): coupled creation now finalizes the exact evidence
commit, activates its pending standard/enrollment proof, and finalizes audit trust LAST. The
guarded application finalizer retains root/security/source locks and requires exact trigger
catalogs, current actor/session/purpose and final trusted read-back. The ordinary cutover
catalog remains frozen; this explicit integrated assembly is not operationally installed.
Final expanded units/core passed 690 / 23,129 and both mysql/mariadb contracts passed
11 / 1,566 each: 712 tests / 26,261 assertions, no skips. All six changed PHP files pass
Pint --test; git diff --check passes. No test remains running. The isolated MariaDB server
was shut down and removed after zero remaining private schemas were verified.
Expired-on-retry source creation now additionally requires the exact old trusted creation,
elapsed expiry, same actor/session/purpose pending replacement, current security/factor/fences
and the separate building transition commit. Source INSERT is one-use and append-only; it
does not revoke or finalize anything. Final units/core passed 693 / 23,364 and the new same
source cases passed 3 / 231 on each mysql/mariadb driver (699 / 23,826, no skips). No tests
remain running; the private server was removed after zero-schema verification.
The then-next terminal coupling is now implemented and verified in the current checkpoint above.
Other typed terminal branches and the complete issuance/lifecycle repository remain outstanding.
The ten-minute thread heartbeat remains active; no further go-ahead is required. No Main,
production, operational migration or real-credential activation is authorized by these tests.

Latest Vault verification (2026-09-12): current standard and purpose-bound enrollment proof
readers now connect to the central freshness contract through DatabaseVaultFreshProofEvidence.
Both reload current actor/session/epoch/generation and exact finalized source/audit/trust under
the operation-owned transaction; enrollment purpose cannot substitute for ordinary step-up.
Final Vault units passed 674 / 20,916 and the same reader/adapter cases passed 14 / 403 on each
isolated MariaDB driver (702 / 21,722 total, no skips). See HR-2026-09-04-003 for fixture limits.
The adapter remains unbound and runtime disabled. Next: use these live readers in the complete
source/operation protocol, validate canonical planned subject values, finish protected source
DML/owner-origin finalizers, shared trust and cutover/repositories before the workspace.
HR-2026-09-04-003 remains Pending, not practical-review ready. Automatic continuation is active.

Previous Vault verification (2026-09-12): selected approval-source trust is implemented and its
three-driver/core contracts passed 691 / 43,091. Authority finalization now additionally checks
ordinary building lifecycle reservations versus exception-origin finalized consumption/open
obligation evidence and retained fence identities. The pure lifecycle now accepts peer-approved
TOTP enrollment with its exact enrollment proof. Units/SQLite passed 675 / 28,752; final phase
mysql/mariadb and actual migrated-core verification passed 17 / 16,750 (692 / 45,502 across
the two final runs, no skips). See HR-2026-09-04-003 for regression evidence and limits.
Continue Slice 04 with live proof/factor and canonical-payload/protected-write validation,
complete source/origin finalizers, shared trust, cutover and repository integration.
Ordinary consumption finalizes AFTER the applied operation; exception origins finalize their
obligation/consumption first under retained fences. Do not impose a blanket finalized-consumption
prerequisite. HR-2026-09-04-003 remains Pending, not practical-review ready. Runtime/workspace
remain disabled. Automatic continuation remains active without another go-ahead.

Last reconciled against authoritative Dev on 2026-09-04.

- Proposed ADRs: none. Current ADR inventory is 46 Accepted and 3 Superseded.
- Approved RFCs and Accepted ADRs are not open work by status alone; their implementation is tracked
  through the linked workstream and Feature Slices.
- Dependency-gated queued Mail slices remain under their parent Mail workstreams instead of being
  treated as independent active implementations.

| Artifact | Status | Owner / Next Gate |
| --- | --- | --- |
| [Vault RFC](rfc/2026-09-04-vault-domain-and-operational-credential-platform.md) | Foundation Done On Dev / Slice 04 In Progress / HR Pending | RFC Approved and all 12 security ADRs Accepted. Foundation Slices 01-03 are implemented, migrated, read back, and independently reviewed while runtime remains disabled. Slice 04 passed both pre-code reviews and Svein Tore authorized implementation on 2026-09-05; HR-2026-09-04-002 and HR-2026-09-04-003 remain Pending. |
| [Vault governance](feature-slices/2026-09-04-vault-governance-threat-model-inventory.md) | Done On Dev | Canonical RFC/ADR files, threat model, credential inventory, completion matrix, dependency gates, and first implementation slice are reconciled. No runtime or credential data changed. |
| [Vault control plane and crypto foundation](feature-slices/2026-09-04-vault-control-plane-and-crypto-foundation.md) | Done On Dev / HR Pending | Dormant fail-closed module, independent envelope crypto, immutable versions/material boundary, safe audit, exact control-plane permissions, migrations, tests, and documentation are complete on Dev. No real credential or UI/API/runtime consumer exists; HR-2026-09-04-002 remains Pending. |
| [Vault central authorization, grants, collections, step-up, and approvals](feature-slices/2026-09-04-vault-central-authorization-grants-collections-step-up-approvals.md) | Approved / In Progress | Codex: foundation finalization/guarded writer adapter plus exact authority audit/plan-requester/proof bindings and completed witness/UserSecurity physical-set checks are implemented. Latest red/green evidence and fixture limits are in HR-2026-09-04-003. Shared Row04 trust UPDATE remains closed and the recorder port unbound. Next: exact planned subject values, proof/approval source provenance and protected source DML; then coordinated owner/source/abort finalizers, permanent-guard stages/final cutover, repositories, classification and integrated Slice 04 verification before Documentations -> Vault. Measure representative backfill volume before operational cutover. No operational Row04 migration, runtime/content activation or standalone thaw. Human review remains Pending and not ready for practical use. Automatic continuation is active; ordinary continuation needs no further user approval. |
| [Vault-required Connection Broker and Execution dependencies](plans/2026-09-04-vault-completion-matrix.md) | Scope Approved / Queued after active Slice 04 | Svein explicitly approved the necessary #270/#272 implementation on 2026-09-10. Codex read back both published RFC comments; their GitHub labels still say Draft and were not changed. Reconcile the Integration Hub candidate and record the necessary technical ADRs/Feature Slices before dependent code. This is not approval to implement the unrelated full AI/Automation platform or activate Vault. |
| `docs/rfc/2026-09-03-canonical-contact-workflow-and-legacy-cutover.md` | Done On Dev / Reviewed | GitHub Issue #253 is implemented, evidence-commented, and closed. Svein approved `HR-2026-09-03-005` on 2026-09-04 after authenticated Dev browser verification; the real production backup, migration, read-back, and relationship checks remain operational deployment gates. |
| `docs/adr/2026-09-03-contact-canonical-identity-and-legacy-bridge.md` | Accepted | Contact is canonical; stable internal Client User bridge IDs preserve Ticket, Asset, Sales, Nextcloud, Marketing, Telephony, Intake, User, and polymorphic history. |
| `docs/feature-slices/2026-09-03-canonical-contact-client-workflow.md` | Done On Dev | Client, Site, central Contacts, Client creation, legacy aliases, permissions, duplicate handling, stable bridges, docs, and focused regressions are complete. |
| `docs/feature-slices/2026-09-03-legacy-contact-production-cutover.md` | Done On Dev | The automatic forward-only migration, idempotent command, guarded Marketing enrichment, downstream canonical backfill, preserved legacy IDs, fail-closed conflicts, and read-back evidence are complete. |
| `docs/rfc/2026-09-03-web-push-notification-type-registry.md` | Done On Dev | GitHub Issue #257 is implemented and verified on authoritative Dev. The registry covers all 27 current types, nine internal types are explicitly Web Push-eligible, and every exclusion has a reason. Human review remains `HR-2026-09-03-004`; Main/production promotion remains Svein's decision. |
| `docs/feature-slices/2026-09-03-web-push-notification-registry-delivery.md` | Done On Dev | Authoritative type/channel metadata, safe queued best-effort delivery, current preference/recipient/permission/target checks, minimal payloads, and affected regressions are verified. The specialized inbound Email outbox remains unchanged. |
| `docs/feature-slices/2026-09-03-web-push-preference-ui-documentation.md` | Done On Dev | Grouped, described, responsive and keyboard-native internal preferences are implemented and source-contract tested. Knowledge documentation is current; visual/device checks remain in `HR-2026-09-03-004`. |
| `docs/rfc/2026-09-03-task-templates-and-scheduled-generation.md` | Done On Dev | All six slices are implemented and verified on authoritative Dev. Human review remains `HR-2026-09-03-001`; Main/production promotion remains Svein's decision. |
| `docs/adr/2026-09-03-task-template-application-boundary.md` | Accepted | Task owns mutable templates, atomic group application, generated-task snapshots, and idempotent generation evidence; caller domains retain their rule authority. |
| `docs/feature-slices/2026-09-03-task-template-application-foundation.md` | Done On Dev | Atomic Task-owned generation, provenance, graph validation, idempotency, and migration are verified. |
| `docs/feature-slices/2026-09-03-task-template-admin-ui.md` | Done On Dev | Simple direct-edit CRUD, navigation, graph fields, permission gates, and guarded deletion are implemented. |
| `docs/feature-slices/2026-09-03-task-template-recurring-schedules.md` | Done On Dev | Schedule CRUD, locked due generation, Generate now, history, and verified Dev external runner are implemented. |
| `docs/feature-slices/2026-09-03-task-template-ticket-client-application.md` | Done On Dev | Ticket/Client chooser, no-write preview, visibility checks, and idempotent application are implemented. |
| `docs/feature-slices/2026-09-03-task-template-rule-actions.md` | Done On Dev | Signal, Ticket, and RMM rules call the shared Task boundary with selectors, audit, target validation, and idempotency. |
| `docs/feature-slices/2026-09-03-task-template-owner-search-and-recurring-ticket.md` | Done On Dev | Searchable User/Client selection replaces owner IDs, Ticket is rejected as a future schedule owner, and recurring Ticket occurrences receive the selected Task group once. |
| `docs/rfc/2026-09-03-knowledge-bookstack-revision-safe-sync.md` | Done On Dev | GitHub Issue #276 is implemented on authoritative Dev and closed as completed. Human review remains `HR-2026-09-03-003`; Main/production migration and policy activation remain Svein's decision. |
| `docs/adr/2026-09-03-knowledge-bookstack-three-way-sync.md` | Accepted | Immutable article revisions plus a per-article proven base govern BookStack three-way comparison, conflict candidates, exact outbound delivery, and read-back. |
| `docs/feature-slices/2026-09-03-bookstack-revision-identity-and-baseline.md` | Done On Dev | Immutable canonical revisions and honest unknown baselines are implemented and verified. |
| `docs/feature-slices/2026-09-03-bookstack-three-way-sync-and-readback.md` | Done On Dev | Clean inbound, conflict preservation, stale-job safety, exact outbound read-back, retries, deletion, and loop prevention are implemented. |
| `docs/feature-slices/2026-09-03-bookstack-conflict-review-and-operations.md` | Done On Dev | Authorized review actions, settings/status visibility, sanitized errors, and operational documentation are implemented. |
| `docs/rfc/2026-09-04-knowledge-revision-approval-publication.md` | Done On Dev | GitHub Issue #266 is implemented and verified in the authoritative Dev working copy. Human UI and deployment review remains `HR-2026-09-04-001`; Main/production promotion remains separate. |
| `docs/adr/2026-09-04-knowledge-published-surface-and-revision-state-machine.md` | Accepted | The Article row remains the published projection; immutable revisions, exact-object Ticket review, protected publication actor, and read-back own lifecycle truth. |
| `docs/feature-slices/2026-09-04-knowledge-revision-foundation.md` | Done On Dev | Schema, exact baselines, permissions, protected actor, immutable snapshots, transition audit, and stale-base guards are implemented and verified. |
| `docs/feature-slices/2026-09-04-knowledge-review-publication-ui.md` | Done On Dev | Manual/API proposals, exact diff/full preview, approval, publication, retry, conflict, and rollback are implemented and verified. |
| `docs/feature-slices/2026-09-04-ticket-documentation-revision-boundary.md` | Done On Dev | Durable follow-ups and exact Ticket-scoped AI revision review without broad Knowledge or publication access are implemented and verified. |
| `docs/feature-slices/2026-09-04-knowledge-publication-readback-authority.md` | Done On Dev | Local/BookStack read-back, retry truth, portal notification timing, and repository authority are implemented and verified. |
| `docs/rfc/2026-05-31-technician-profile-consolidation.md` | Draft | Reconcile approval history and finish or explicitly defer the remaining UserManagement skills ownership decision. |
| `docs/rfc/2026-06-05-client-deletion-retention-policy.md` | Draft | Product decision and RFC approval required before implementation. |
| `docs/feature-slices/2026-05-31-work-hours-and-skills-migration.md` | Partially Implemented | Finish or explicitly defer the general-skills ownership decision; keep aligned with the technician-profile RFC. |
| `docs/feature-slices/2026-07-14-ai-privacy-gateway-routing.md` | In Review | Reconcile with the Organization-controlled AI workstream and its human-review gate. |
| `docs/feature-slices/2026-07-14-coordinator-progressive-context-profiles.md` | Draft | Do not start until the active privacy/coordinator work is completed or concretely blocked. |
| `docs/feature-slices/2026-07-20-cloudfactory-production-validation.md` | In Progress | Complete the recorded live-validation gates before new CloudFactory expansion. |
| `docs/feature-slices/2026-07-22-cloudfactory-legal-documents-and-portal-ordering.md` | Ready For Human Review | Human review remains the next gate. |
| `docs/feature-slices/2026-08-11-storage-automatic-ai-profile-bootstrap.md` | Ready | Named desktop/narrow-width and controlled real-email review remains. |
| `docs/feature-slices/2026-08-11-storage-operational-supplier-order-setup.md` | Ready | Named desktop/narrow-width review remains. |
| `docs/feature-slices/2026-08-15-email-mail-inbound-attachment-recovery-download.md` | Rework Needed / Partial Recovery | Complete canonical evidence and operator repair before adjacent Mail expansion. |
| `docs/feature-slices/2026-08-19-email-ticket-correlation-conflict-triage.md` | Done On Dev / Human Review Reopened | Run the new browser/runtime checks under `HR-2026-08-16-014`; implementation is complete and no longer blocks Order 15. |
| `docs/feature-slices/2026-08-19-email-ticket-not-ticket-merge-compatibility.md` | Done On Dev / Human Review Pending | Implementation and automated Dev verification are complete; run the exact browser/runtime checks under `HR-2026-08-16-015`. |

## Status Legend

- `Ready`: can be picked up now.
- `In Review`: the proposed direction is being reviewed before implementation approval.
- `In Progress`: someone is actively working on it.
- `Blocked`: needs a decision or prerequisite.
- `Done`: implemented, tested, and documented.

## Active Workstreams

| Area | Status | Owner | Notes |
| --- | --- | --- | --- |
| Vault Domain And Operational Credential Platform (#279) | Foundation Done On Dev / Slice 04 In Progress / Runtime Disabled / HR Pending | Svein / Codex | Governance and foundation Slices 01-08 are Done On Dev. Slice 04 passed both pre-code reviews and Svein Tore authorized implementation on 2026-09-05. Implement its exact default-deny authorization, groups/collections, two permissions, Client visibility, epochs, replay-safe step-up, approval, concurrency, quorum-lock and recovery contract on Dev. Vault still has no route/UI/API, real credential, key provisioning, content operation, or runtime consumer. HR-2026-09-04-002 and HR-2026-09-04-003 remain Pending. |
| Canonical Contact Workflow And Legacy Cutover (#253) | Done On Dev / Reviewed / Production Pending | Svein / Codex | Contact is the sole visible Client/Site/central workflow. Stable Client User IDs retain every legacy-only relationship; the automatic migration adds canonical identity and guarded Marketing evidence without sending. The affected matrix passes 96 / 787, authenticated desktop and 390 px browser verification passed, Issue #253 is closed, and Svein approved `HR-2026-09-03-005` on 2026-09-04. Production backup, migration, idempotent read-back, historical relationship checks, and smoke verification remain mandatory deployment operations. |
| Broad Dev Regression Follow-up Discovered During #253 | Reconciliation Required / Outside #253 | Current domain owners | The complete run passed 2,535 tests / 24,636 assertions with 13 failures. Twelve reproduce outside Contact/Clients/Marketing: Commercial 2, Email conversation UI 1, Integration provider-verification rendering 5, Notification durability 2, and Ticket Rule evidence 2; the Email provider-health deadline failure passed isolated. Reconcile the current concurrent working-copy changes and rerun the complete suite without weakening domain guards. |
| Dev Database And Email Private-Storage Reconciliation | Blocked / Operator Database Selection Required | Svein / Operations | A fresh read-only check on 2026-08-30 found that the configured MySQL database `tdPSA_` currently contains 0 Email accounts, 0 Email messages, and 0 queued jobs while the canonical private Email tree contains 1,546 files. The inventory therefore classified all files as unreferenced and cannot supersede the earlier coherent 2026-08-24 evidence. Do not delete, move, copy, or infer retention from this result. Confirm the intended authoritative Dev database and recovery state, then rerun the inventory. The exact 79 regular `www-data:www-data` files at mode `0644` were independently revalidated with zero symlinks, but mode repair remains blocked because non-interactive sudo is unavailable. Track the required operator checks under `HR-2026-08-30-001`. |

| Email Provider Administration And Critical Ticket Delivery Safety | Provider Lifecycle Retired / Delivery Safety Human Review Pending | Svein / Codex | The provider-first and migration lifecycle is retired in favor of the Simple Email Account Connection Setup below; the transport and delivery-safety backend remains. Safe verification failures no longer return an opaque server error. Ticket reply SMTP uses a pre-send preparation phase, one durable reservation and no blind replay after an ambiguous provider outcome. Ticket status distinguishes reserved, SMTP accepted and unresolved, while Mail health scopes failed jobs to `default`/`email`. Inbound evidence conflicts no longer guess a Ticket and are resolved through the audited admin queue. Current Dev has one active shared account-owned Email account with `OK` health and ready runtime; no account is provider-bound, no provider ciphertext remains, normal Dev has zero provider lifecycle routes, and the stale Email Providers card was removed from Integrations on 2026-09-02. The earlier controlled E2E message was accepted by SMTP, imported into INBOX, produced no Ticket while ticket ingress remained disabled, was deduplicating-appended to Sent, imported, and reached `reconciled`. Five provider folders synchronized with no account error; the separate every-minute Nexum database worker/poll crons are present and the `email`/`default` queues are empty. Browser/responsive review, deliberate Ticket-ingress behavior, production rollout, and the remaining delivery-safety checks stay pending under the replacement `HR-2026-09-01-001`; the provider-first `HR-2026-08-16-006` is superseded. |
| Email Account Provider Prerequisite UX (#256) | Superseded Before Commit | Codex | The uncommitted provider-prerequisite UI passed its focused tests but was rejected during production review because it made the provider-first workflow more prominent. It is superseded by the approved account-owned setup RFC amendment, ADR, and Feature Slice below. Its unrelated regression fixes remain separate contributor work. |
| Simple Email Account Connection Setup | Implemented On Dev / Dev Human Review In Progress / Production Review Pending | Codex | Approved RFC amendment `docs/rfc/2026-07-04-mail-module-full-email-client.md`, accepted ADR `docs/adr/2026-09-01-email-account-owned-imap-smtp-configuration.md`, and Feature Slice `docs/feature-slices/2026-09-01-email-account-connection-setup.md` define one Email account Add/Edit/Save-and-test flow. A one-way migration promotes exactly verified provider bindings, destroys duplicate provider ciphertext and disables the old routes/runtime; inert rows remain only as audit history. Authenticated Dev review on 2026-09-02 found and fixed stale debounced To/Subject submit validation, then proved one SMTP acceptance, one IMAP self-receipt, one provider Sent append, one reconciled outbound submission, and zero Ticket links. Blank-password preservation and activation were rechecked twice, and the complete Email feature suite passes 706 tests / 7,218 assertions. Wrong-password correction, permission, narrow-layout and production checks remain under `HR-2026-09-01-001`. |
| Recorded Baseline Regression Cleanup | Done On Dev | Codex | The eight failures retained by the 2026-08-26 broad Ticket Rules run are resolved without weakening provider-binding or Email live-authority guards. The combined Customer Portal, Email live publisher, Integration, and User-profile matrix passes 81 tests / 826 assertions on 2026-08-30. |
| Task Stopwatch And Time Registration (#232) | Done On Dev / Reviewed | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-08-25-task-stopwatch-and-time-registration.md`, ADR `docs/adr/2026-08-25-task-actual-time-and-ticket-billing-authority.md`, and Feature Slice `docs/feature-slices/2026-08-25-task-ticket-billing-minimum-and-time-authority.md` add one shared Ticket/Task stopwatch plus configurable Ticket-owned Task billing. Task time stays actual technician time; Ticket billing can follow actual time or use the Task estimate as one cumulative minimum. Linked billing provenance prevents worklog double counting. Migration `2026_08_25_220000_add_task_source_to_ticket_time_entries` ran on Dev in batch 3; Svein approved human review `HR-2026-08-25-012` on 2026-08-26, and GitHub Issue #232 is closed. |
| RMM Alert Rules (#226) | Done On Dev / Reviewed / Scheduler Operations Pending | Svein / Codex | Approved RFC `docs/rfc/2026-08-25-rmm-alert-rules.md`, accepted ADR, and three completed-on-Dev Feature Slices implement the provider-neutral pre-routing layer requested in the controlling Issue comment: new/reopened Tactical and N-able occurrences can create/update Ticket, create/reuse Task, reopen through Ticket Workflow, emit Signal, or be ignored. Immutable context, locked current targets/routing references, UUID processing leases, resolved N-able stale recovery, and terminal no-replay semantics prevent cross-customer or stale-worker actions. RMM Ticket notifications are suppressed; Ticket keys use a locked yearly sequence; downstream Signal webhooks use a unique non-null action key, durable claimed outbox, every-minute pending recovery, and unresolved no-blind-replay semantics. Verification on 2026-08-26: RMM 25 tests/226 assertions; Signal 39/208; Task 31/202; TicketModule 127/952; a live two-connection MariaDB sequence probe completed without deadlock and rolled back. Migrations `230000`, `000000`, `010000`, `020000`, `030000`, and `040000` Ran in Dev batches 5, 6, 7, 8, 9, and 10 with schema/cohort read-back. Laravel registers the dispatcher, but no external `schedule:run` runner for `/var/Projects/tdPSA` was found; the only verified every-minute Laravel systemd timer targets `/var/projects/project-society`. The latest broad Integration run passed 198 tests, skipped one environment-gated contract, and retains one unrelated OpenAI legacy-completion `max_tokens` expectation failure. Separate broad Ticket verification retains unrelated Ticket Rule compatibility and Portal provider-binding failures outside #226. `HR-2026-08-25-014` was reviewed by Svein on 2026-08-31. Production migration, rule activation, and the external scheduler remain separate operational gates; scheduler remediation is tracked in #262, and #226 is closed. Controlled RMM occurrence retry, recurrence windows, explicit RMM notifications/resolution actions, scripts/remediation, direct RMM webhooks, and AI remain future approved RFC/slice work. |
| Ticket Message Read API (#240) | Done On Dev / Reviewed | Svein / Codex | Feature Slice `docs/feature-slices/2026-08-25-ticket-message-read-api.md` adds the `tickets.read`-protected, paginated `GET /api/v1/tickets/{ticket}/messages` metadata endpoint. It exposes message type, visibility, author type, timestamp, and authoritative first-response verification only; bodies, subjects, raw metadata, attachments, author IDs, and customer content remain excluded. Focused regression coverage passed on Dev, and Svein reviewed `HR-2026-08-25-011` on 2026-08-31. OpenAPI/Knowledge deployment synchronization remains an operational handoff action; #240 is closed. |
| Ticket Rules Triggers, Ordered Actions, And Audited Execution (#231) | Done On Dev / Human Review Approved | Svein / Codex | Approved RFC `docs/rfc/2026-08-25-ticket-rules-triggers-actions-execution.md` and the accepted authority ADR govern all six implementation-complete Feature Slices. The final Issue #231 plus Workflow matrix passes 198 tests / 2,419 assertions; the complete Issue-only matrix passes 173 / 2,128; and an independent cross-module matrix passes 239 / 1,978. The broad repository run completed 2,452 passing tests / 23,683 assertions with ten initial failures: the Issue-related Email fixture was corrected and passes, the provider-health deadline passes isolated, and the eight remaining unrelated independently reproducing baseline failures are three Customer Portal notification/provider-binding tests, three Email live-publisher state-machine tests, one Integration `max_tokens` expectation, and one User-profile backfill count. Migrations 060000-120000 ran path-scoped in Dev batches 12-18; read-back confirmed actor/workflow grants, exact loop evidence, operator permissions, draft payload, and unique first-save identity. Compatibility preflight and the gated zero-row backfill completed with authority still `legacy` at generation 0. History/preview fail closed for restricted Custom Fields, retry defaults to three total attempts per action position, runtime/preview share exact loop reasons, and the legacy route boundary cannot mutate draft/schema-2 state. Every v2 trigger/action/Custom Field capability and full rerun remain off. Static checks pass for changed files, all new copy remains English, and no language files were added. The unauthenticated browser reached the site but redirected Ticket Rules to `/login`; Svein approved the complete human review on 2026-08-26. Release, Main promotion, production migration, deployment, and v2 runtime activation remain separate explicit decisions; all gates remain off. |
| Knowledge And BookStack Revision-Safe Synchronization (#276) | Done On Dev / Human Review Pending | Svein / Codex | Immutable revision identity, honest unknown baselines, three-way inbound decisions, visible candidates, exact published outbound revision binding/read-back, stale-job safety, idempotent ambiguous-create recovery, remote deletion preservation, disabled-state preservation, loop prevention, and sanitized errors are implemented. Automatic clean inbound remains off by default. Final Dev migration/test evidence is recorded in `HR-2026-09-03-003`; Issue #276 is closed as completed, while Main/production and policy activation remain separate gates. |
| Knowledge Revision Approval And Publication (#266) | Done On Dev / Human Review Pending | Svein / Codex | Immutable manual/API/AI/import proposals, concise diff and complete preview, exact Ticket-scoped approval, stale conflict, least-privilege system publication, local/provider read-back, retry without duplicate publication, rollback-as-new-revision, repository authority, portal timing, and independent documentation completion are implemented. Migration `2026_09_04_080000_add_knowledge_revision_workflow` ran in Dev batch 4 with exact baseline and actor read-back. The focused matrix passes 58 tests / 526 assertions, routes and Blade compile, and relevant PHP passes Pint. Issue #266 may close as implemented; manual UI and production rollout remain in `HR-2026-09-04-001`. |
| BookStack Cross-Process Rate-Limit Coordination (#196) | Done On Dev / Verified | Svein / Codex | Cross-process pacing, shared 429 cooldown, provider retry headers, guarded error timestamps, and large Knowledge article storage are complete on Dev. Approved migration `2026_08_25_210000_expand_knowledge_article_body_capacity` changes `body_markdown` and nullable `body_html` to `MEDIUMTEXT` with a rollback preflight that refuses destructive shrinking. It ran in Dev batch 2 with schema read-back. Focused verification passes 25 tests / 190 assertions. The final configured BookStack pull completed in 390.96 seconds: 3 created, 373 skipped, 0 failed, 0 rate-limited, 376 total, 0 duplicate source identities, healthy status, and no Last Error. Human review `HR-2026-08-25-005` is Reviewed; Issue #196 is closed. Production later requires a database backup, stopped BookStack/default workers during migration, schema read-back, cache clear, worker restart, and one controlled pull. |
| Mail Production Placement Schema Repair | Done On Dev / Production Migration Pending | Svein / Codex | Production had recorded `2026_08_16_118000_add_email_provider_reconciliation` as Ran while its four placement-observation columns, foreign key, indexes, and guards were absent. New forward-only migration `2026_08_25_100000_repair_email_provider_reconciliation_placement_schema.php` repairs partial MariaDB/MySQL/SQLite states idempotently and ran on Dev in batch 132. The regression reproduces the recorded-migration/schema-drift state and passes. Production still requires backup, stopped Email/default/notification workers, migration, schema read-back, worker restart, provider reconciliation, and proof that the 241 already-stored post-18-August messages receive active Inbox placements. Track under `HR-2026-08-16-007`; do not run the historical migration again or create placements with an ad-hoc SQL backfill. |
| Scheduled Ticket SLA Management | Done / Reviewed | Junie | All Slices (One-time, Recurring, Calendar Linkage, and Automation) are implemented, tested, and verified. Human review `HR-2026-08-25-001` was approved by Svein on 2026-08-25. |
| Commercial Contract Customer Document And Pricing Consistency | Done / Production Readiness And Human Review Pending | Svein / Codex | Implemented on authoritative Dev under the approved RFC/ADR and completed Feature Slices. The boundary uses Brick Math/minor units, exact CloudFactory Contract-bound decimals, one Norwegian six-column projection, immutable non-null evidence with full semantic v1 validation, preview-safe Livewire validation, and term metadata v2. Historical null-snapshot customer paths fail closed; only a named, original-evidence attestation with stable fingerprint and explicit document type can freeze the reconstruction, and it never replaces non-null evidence. Ambiguous approved/won rows show no reconstruction before type selection. API/portal lists isolate unavailable rows, missing tokens do not create public/resend access, and accepted CloudFactory line reconciliation locks the parent Contract first. Migrations `160000` and `180000` ran in Dev batches 130 and 131 with bounded backfill, NOK preflight, and protected rollbacks. Production remains blocked until authoritative supplier/customer identity and the one legacy won evidence disposition are supplied, production rate visibility is classified, and the exact EDR Service/cadence is identified. Never guess or automatically backfill these facts. Final authoritative scoped Dev verification passes 99 tests / 1,455 assertions: `ContractFinalReviewRegressionTest` 44 / 868, `ContractCustomerDocumentTest` 6 / 104, `CommercialModuleTest` 33 / 313, `CustomerPortalQuoteContractAcceptanceTest` 2 / 60, two Contract-focused `CustomerPortalCommercialEconomyTest` methods 2 / 26, `ContractPricingTest` 8 / 31, and four CloudFactory Contract-boundary tests 4 / 53. Final PDF evidence and all still-open human checks are recorded in `HR-2026-08-24-003`. |
| Default Permission And Role Seeder Reconciliation | Done | Junie | The 2026-08-21 Mail provider deployment repair proved that a full `RoleSeeder` sync would remove 17 current Admin and 4 current Tech grants owned by Calendar/Sales defaults. Reconciled in HR-2026-08-25-002. |
| Email Pre-Existing Unreferenced Private-Storage Inventory | Ready | Operations / Svein | The latest 2026-08-24 12:47 CEST read-only inventory inspected 1,445 Dev files without mutation: 968 referenced and 477 unreferenced. It reports 28 missing `message_raw` references, 79 non-private `0644` files, 15 duplicate unreferenced checksum+size groups, and zero unsafe or unreadable files. Duplicate groups and unreferenced status do not authorize deletion; investigate send reconciliation, missing raw evidence, legacy sources/account-2 copies, provenance, retention/Ticket/legal-hold/backup relevance, and age before any separately approved mutation. No file was deleted, moved, copied, or rewritten during recovery or inventory. |
| Mail Scheduled All-Account Polling And Folder-State Recovery | In Progress / Targeted Runtime Active | Svein / Codex | The Dev crontab runs one overlap-safe `email:poll --scheduled` dispatch and one database `email,default` worker every minute; the active account-owned mailbox has completed repeated polling without the former folder-handle errors. The shared Laravel scheduler is not running, so scheduled remote-mail reconciliation, health, remote-operation recovery, and notification dispatch are not automatically active. Do not enable the full scheduler until its notification backlog and all other due jobs receive explicit operator review. The exact historical failed IMAP job remains preserved under `HR-2026-08-15-003` without blind retry/deletion. |
| Retired Integration-Owned Email Provider Lifecycle | Superseded / No Active Work | Svein / Codex | The historical ADR and Feature Slice are retained only to explain migrations and old evidence. `docs/adr/2026-09-01-email-account-owned-imap-smtp-configuration.md` and `docs/feature-slices/2026-09-01-email-account-connection-setup.md` supersede their credential ownership, UI, staging, migration, cutover and runtime design. Dev has one active account-owned mailbox, no provider binding, no provider ciphertext, no normal provider lifecycle routes and no Integrations card. Do not resume `HR-2026-08-16-006`; all current account checks belong to `HR-2026-09-01-001`. |
| Mail Full Provider-Originated Reconciliation | Done / Runtime Operations In Review | Svein / Codex | Implemented `docs/feature-slices/2026-08-16-email-mail-provider-originated-reconciliation.md`: bounded resumable read-only all-folder/UID/flag/import cycles, hidden-and-attested message birth, stable negative evidence, mailbox-local NOMODSEQ verification, exact move/copy/operation reconciliation, personal-state-safe confirmed moves, deferred live-Inbox automation, durable bounded notification fan-out and external-delivery evidence, intent/lease-safe cancellation, bounded summaries/recovery, optional IDLE hints, and paged scheduled all-account correctness. The final focused SQLite bundle passes 255 / 2,225, the standalone durability gate passes 34 / 468, rolling unread-schema compatibility passes 4 / 26, the existing private MariaDB guard/path/Integration matrix passes 3 / 434, and the final MariaDB `118500` contract passes 3 / 163. The clean 2026-08-24 complete Email Feature directory passes 686 / 7,046, including the 500-folder boundary and truthful child-progress regressions, without weakening production guards. Controlled account-2 run 2 finished terminal `stale` in `summary`: 137 folders, 131 complete and 6 stale with `provider_tuple_drift`; 8,427 observations; 7 confirmed missing; and 0 moves, conflicts or errors. Provider-wins local projection hid seven placements and soft-deleted caches where no active placement survived. Three pending observations belong only to the six stale folders. No provider write or notification delivery occurred. The exact 20 Order-1-through-7 migrations `100000` through `118500` ran one per step in Dev batches 98 through 117. The inbound Ticket-message repair cursor completed through ID 363 in four pages. Both the historical IMAP failure and run 1's understood 100-folder-cap reconciliation failure remain preserved without retry/deletion. Browser/full-scheduler/notification-worker smoke remains operator-gated; Svein's 2026-08-19 review history is preserved, and `HR-2026-08-16-007` was reopened on 2026-08-24 for the folder-cap/progress/runtime changes and current checks. This is not a complete repository-suite claim. |
| Mail Notification Backlog And Worker Activation | Blocked / Human Delivery Decision Required | Svein / Operations | Dev has 136 unreserved `ProcessInboundEmailNotificationFanout` jobs, all with zero attempts, and no `notifications` database worker. Existing settings enable Web Push for both relevant notification types, so starting the worker or full scheduler could deliver a historical burst. No preference was changed and no notification was delivered during Mail completion. Review the bounded cohort and explicitly choose delivery, suppression, or another approved disposition before enabling that worker. |
| Dev Recovery Incident August 15–21 Evidence Reconciliation | Ready / Human Disposition Open | Svein | The 2026-08-21 Dev recovery restored the complete August 15 backup and applied forward migrations. Any Dev-only changes from August 15 through the accidental reset remain a possible loss window until a named human compares external/provider/project evidence and records the disposition in `HR-2026-08-21-001`. Runtime-resume approval does not close this evidence review. |
| Mail Private Live Invalidation And Polling Fallback | Implemented On Dev / Runtime Disabled / Human Review Pending | Svein / Codex | Order 8 now has coordinated account/user/role authority generations, resumable two-phase access recompute in pages of at most 100, one-time scheduled delegation/break-glass boundary invalidation, restored null-safe database writer guards, minute recovery, and a bounded current-view projection. Focused authority plus delegation/break-glass coverage passes 19 / 225. Runtime flags stay false. Migration `2026_09_01_100000` ran on Dev in batch 22. Complete real-provider Reverb/proxy/worker/scheduler/socket-loss/two-user browser review under `HR-2026-08-16-008`; production remains untouched. |
| Mail Presence, Shared Draft Fencing And Stale Composer | Backend And Accessible UI Implemented / Runtime Disabled / Human Review Pending | Svein / Codex | Order 9 now includes the accessible Mail composer controls over its default-off cache presence and explicit shared-draft backend: Share draft, current holder/expiry/read-only wording, one 60-second lease, release/expiry takeover, fenced save/attachments/discard/send and exact Ticket-selected peer reuse. The legacy SQL-lock/whisper path remains removed. Focused coverage passes 10 / 134; Ticket shared integration passes 7 / 50. `EMAIL_LIVE_ENABLED`, collaboration and UI gates remain false. Complete Order 8 transport plus Redis/Reverb-loss and desktop/mobile two-user browser review `HR-2026-08-16-009` before activation. |
| Mail Conversation Acknowledgement And Explicit Selected Accounts | Advanced API Implemented / Runtime Disabled / Human Review Pending | Svein / Codex | The advanced conversation-wide and exact multi-account preview/apply workflow remains default-off API/administrative safety infrastructure. It is no longer exposed in the ordinary Mail reader. The reader instead has one direct selected-message Mark as read/unread action: shared/system mail changes only the actor's personal state, while a personal mailbox owner also mirrors provider Seen/Unseen. Frozen evidence and bounded continuation remain available for the advanced workflow. Migration `2026_08_24_140000` remains deployed; `HR-2026-08-16-012` tracks its separate activation gate and the simplified reader review. |
| Mail Desktop Workspace Density And Height Polish | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-15-email-mail-desktop-workspace-density-height-polish.md`: the Smart Inbox button is again above the reader while its single controlled result region remains after the complete conversation; center-list parent/child rows show only the signed-in technician's personal Unread badge; desktop rows are denser; and equal-height list/reader panes consume the available viewport before independent scrolling. The stacked layout below 1200 px is unchanged. Focused coverage passes 20 / 337, `EmailModuleTest` plus supervised cleanup passes 153 / 1,408, and the complete Email directory passes 349 / 3,066. Human review `HR-2026-08-15-007` is Pending. |
| Mail Inbound Attachment Recovery And Download | Implemented On Current Dev / Human Review Pending | Svein / Codex | The bounded placement-bound download and recovery code is complete and its focused regression passes 15 tests / 110 assertions. A 2026-09-02 current-Dev readback found message IDs 1-20, none of the historical recovery targets 456/478/479, and zero current messages whose stored attachment counter exceeds actual attachment rows. The obsolete historical target is retired without guessed copies or deletion; any future discrepancy requires a new exact preflight. Controlled browser/access review remains open under `HR-2026-08-15-006`. |
| Mail Smart Inbox Reader-First Review Polish | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-15-email-mail-smart-inbox-reader-first-polish.md`: useful results remain after the selected mail body and start closed, while the follow-up desktop-polish slice restores one accessible trigger above the reader without duplicating eligibility/query ownership; terminal and currently unavailable pending actions disappear while applied history and durable audit remain; recorded-agent/user/mailbox/target eligibility is checked for presentation and again at action time; and schema-v2 fingerprints ignore unrelated bookkeeping timestamps while legacy rows use their recorded schema. Human reviews `HR-2026-08-15-005` and `HR-2026-08-15-007` are Pending. |
| Email Private Storage Cross-User Permissions | In Progress | Operations / Svein / Codex | `EmailPrivateStorage` limits writes to normalized `email/*` paths; the latest 2026-08-24 12:47 CEST read-only Dev snapshot has 1,445 files, 968 referenced and 477 unreferenced. The structural directory/ACL contract remains intact, but 79 `www-data`-owned legacy files are still non-private `0644` and the SSH project user cannot chmod them. A root/operator must change only those 79 modes to `0660` without content, ownership, move, or deletion, then rerun inventory and PHP-FPM/queue dual-runtime smoke. Investigate 28 missing raw references and 15 duplicate groups separately; zero unsafe/unreadable files were found and no deletion is authorized. Keep `HR-2026-08-15-003` Pending and preserve the exact historical failed job. |
| Mail Runtime Reliability Hardening | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-15-email-mail-runtime-reliability-hardening.md`: deterministic special-folder targets, exact UID preflight, truthful pre/post-SMTP evidence, fail-closed Sent/Draft handling, verified private writes, bounded Draft refresh, and honest UI state. Controlled Dev repair cancelled operation `23`, hid stale placement `474`, projected verified Trash UID `30177` as placement `485`, repaired the child role, and reconciled draft `1` without SMTP or MOVE writes. Polling now repeatedly succeeds for the active account-owned mailbox. The recorded Email, Unit, Notification and Ticket regressions passed at completion. The latest 1,445-file inventory still reports 79 non-private files, so root/operator mode-only normalization plus dual-runtime smoke remains under `HR-2026-08-15-003`. |
| Mail Folder Hierarchy And Subject Readability | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-15-email-mail-folder-hierarchy-subject-readability.md`: normal Mail navigation now shows each authorized provider folder as an account-isolated expandable parent/child tree, defaults branches closed, remembers each technician's explicit open/close state in Email-owned folder preference rows, keeps non-selectable containers structural, selects exact physical folders, labels folder-local mailbox unread counts, and renders common or truncated RFC 2047 subjects readably throughout Mail and Reply/Forward presentation without rewriting identity-bearing stored data. Human review `HR-2026-08-15-002` is pending. |
| Mail Decoded Subject Search Compatibility | Done | Svein / Codex | The decoded `subject_search` projection/search surfaces remain intact and the discovered MariaDB receipt-timestamp defect is repaired. Migrations `121000`, `121100`, and `121200` ran after recovery in Dev batches 95, 96, and 97. The final migration removed implicit `ON UPDATE CURRENT_TIMESTAMP` and froze 490 audit candidates; preview/apply restored the 471 evidence-supported timestamps (439 header dates, 32 conversation boundaries), left 19 unresolved candidates untouched, and recovered exactly five falsely stale Smart Inbox suggestions. The schema reports empty `EXTRA`; receipt-repair plus adjacent regressions pass 36 / 408. Manual search, timestamp-candidate, and rollout checks remain Pending under `HR-2026-08-15-004`. |
| Mail Selected Conversation List Expansion | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-15-email-mail-selected-conversation-list-expansion.md`: selecting a conversation now opens one indented, newest-first list of its authorized exact mailbox placements beneath the parent row; child clicks synchronize with the threaded reader, filter-scoped and full-conversation counts remain explicit, and unselected rows add no child query. Human review `HR-2026-08-15-001` is pending. |
| Mail Provider Deletion Reconciliation | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-provider-deletion-reconciliation.md`: stable bounded inventories, fail-closed UID/cursor evidence, seven-day hidden tombstones, conservative move/reappearance handling, retention/Ticket protections, idempotent Mail/Smart Inbox cleanup, daily dispatch, and an exact Admin opt-in that remains off by default. Migration `115000` ran in batch 93. Human review `HR-2026-08-14-015` is pending and the setting must remain off until controlled review. |
| Mail Supervised Smart Inbox Cleanup | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-supervised-smart-inbox-cleanup.md`: explicit reversible Archive/Move suggestions through the normal remote ledger and verified Undo, exact source placement/UID/version checks, cleanup-only max-50 batches with one reservation per source and per-item reauthorization/results, unchanged Seen/personal unread, honest provider status, no-write personal prefill, one-use opaque inactive Admin prefill, and distinct provider rule actions that preserve legacy local `archive`. Human review `HR-2026-08-14-014` is pending. |
| Mail Reviewed Smart Inbox Actions | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-ai-reviewed-conversation-actions.md`: explicit human application is limited to existing active Email category, existing active tag, or an editable internal Task through guarded domain actions; exact source/agent/scope authority is rechecked and applied references/events are idempotent. Human review `HR-2026-08-14-013` is pending. |
| Mail Durable Smart Inbox Suggestions | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-smart-inbox-suggestion-foundation.md`: user/account/conversation/source-bound typed suggestions, append-only events, explicit manual analysis, post-provider authorization recheck, safe provider errors, terminal-state access revocation, preserved audit references, a selected-conversation Livewire review queue, and scoped queue/count/show/analyze/dismiss/correct API. Migration `114000` ran in batch 92. Human review `HR-2026-08-14-012` is pending. |
| Mail Verified Remote Operation Undo | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-verified-remote-operation-undo.md`: immutable exact result snapshots, unique source/inverse linkage, 15-minute recent window, execution-time local/provider verification, no-write stale/revoked/ambiguous blocking, normal ledger/recovery for every inverse, shared UI/API actions, and hidden-404 account scope. Permanent delete, bulk undo, and folder mutation undo remain excluded. Human review `HR-2026-08-14-011` is pending. |
| Mail Fail-Safe Retention Purge | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-fail-safe-retention-purge.md`: one eligibility service now protects provider placements, unresolved operations/reconciliation, Ticket evidence, recognized holds, and unsupported storage; the scheduled orphan purge records sanitized durable run/attempt evidence, and Email Admin shows a read-only cutoff/count/reason preview. Full legal-hold/DSAR/offboarding remains separately scoped lifecycle work; provider-deletion inventory confirmation is now completed under its default-off reconciliation slice and `HR-2026-08-14-015`. Human review `HR-2026-08-14-009` is pending. |
| Mail Remote Operation Recovery | Done | Svein / Codex | Implemented under `docs/feature-slices/2026-08-14-email-mail-remote-operation-recovery.md`: immutable sanitized provider-attempt evidence, execution-time requester authorization and stale identity guards, ambiguous-outcome reconciliation without blind replay, authoritative target-folder evidence for moves, fail-closed provider inventory errors, mutation-only attempt budgets, bounded scheduled retries, shared race-safe retry/cancel actions, scoped recovery API, richer Mail dashboard evidence, and durable conversation unread refresh. Verified inverse/undo is completed in its follow-up slice. Human review is `HR-2026-08-14-010`. |
| Mail Conversation Identity Hardening | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-conversation-identity-hardening.md`: nested RFC replies stay together, reused identifiers fail closed, only unambiguous existing projections reconcile, and Smart Inbox suggestion/event references preserve old audit shells. Migration `105000` ran in batch 86; 462 placements remain linked across 139 conversations after two safe moves. Focused coverage passes 7 tests / 50 assertions. Human review `HR-2026-08-14-007` is pending. |
| Mail Conversation Taxonomy Classification | Done | Svein / Codex | Implemented `docs/feature-slices/2026-08-14-email-mail-conversation-taxonomy-classification.md`: one account-scoped durable conversation owns Email category/tags while legacy message routing tags, provider state, Ticket classification, cross-account isolation, and ambiguous migration evidence remain intact. Migration `110000` ran in batch 87; focused coverage passes 11 / 90 and Inbound Automation 14 / 81. Human review `HR-2026-08-14-008` is pending. |
| Mail Composer Local Status Polish | Done | Svein / Codex | Implemented under `docs/feature-slices/2026-08-14-email-mail-composer-local-status-polish.md`: AI apply/no-reply/unavailable results and open-composer draft save/restore/provider sync/attachment messages now render inside the shared composer while send/discard and non-composer actions keep page-level Mail feedback. |
| Mail Durable Account Conversations | Done | Svein / Codex | Implemented under `docs/feature-slices/2026-08-14-email-mail-durable-account-conversations.md`: Email now persists account-scoped conversation rows, backfills mailbox placements and Ticket conversation links, projects conversations on inbound storage and provider moves, and keeps the existing `/tech/mail` conversation UI behavior. The conversation-scoped Taxonomy follow-up is now complete under `docs/feature-slices/2026-08-14-email-mail-conversation-taxonomy-classification.md`; later extensions and restricted automatic external replies remain separate dependency-gated work whose implementation approval is recorded in the 2026-08-16 completion index. |
| Mail Composer AI Consistency | Done | Svein / Codex | Implemented under `docs/feature-slices/2026-08-14-email-mail-composer-ai-consistency.md`: the shared `/tech/mail` composer now exposes AI rewrite controls consistently for Compose, Reply, Reply All, and Forward when policy allows it; Draft reply remains Reply/Reply All only, and Forward preserves the original forwarded-message block. |
| Mail Conversation Reader Polish | Done | Svein / Codex | Implemented under `docs/feature-slices/2026-08-14-email-mail-conversation-reader-polish.md`: `/tech/mail` renders the current account-scoped conversation as a compact reader thread, expands only the selected placement, and keeps command-bar actions scoped to that selected provider placement. The durable conversation projection, identity hardening, database-backed list pagination, account-isolated reader loading, and conversation Taxonomy follow-ups are now complete. |
| Beta completion | In Progress | Svein / Codex | Finish and harden existing modules before starting large new domains. |
| Mail Full Client, Personal Mailboxes, Rules And AI | In Progress | Svein / Codex | Svein approved Level 3 RFC `docs/rfc/2026-07-04-mail-module-full-email-client.md` and accepted its four 2026-08-11 Email ADRs on 2026-08-12. Feature Slice 1 `docs/feature-slices/2026-08-12-email-mailbox-access-foundation.md` is implemented with mailbox kinds, owner isolation, explicit grants, scoped Inbox UI/API, notification filtering, account-scoped rules, and personal no-Ticket-ingress. Feature Slice 2 `docs/feature-slices/2026-08-12-email-server-authoritative-folders-placements.md` adds provider folders, mailbox placements, folder baselines, safe multi-folder polling, Inbox-only legacy automation, and an idempotent remote-operation ledger. Admin config cleanup `docs/feature-slices/2026-08-12-email-admin-sync-cache-settings-clarity.md` makes sync/cache settings clear, keeps normal IMAP mail on the provider by default, and moves gateway-authentication trust into Advanced Automation Trust. Rule/API foundation `docs/feature-slices/2026-08-12-email-deterministic-rule-versions-api-foundation.md` adds published rule snapshots, idempotent execution attempts, and read-only rule list/show/preview API. Livewire Mail workspace and personal state `docs/feature-slices/2026-08-12-email-livewire-mail-workspace-personal-state.md` adds `/tech/mail`, account/folder/search/reading panes, explicit `Unread for me`, opened receipts, and personal read/unread actions while leaving legacy `/tech/inbox` intact. Provider mailbox actions and API `docs/feature-slices/2026-08-12-email-provider-mailbox-actions-api.md` adds explicit IMAP Seen/Unseen, Flag/Unflag, Archive, and Trash actions with shared UI/API authorization and remote-operation acknowledgement. Mail reply composer `docs/feature-slices/2026-08-12-email-mail-reply-compose-attachments.md` adds Reply from `/tech/mail` with To, Cc, attachments, threading headers, mailbox Send authorization, and idempotent outbound Email logs. Mail forward and rich HTML composer `docs/feature-slices/2026-08-12-email-mail-forward-rich-html-composer.md` adds Forward, shared rich Reply/Forward editor controls, HTML source mode, sanitized outbound HTML, and Forward logs without auto-reattaching original inbound attachments. Mail command bar triage `docs/feature-slices/2026-08-12-email-mail-command-bar-triage-actions.md` makes Mark read personal-only in the main bar, moves provider read/flag/archive to More, and adds compact Spam/Ticket/Trash icon actions backed by existing Email/Ticket actions. Mail taxonomy classification `docs/feature-slices/2026-08-12-email-mail-taxonomy-classification.md` keeps provider flagging separate and adds visible flag styling plus Email-owned category and multi-tag assignment using existing Taxonomy definitions. Mail Reply All/new compose `docs/feature-slices/2026-08-12-email-mail-reply-all-new-compose.md` adds Reply All recipient defaults/threading and a new-message composer using send-authorized accounts without broadening mailbox read access. Mail Move-to-folder `docs/feature-slices/2026-08-12-email-mail-move-to-folder.md` adds arbitrary same-account selectable-folder moves through the provider operation ledger and API. Personal simple rules `docs/feature-slices/2026-08-12-email-mail-personal-simple-rules.md` adds per-message rule history, owner-scoped safe personal rule creation, personal rule execution for personal no-Ticket-ingress mail, and Admin builder redirects for shared/system rule managers. Mail AI summary `docs/feature-slices/2026-08-12-email-mail-ai-summary.md` adds a read-only governed AI summary/action-extraction panel from `/tech/mail` through the selected Email agent or global fallback agent, excluding raw source, HTML, attachment content, attachment filenames, and all write actions. Mail AI reply drafting `docs/feature-slices/2026-08-12-email-mail-ai-reply-drafting.md` adds governed Reply/Reply All composer drafting, improve, shorten, warmer tone, and Norwegian rewrite controls where sendable output replaces only the composer body, no-reply recommendations stay advisory, and sending remains manual; it also expands `common_settings.value` to long text so Email settings can save full MIME/trust/AI settings, lets admins choose the Default Email agent directly, clears the legacy structured workload override on save, and otherwise falls back to the global default agent for manual non-writing Mail AI. Integration standard AI activation `docs/feature-slices/2026-08-12-integration-standard-ai-activation.md` adds a compact AI Settings activation path that records installation/provider/model governance for normal user-triggered Mail AI after admin confirmation, while keeping advanced Privacy & Coordinator governance available. Mail signatures `docs/feature-slices/2026-08-13-email-mail-signatures.md` adds Mail-owned technician signatures on profile, keeps the page AI chat first in the Mail rightbar, and shows the signature plus Mail AI runtime cards collapsed below it, appends them in the send pipeline, and keeps AI drafting scoped to the message body. Mail local drafts `docs/feature-slices/2026-08-13-email-mail-drafts-autosave.md` adds user-scoped Nexum drafts/autosave for Compose, Reply, Reply All, and Forward, marks sent/discarded drafts, and provides the local lifecycle used by provider Drafts sync. Mail Sent reconciliation foundation `docs/feature-slices/2026-08-13-email-mail-sent-reconciliation-foundation.md` records pending provider Sent reconciliation after SMTP success and marks matching same-account Sent-folder imports reconciled by normalized `Message-ID`. Mail provider Drafts visibility `docs/feature-slices/2026-08-13-email-mail-provider-drafts-visibility.md` shows imported provider Drafts-folder placements with a Drafts view/filter and draft badges while hiding ordinary Reply/Forward/Ticket/rule actions for those provider drafts. Mail provider Drafts write sync `docs/feature-slices/2026-08-13-email-mail-provider-drafts-write-sync.md` records provider Drafts sync evidence on local composer drafts, appends manual Save draft content to the real provider Drafts folder, keeps autosave local-only, and best-effort deletes provider copies after Send or Discard. Mail durable draft attachments `docs/feature-slices/2026-08-13-email-mail-durable-draft-attachments.md` stores local draft attachments durably, restores them in the composer, includes them in SMTP sends and provider Drafts append, and cleans them up after Send or Discard. Mail provider folder create `docs/feature-slices/2026-08-13-email-mail-provider-folder-create.md` lets organize-authorized technicians create a custom provider folder from the Mail sidebar after selecting one mailbox, then projects the IMAP-created folder locally. Mail provider folder rename/delete `docs/feature-slices/2026-08-13-email-mail-provider-folder-rename-delete.md` adds a Folders-header gear manager for the selected organize-authorized mailbox, mirrors custom folder rename/delete to IMAP through the remote-operation ledger, and requires mail to be moved before folder delete. Direct provider Drafts placement editing `docs/feature-slices/2026-08-13-email-mail-provider-drafts-direct-editing.md`, provider Sent append/deduplication support `docs/feature-slices/2026-08-13-email-mail-provider-sent-append-support.md`, grouped shared rule builder/reprocessing `docs/feature-slices/2026-08-13-email-mail-grouped-rules-reprocessing.md`, multi-conversation Ticket links `docs/feature-slices/2026-08-13-email-mail-ticket-conversation-links.md`, remote operation retry dashboard `docs/feature-slices/2026-08-13-email-mail-remote-operation-retry-dashboard.md`, first write-gated AI assistant `docs/feature-slices/2026-08-13-email-mail-ai-write-gated-assistants.md`, manual Mail send/receive plus folder refresh `docs/feature-slices/2026-08-14-email-mail-manual-send-receive-refresh.md`, and conversation list grouping `docs/feature-slices/2026-08-14-email-mail-conversation-list-grouping.md` are implemented. Durable conversation identity/classification, recovery and verified Undo, fail-safe retention, the Smart Inbox foundation/review queue, reviewed category/tag/Task actions, supervised reversible cleanup, default-off provider-deletion reconciliation, and selected-conversation list expansion are now implemented under their approved Feature Slices and ready for pending human review. All 51 Mail-parent Feature Slices that existed before the 2026-08-16 completion audit are implemented; deferred and later target capabilities had not yet been represented as slices and are now ordered in `docs/plans/2026-08-16-email-mail-completion-slice-index.md`. The complete Email module regression passes 141 tests / 1,227 assertions; focused conversation-query coverage passes 6 / 81. Restricted automatic external replies remain unimplemented, but Svein's explicit 2026-08-16 instruction to take every remaining slice, including work not previously approved, supplies the separate product approval. The capability remains default-off and cannot be enabled until its slice, architecture/security gates, tests, operational checks, and named human review pass. |
| Sales Quotes / CPQ Completion | Done | Svein / Codex | GitHub Discussion #170 Sales-owned completion is implemented on Dev under approved RFC `docs/rfc/2026-08-11-sales-cpq-completion.md`, ADR `docs/adr/2026-08-11-sales-cpq-accepted-snapshot-boundary.md`, and feature slice `docs/feature-slices/2026-08-11-sales-cpq-core.md`. Delivered scope includes customer-selectable option groups, required/recommended/default selections, quantity bounds, quote/line acknowledgements, immutable accepted snapshots, configurable approval policy and Admin settings, reusable quote templates/bundles, sent-quote superseding when scope changes before acceptance, separate additional Ticket quotes for quote-required scope added after acceptance, permissioned accepted-quote voiding with safe reversal, Ticket `Add cost/item` routing to planned scope for quote-required Storage items or threshold-triggered costs, accepted Ticket quote auto-processing to safe reservations, draft purchase needs, or pending Ticket costs, public and Customer Portal accept/decline/expiry/history behavior, lifecycle activities, conversion-plan status/reference tracking, Sales, Ticket, and Storage Knowledge updates, Dev migrations `[67]`, `[68]`, and `[69]`, Sales tests `25 / 418`, Ticket tests `122 / 903`, Ticket Workflow tests `25 / 278`, and Storage tests `23 / 363`. Human review is `HR-2026-08-11-004`. Sales conversion plans for non-Ticket downstream domains remain owner-controlled and do not silently mutate Economy, Commercial, Asset, Task, ServiceVisit, or future Project records. |
| Simplified And Automatic Storage Supplier Order AI | Ready for Review | Svein / Codex | The approved original RFC/ADR plus operational RFC `docs/rfc/2026-08-11-operational-supplier-order-automation-setup.md` and bootstrap RFC/slice `docs/rfc/2026-08-11-automatic-ai-supplier-profile-bootstrap.md` / `docs/feature-slices/2026-08-11-storage-automatic-ai-profile-bootstrap.md` remove manual workload/user setup and technical controls from the ordinary form. Administrators choose order handling, warehouse, Supplier/Item behavior, AI use, one Storage agent, business limits, and notifications. A trusted valid email without a profile can now use a Storage-owned candidate contract to create one active Supplier, protected reusable profile, active/orderable Items, and an editable ordered Purchase Order; retries and close first messages reuse identities, and only Receiving changes stock. Dev policy revision 11 is automatic profile-or-AI with fallback agent 5 on standard `gpt-5.5`, warehouse 2, one-sample verified activation, active Supplier/Item creation, max 250 new Items, and green readiness. A rolled-back real-provider bootstrap passed in about 21 seconds with zero receipt/inventory deltas. Focused verification passes 94 / 971; the full Storage suite passes 257 / 2,775 with one expected skipped opt-in MariaDB contract. Human review `HR-2026-08-10-003` remains In Review. |
| Storage Incoming Purchase Quantity Visibility | Done | Svein / Codex | Approved Level 2 RFC `docs/rfc/2026-08-10-storage-incoming-purchase-quantity-visibility.md` adds one sortable Inventory **Incoming** quantity derived from positive outstanding lines on active ordered/partially received Purchase Orders. The row shows **On order** while incoming quantity is positive; drafts, received/closed/cancelled/deleted orders and received/cancelled line quantities do not inflate it. Storage-only users see the aggregate without gaining Purchase Order identity or actions. The reported item `IMP-18-1324745-5BA90BDA` now projects 1 incoming from `AUTO-2026-00000011`. Focused Storage verification passes 23 tests / 360 assertions; the full Storage suite passes 251 tests / 2,600 assertions with one expected skipped MariaDB contract. No migration, API, permission, queue, scheduler, or frontend build change is required. Human review `HR-2026-08-10-002` remains pending. |
| Storage Unified Supplier Orders List | Done | Svein / Codex | Approved Level 2 RFC `docs/rfc/2026-08-10-storage-unified-supplier-order-list.md` consolidates Supplier Order Imports, Purchase Orders, and Receiving into one permission-aware operational list without changing data, APIs, permissions, receipt posting, or inventory effects. Manual and email-created orders now share the same canonical detail; an authorized email order adds only a sanitized Email Copy card at the bottom after Shipments and Receipt History, while item lines, shipments, tracking, receipts, and actions remain identical. Trusted Authentication, SPF, DKIM, DMARC, and alignment remain internal and are not displayed on either detail page. Focused import UI verification passes with 7 tests / 130 assertions; the full Storage suite passes with 250 tests / 2,588 assertions and one expected skipped MariaDB contract. Knowledge is updated and human review `HR-2026-08-10-001` remains pending. |
| Storage Supplier Email Purchase Order Automation | In Review | Svein / Codex | The approved Level 3 RFC, ADR, nine Feature Slices, migrations `100000`-`113000`, seeders, 17 Nexum Knowledge articles, isolated `supplier-orders` runtime, and locked inbound Email poller/worker are deployed on Dev. Exact inline-forward routing, Email rule 10, Signal rule 2, Itegra profile row 1/version row 7 (version 4), warehouse 2, and policy revision 3 remain active only in `shadow`. The first real forward produced EmailMessage 51, Signal 10, and import 2 with deterministic `shadow_complete`: one unresolved line and no Item, PO, receipt, Movement, or stock write. The original poll missed UID 1447 and stored no authentication headers; both defects are fixed with ordered raw-header parsing plus a persistent forward-only `UIDVALIDITY`/UID baseline that ignores historical unread mail and drains new bursts oldest-first. An intermediate unread-catch-up safety test unintentionally imported 240 historical messages, creating 179 Tickets, 42 Signals, 18 rule logs, and one database-only assignment notification; it sent no outbound email and produced no failed jobs. Polling was contained, corrected, live-verified, and re-enabled, but these accidental rows remain untouched pending explicit cleanup authorization. A bounded mailbox calibration on 2026-08-07 added five inactive draft profiles with six protected real fixtures for Dustin, iFixit, MyTrendyPhone, Ecoengros, and IPC-Computer. It also added a validated but inactive Itegra version 5 candidate with four real fixtures while active version 4 remained unchanged. Nine calibration imports retained 12 reviewed lines in `needs_attention` / `validate`; the guarded transaction created no Vendor, Item, PO, shipment, receipt, Stock Unit, Movement, on-hand, Email rule, Signal, Ticket, queue, or failed-job side effect. NDI is PDF-only, 3DJake lacks normalized line prices, and the available Allnet sample lacks order lines, so no passing profiles were created for those formats. A second ordinary-poll capture, broader Itegra coverage for multi-line orders, quantity above one, and freight or discount variation, verified Plesk trust boundary, least-privilege actor, Item mapping, human review `HR-2026-08-04-003`, external BookStack push, and authenticated browser QA remain open; AI also requires a governed provider smoke if enabled. The approved ninth Feature Slice is implemented on Dev with one shared manual/email PO identity: exact supplier plus supplier order number, database-enforced across soft-deleted history, manual-first vendor confirmation without overwrites or inventory effects, fail-closed material and source-projection conflicts, locked post-confirmation identity, and accessible provenance in the canonical Purchase Orders list. Migration `2026_08_07_100000_add_supplier_order_identity_to_purchase_orders.php` remains in batch 62, and forward-only migration `2026_08_07_101000_add_database_generated_supplier_order_identity_key.php` ran in batch 63. The authoritative guard is a database-generated normalized key plus composite supplier/key unique index; the old hash unique index is removed, while the obsolete nullable hash column remains unindexed for a later reviewed cleanup. Sanitized preflight found one PO, zero populated supplier order numbers, and zero collisions. A live two-connection MariaDB 10.6.23 contract verified raw insert/update rejection, supplier scoping, blank identities, Unicode-consistent normalization, and locking-read race recovery beyond an older REPEATABLE READ snapshot. Focused identity/import tests pass 27 tests / 192 assertions; affected AI/integrity/policy/purchase tests pass 71 / 622; the full Storage suite passes 247 / 2,528; and all remaining application tests pass in bounded groups with 978 / 7,619. The opt-in PHPUnit MariaDB contract is skipped without dedicated credentials, while its equivalent live Dev contract passed. Cache clearing, Blade compilation, Pint, three protected-route HTTP smoke checks, the six-article Storage Knowledge sync, bounded worker/cron check, and zero failed-jobs check pass. Authenticated browser QA and the named human checks in `HR-2026-08-04-003` remain open. |
| Storage Supplier Shipment Confirmation Email Automation | Blocked |  | Shipment-confirmation messages were retained only as future calibration corpus; no shipment-email profile, Email rule, Signal action, shipment, tracking, receipt, or inventory mutation was created. The current supplier-order profile contract handles order confirmations only. Define and approve a separate Level 3 RFC, ADR, and Feature Slices before adding shipment-confirmation routing or mutation. Shipment email processing must never imply physical receipt or update stock automatically. |
| Storage Supplier Order Legacy Identity Hash Cleanup | Planned | Svein / Codex | After `HR-2026-08-04-003` and the rollback window are complete, add a forward migration that removes the now-unindexed `supplier_order_identity_hash` column. The generated `supplier_order_identity_key` and composite supplier/key unique index are authoritative; this cleanup must not weaken or recreate that invariant. |
| Email IMAP Historical Import And UID Re-Baseline | Done / Human Review Pending | Svein / Codex | Implemented `docs/feature-slices/2026-08-16-email-mail-historical-import-and-uid-rebaseline.md`: explicit `email.mailbox_sync_manage` permission, bounded account/folder/date preview, 31-day window, default 100 and hard 500 caps, durable progress/cancel evidence, exact UIDVALIDITY namespaces, shared provider locks, and fail-closed changed/same-validity re-baseline paths. Import is forward-cursor independent and never derives backlog from unread state or triggers provider writes, Inbox automation, Tickets, Signals, notifications, AI, or personal unread changes. Focus is 21/167; adjacent + inbound/conversation + EmailModule is 231/1,886. Additive migrations `100000`-`102000` ran one per step after recovery in Dev batches 98-100; controlled provider/runtime/browser checks remain Pending under `HR-2026-08-16-001`. |
| Mail Per-User Unread Baselines And Backlog Handover | Done / Human Review Pending | Svein / Codex | Implemented `docs/feature-slices/2026-08-16-email-mail-per-user-unread-baselines-backlog-handover.md`: current access epochs prevent new grants/delegations from flooding history while post-access mail remains unread independently of provider Seen; personal direct grants and break-glass fail closed; historical import projects insert-only current-epoch read state; and a metadata-only exact-account/user/folder/date/cap preview applies bounded backlog unread without changing provider or other-user state. Focus passes 13/118; full historical+delegation rerun is 30/340; broad Email/delegation/attachment is 171/1,548; and affected Notification/UserManagement/system-actor/Ticket is 157/1,063. Additive migrations `104000`/`105000` ran after recovery in Dev batches 102/103; authenticated browser checks and named review remain Pending under `HR-2026-08-16-003`. |
| Mail Canonical Message Shadow Correlation | Done / Review Record Reconciliation Needed | Svein / Codex | Implemented `docs/feature-slices/2026-08-16-email-mail-canonical-message-shadow-correlation.md`: bounded, resumable, account-safe local discovery records only versioned hashes, reason codes, counters, and audited immutable review decisions. Initial and final snapshots each fail closed above 64 MiB and the complete run above 256 MiB; content inspection requires current ordinary View for each exact recorded account, and deterministic oversized evidence cannot be confirmed. Focused coverage passes 19/131 and the final independent audit is GO. Additive migration `2026_08_16_110000_create_email_canonical_correlation_shadow.php` ran in Dev batch 104 as part of the one-per-step Order-1-through-7 batches 98 through 117. The summary records Svein's 2026-08-19 approval while older detailed wording still needs human reconciliation; controlled operator/worker and rollback-guard evidence remains open. No provider/data mutation or canonical cutover ran. |
| Mail Canonical Message And Placement Cutover | Done / Human Review Pending | Svein / Codex | Implemented `docs/feature-slices/2026-08-16-email-mail-canonical-message-placement-cutover.md`: immutable source occurrences, strict full-field/actual-file evidence, reversible source-to-canonical mappings and placement pointers, source-preserving `legacy`/`verify`/`canonical` reads, reviewed complete-clique consolidation, drift dissolution, retention guard, and newest-first rollback. Arbitrarily large accounts use durable whole-account parity attestations with at most 100 placements per request, one source/projection materialized at a time, current-authority continuation after requester offboarding, and a 15-minute fingerprint rechecked at preview/apply. Focused coverage passes 18/702, adjacent affected Mail coverage 91/843, 501/502-placement scope/age/success coverage passes, and final independent audit is GO. Additive migration `2026_08_16_111000` ran after recovery in Dev batch 105 without a cutover run, provider call, or private-file mutation; controlled browser/worker/provider checks remain Pending under `HR-2026-08-16-005`. |
| Mail Deterministic Rules API Completion | Implemented On Dev / Human Review Pending | Svein / Codex | Order 10 now has separate durable drafts, exact publication preview/checksum, immutable published versions, bounded message/folder/search/UTC-date previews, durable reprocess run/item/action evidence, cancellation, retry and confirmed full rerun without successful-action replay. Admin/Superuser-only publish/reprocess permissions and `email.rules.write`/`email.rules.execute` API ceilings are enforced with current mailbox access. Operational rows and responses omit mailbox content; search input is encrypted at rest. Migration `2026_09_03_090000` ran in Dev batch 24 and no run/provider action was started. Named review `HR-2026-08-16-010` plus an `email-rules` production worker remain the release gates. |
| Mail Compose/Draft/Send/Sent API Parity | Private/API Rework Implemented / Human Review Pending; Shared Collaboration Gated | Svein / Codex | The 2026-08-24 Order 11 repair closes cross-user active-draft lookup, makes ordinary drafts explicitly private, adds opaque HMAC fencing and exact-generation attachment ownership, and routes Livewire plus `/api/v1/email/mailbox` preview/send through one durable version-specific outbound submission before SMTP. Additive migration `2026_08_24_110000` ran in Dev batch 124; its backfill preserved the one existing private draft and the outbound ledger remains empty. Controlled web/API/SMTP/Sent human review remains Pending under `HR-2026-08-16-011`; shared drafts remain default-off. |
| Mail Email/Ticket Conversation Relationship Migration | Safety Rework Implemented / Human Review Pending | Svein / Codex | The 2026-08-24 Order 13 repair adds a frozen preview/apply ledger, exact active-human authorization, bounded Email-queue dispatch, deterministic relationship/provenance/audience/conflict gates, and terminal continuation/final-worker failure evidence. Additive migration `2026_08_24_130000` ran in Dev batch 127 and both ledger tables remain empty; no preview or backfill ran. Disposable data-copy/runtime review remains Pending under `HR-2026-08-16-013`, while Orders 14–15 remain dependency-gated. |
| Mail Selected-Conversation Ticket Reply And Sent Reconciliation | Implemented On Dev / Runtime And Human Review Pending | Svein / Codex | A Ticket with an authorized customer Mail relationship now opens one exact Email-owned draft in Mail, freezes recipients/thread/source/provider binding, sends through the durable Email submission boundary, suppresses the legacy Ticket SMTP job, records accepted/unresolved truth once, and reconciles one exact Sent occurrence back to the Ticket. Migration `2026_09_01_090000` is applied on Dev; focused coverage passes 6 / 44 and adjacent Email submission coverage passes 5 / 81. A controlled IMAP/SMTP browser test and Order 9 Redis/Reverb plus two-user browser runtime review remain pending under `HR-2026-08-16-016` and `HR-2026-08-16-009`; production is untouched. |
| Storage Purchase Orders, Shipping, And Receiving | Done | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-08-04-storage-purchase-orders-shipping-receiving.md` and all four linked Feature Slices are implemented, migrated, seeded, documented, and Dev-tested. The workflow covers externally placed supplier orders, multiple shipments/tracking identifiers, the Documentation-owned carrier register, partial accepted/rejected receiving, immutable receipts/reversals, and atomic inventory movements. Human review remains `HR-2026-08-04-001`; automatic vendor ordering and live carrier polling remain out of scope. |
| Storage Inventory Sortable Tables | Done | Svein / Codex | Approved Level 2 RFC `docs/rfc/2026-08-04-storage-inventory-sortable-tables.md` and both Feature Slices are implemented across all eleven read-only Storage queue, Admin, and detail/history surfaces. Sorting is allowlisted, accessible, null-last, filter-preserving, and stable while workflow forms/control slips retain their original order. The complete Storage suite passes with 95 tests / 1,233 assertions and the full Laravel suite with 1,027 / 8,558. Human review remains `HR-2026-08-04-002`; no migration, API, queue, scheduler, or frontend build is required. |
| Composer Dependency Security Advisories | Implemented On Dev / Human Review Pending | Svein / Codex | GitHub Issue #221 is remediated in the Dev dependency graph. The fresh baseline had grown to 58 advisories across 16 packages (2 critical, 18 high, 31 medium, 6 low, 1 unknown; 15 runtime packages and PHPUnit as the only development-only package); `composer audit --locked` now reports zero advisories and zero ignores. Strict validation, PHP 8.2 locked installation/platform checks, PHP 8.3 package discovery/platform checks, frontend build, route/view compilation, focused dependency consumers, and the complete Dev suite all pass. The final full run is 2,550 tests / 24,750 assertions, so the automated Issue acceptance gate is complete. See `docs/feature-slices/2026-09-03-composer-dependency-security-remediation.md`; production promotion/release remains separately gated by human review `HR-2026-09-03-006`. |
| Web Push And Inbound Email Alerts | Done | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-07-23-web-push-inbound-email-alerts.md` and accepted ADR `docs/adr/2026-07-23-notification-owned-web-push-channel.md`. All three feature slices are implemented on Dev: device/channel foundation, inbound Email/customer-reply delivery, and source read-sync rollout hardening. Dev has a cron-managed `email,default` queue worker plus direct `email:poll --account=1`; the full Laravel scheduler runner remains a separate Operations concern. Production enablement still requires named human browser/device and end-to-end checks in `HR-2026-07-24-001` and `HR-2026-08-11-002`. |
| One Responsive Nexum PWA Final Browser Acceptance | Blocked | Svein / Codex | GitHub Discussion #169's foundation and Notification/Web Push slices are implemented and automated source-contract tests now guard PWA metadata, viewport tags, mobile offcanvas shell, and the shared online-first service worker. Final closure is blocked until the new trusted HTTPS Dev vhost is available and the named human checks in `HR-2026-08-11-003`, `HR-2026-07-24-001`, and `HR-2026-08-11-002` pass. Use `docs/deployment/dev-https-pwa-vhost.md` for the vhost checklist. |
| AI Model Usage And Cost Telemetry | Done | Junie | Slices 1-3 (Ledger, Call-path migration, Rate Cards) and Slice 4 (Admin UI) are implemented. All AI calls in Lead Intelligence and Nextcloud are migrated to the telemetry contract. Decimal-precision cost calculation via versioned rate cards is active. Admin UI standardized across all AI-related pages. Human review updated to `HR-2026-08-25-006`. |
| Dev Queue And Scheduler Runtime | Blocked | Operations | Supplier Order Automation now has its own isolated `/var/Projects/tdPSA` crontab runtime with a locked `supplier-orders` database worker and dedicated dispatch, heartbeat, health, retention, and digest jobs. This removes the runtime prerequisite only for the supplier-order shadow rollout. A general authoritative Dev queue/scheduler runtime for Web Push and ordinary Email/notification scheduling remains unverified, so this cross-application Operations item stays `Blocked` and must not be marked `Done` from the supplier-specific runtime alone. |
| CloudFactory Partner Integration | Ready for Live Validation | Svein / Codex | Core integration and the versioned legal-document/Customer-admin portal-ordering slice are implemented under approved RFC `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`. Provider documents are immutable and read-only, Nexum terms remain additive, contracts and portal writes retain versioned acceptance evidence, and monthly catalogue sync performs the legal check. Remaining gates are the existing production validation in `HR-2026-07-20-001` and the focused Dev legal/portal review in `HR-2026-07-22-001`. |
| Between Competitor Parity Audit | Ready |  | Use `docs/audits/2026-07-03-between-competitor-gap-analysis.md`, `docs/ideas/`, and the 2026-07-04 draft RFC batch as planning input for booking, field-service mobile/PWA, payments/accounting, SMS automation, departments/service areas, resources, and feedback. Do not implement Level 2/3 parity work without approved RFCs. |
| Notification SMS Channel Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-notification-sms-channel-automation.md`; first slice `docs/feature-slices/2026-07-05-notification-sms-dry-run-foundation.md` adds dry-run transactional SMS configuration, templates, audit logs, manual admin test send, and Contact phone consent guards. Production providers and workflow automation remain later slices. |
| One Responsive Nexum PWA Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-one-responsive-nexum-pwa.md`; app-wide PWA metadata, online-first service worker/offline page, mobile tech offcanvas navigation, `/tech/my-day`, UI guidelines, Knowledge docs, Dev smoke checks, focused Dev tests, and Notification-owned inbound Email Web Push/read-sync slices are complete. Offline write queues remain intentionally unavailable unless a future workflow gets an approved conflict/sync design. |
| Public Inquiry Forms Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-public-inquiry-forms.md`; first slice adds Intake-owned public forms, file uploads, admin review, matching, guarded Sales routing, Knowledge docs, Dev migration/seeding, and tests. |
| Intake Signal Post-Submit Automation | Done | Codex | Approved RFC `docs/rfc/2026-07-05-intake-signal-post-submit-automation.md`; Intake now emits post-submit Signal events and Signal rules can trigger Ticket, Task, Portal invitation, Sales follow-up, and webhook actions. |
| Intake Final Routing And Review | Done | Codex | Feature slice `docs/feature-slices/2026-08-11-intake-final-routing-review.md` completes Discussion #166 scope with published/paused form lifecycle, purpose/language/scope metadata, explicit routing modes, form/field snapshots, direct Sales/Ticket/Task routing, manual review outcomes, existing-record linking, Knowledge docs, migration, and focused Intake tests. Human review is `HR-2026-08-11-001`. |
| Email/Ticket Rules Signal Alignment | Done | Codex | Approved RFC `docs/rfc/2026-07-08-email-ticket-signal-rule-alignment.md`; Email Rules and Ticket creation rules can now explicitly emit Signal records, Signal-created tickets skip Ticket Signal handoff to avoid loops, Knowledge sync includes Signal docs, and focused Dev tests passed. |
| Storage Orderable Over-Reservations | Done | Codex | Approved RFC `docs/rfc/2026-07-08-storage-orderable-over-reservations.md`; GitHub issue #177 is implemented so active orderable Storage items can be reserved beyond available stock, not-orderable items keep the available-stock guard, picking remains blocked until stock is on hand, and Knowledge docs/tests were updated. |
| Ticket Storage Reservation Release | Done | Codex | Approved RFC `docs/rfc/2026-07-21-ticket-storage-reservation-release.md`; explicit removal from inside Edit cost and quantity-zero release share one audited transaction that frees reserved stock, removes Picking List work, and restores linked approved planned lines. All affected Dev suites and rendered-view checks passed; human review `HR-2026-07-21-001` remains pending. |
| Customer Portal Foundation | Done | Codex | Approved RFC `docs/rfc/2026-07-04-customer-portal-foundation.md` with ADR `docs/adr/2026-07-04-customer-portal-identity-separation.md`; first slice implemented with portal accounts, memberships, invitations, audit events, portal dashboard, docs, Dev migration/seeding, and tests. Portal invitations now originate from Contact/Contract workflows, while the Customer Portal admin URL is reserved for future portal settings. Customer-visible domain data remains explicit slices. |
| Customer Portal Ticket Workflow | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-ticket-workflow.md`; customer-visible ticket list/create/detail/reply with explicit one-way publishing, manual visibility control, and scope enforcement is implemented. Approved RFC `docs/rfc/2026-07-28-manual-client-ticket-published-default.md` completes GitHub issue #191 by making Published the clean-install fallback while preserving valid admin choices, per-Ticket overrides, internal-Ticket isolation, history, and existing notification behavior. |
| Customer Portal Document Center | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-document-center.md`; explicitly published Documentation records and published public/client-wide Knowledge articles are exposed inside portal scope and Dev-tested. |
| Customer Portal Commercial/Economy Summary | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-commercial-economy-summary.md`; approved/accepted contract summaries and explicitly published economy order summaries are implemented, documented, migrated, and Dev-tested. |
| Customer Portal Quote/Contract Acceptance | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-quote-contract-acceptance.md`; existing Sales quote and Commercial contract acceptance are bound to authenticated portal identity, documented, migrated, and Dev-tested without implementing full CPQ. |
| Customer Portal Notifications | Done | Codex | Feature slice `docs/feature-slices/2026-07-04-customer-portal-notifications.md`; portal notification center, customer-safe notification delivery, preferences, and implemented-domain event emitters are implemented, documented, and Dev-tested under the approved Customer Portal RFC. |
| Online Booking With Calendar Availability | Done | Codex | Approved RFC `docs/rfc/2026-07-04-online-booking-calendar-availability.md`; foundation slice `docs/feature-slices/2026-07-04-online-booking-calendar-availability.md` implements public Calendar-backed requests and staff confirmation. Follow-up slice `docs/feature-slices/2026-07-28-booking-hours-and-technician-routing.md` completes service opening windows, company/technician hours, fixed/automatic/customer-choice routing, Page Header Back, plain-language spam protection, docs, and tests for GitHub issue #184. |
| Client Workspace Tickets Tab | Done | Codex | Approved RFC `docs/rfc/2026-07-28-client-workspace-tickets-tab.md`; GitHub issue #188 adds the permission-aware Client Tickets tab, required tab order, direct Ticket links, matching count, tests, and Knowledge documentation. Human review is `HR-2026-07-28-003`. |
| Client Summary Layout And Notes Autosave | Done | Codex | Approved RFC `docs/rfc/2026-07-28-client-summary-notes-autosave.md`; GitHub issue #189 adds the responsive Summary metadata grid and permission-protected Notes autosave with honest save states, tests, and Knowledge documentation. Human review is `HR-2026-07-28-004`. |
| Telephony Call Intake v1 | Done | Codex | Discussion #49 implemented with personal provider URL, public token intake, caller matching, call notes, ticket creation/linking, tests, and Knowledge docs. |
| Technician profile consolidation | Done | Codex | UserManagement owns `user_profiles`; Ticket now owns only Ticket Assignment Settings. |
| Ticket assignment settings split | Done | Codex | Legacy Ticket technician profile tables migrated into explicit assignment settings. |
| Ticket SLA v1 | Done | Codex | SLA resolution, contract SLA field, ticket show panel, index SLA risk badges, Knowledge docs. |
| Ticket Actions v1 | Done | Codex | Shared action names, guard, apply SLA action, UI gating, Knowledge docs. |
| Ticket Workflow v1 | Done | Codex | Default workflow, states, transitions, runtime validation, Ticket show actions, Knowledge docs. |
| Ticket Workflow Editor v2 | Done | Codex | Admin create/edit workflow metadata, states, transitions, and stored requirements. |
| Ticket Workflow v3 Conditional Actions And Escalation | Done | Svein / Codex | Approved Level 3 RFC `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`; all eight 2026-07-17 Feature Slices plus the 2026-07-18 customer notification and automatic migration-placement slices are implemented. Workflow supports Signal-style grouped requirements, versioned steps, server-enforced action/API parity, escalation and eligible assignment, senior review/evidence, Ticket-origin Sales quotes, planned scope, controlled fulfilment/Economy closure, Published-only transition notifications, and requirement-based per-Ticket placement when active work is migrated to a new version. Dev migration, focused cross-module tests, Knowledge/BookStack sync, and human review are tracked under `HR-2026-07-17-001`. |
| Ticket Solution Policy | Done | Codex | Approved RFC 2026-06-03 keeps internal solution notes enabled by default and admin-configurable. Follow-up RFC `docs/rfc/2026-07-28-ticket-internal-note-solution-toggle.md` completes GitHub issue #190 by replacing the duplicate composer type with a policy-protected Mark as solution switch that retains technician notification. Human review is `HR-2026-07-28-005`. |
| Ticket Knowledge loop | Done | Codex | Ticket show creates documentation follow-up events and Ticket settings lists the latest requests. |
| AI write tools | Blocked |  | Wait until Ticket Workflow/Action guards are stable enough. |
| Contract SLA UI polish | Done | Codex | Contract index, form wording, show summary, tests, and Knowledge docs updated. |
| Company Theme System | Done | Codex | Branding view now manages light/dark logos, shell surfaces, card headers, and button colors. |
| Reporting Domain Foundation | Done | Codex | Report module owns the hub and registry; Ticket registers the SLA report while keeping its query/detail view. |
| Module Settings Audit | Done | Codex | Audit captured settings ownership gaps, admin discoverability gaps, visible unfinished UI, and legacy planning files. |
| Admin Settings Discoverability Cleanup | Done | Codex | Existing beta-ready settings surfaces are reachable from Admin hub/sidebar and documented. |
| Visible Unfinished UI Cleanup | Done | Codex | Removed beta-visible coming-soon text from Asset/N-able and replaced old login copy/placeholders. |
| Email Health Check Honesty | Done | Codex | Queued email health checks now reuse the real IMAP/SMTP test service instead of writing unconditional OK results. |
| BookStack Scheduled Sync Honesty | Done | Codex | Scheduled BookStack pull/push jobs now mark active misconfigured integrations unhealthy instead of returning silently. |
| BookStack Knowledge API And Sync Hardening | Done | Codex | Approved RFC `docs/rfc/2026-06-16-bookstack-knowledge-api-sync-hardening.md`; implemented API parity for shelves/books/chapters/articles, BookStack pull/push/test/status endpoints, two-way sync diagnostics, tests, and docs. |
| Knowledge Documentations API | Done | Codex | Approved RFC `docs/rfc/2026-06-30-knowledge-documentations-api.md`; Documentation module exposes Knowledge-scoped API routes for Documentation records, documentation categories, and templates. |
| Commercial Settings Route Cleanup | Done | Codex | Contract settings URL now uses `/contracts`; legacy `/contacts` typo redirects to the canonical route. |
| Beta Release Hardening Sweep | Done | Codex | Removed mutating GET routes found in Commercial, made Queue/Worker setup paths environment-aware, and ran all module feature suites. |
| Asset Settings Slice | Done | Codex | Asset module now owns manual registration defaults and admin settings. |
| Contact Settings Slice | Done | Codex | Contact owns defaults and relation types. Follow-up slice `docs/feature-slices/2026-07-28-contact-portal-invitation-override.md` adds the approved global portal-invitation default and create-only per-Contact override for GitHub issue #185. |
| Legacy Planning Files Cleanup | Done | Codex | Moved Markdown planning/spec files out of production view paths and updated runtime doc references. |
| Task Settings Slice | Done | Codex | Task module now owns manual task defaults for status, priority, and estimate. |
| Warroom Settings Slice | Done | Codex | Warroom now owns dashboard windows, list limits, and visible panels. |
| Knowledge Settings Slice | Done | Codex | Knowledge now owns manual article defaults for visibility, status, review, and priority. |
| Risk Settings Slice | Done | Codex | Risk now owns defaults for assessments, item scoring, item status, and review interval. |
| Missing Settings Ownership RFC | Done | Codex | RFC approved; Asset, Contact, Task, Warroom, Knowledge, and Risk settings slices completed. |
| Ticket Customer Completion API | Done | Codex | GitHub issue #194 and approved RFC `docs/rfc/2026-07-29-ticket-api-customer-completion-flow.md`; API coordinators can publish eligible Tickets, send idempotent customer replies including `send_solution`, inspect decisions, transition to Resolved, and close through existing guarded actions. Dev migration, OpenAPI, Knowledge, regression tests, and human review `HR-2026-07-29-002` are recorded. |
| Organization-controlled AI and coordinator access | Done On Dev | Codex / Svein | GitHub #178 is closed/completed (read back 2026-09-27); original HR-2026-07-29-012 was Reviewed by Svein on 2026-08-25. New historical export/context work is complete on Dev under approved RFC 2026-09-27-controlled-history-and-commercial-time-export; new HR-2026-09-27-WORKLOG remains Pending before production activation. |
| Calendar Ownership View Metadata | Done | Codex | GitHub issue #137 and Feature Slice `docs/feature-slices/2026-07-29-calendar-ownership-view-metadata.md`; Calendar overlays and APIs expose stable calendar-owner/type/group metadata without broadening visibility, and the single-event API now applies private-detail masking. Human review is `HR-2026-07-29-003`. |
| Calendar Owner Badges And Accessible Color | Done | Codex | GitHub issue #138 and Feature Slice `docs/feature-slices/2026-07-29-calendar-owner-badges-accessible-color.md`; day/week/month/list use one privacy-safe owner badge with text, color swatch, accessible owner/type label, truncation, and narrow-screen overflow. Human review is `HR-2026-07-29-004`. |
| Calendar Type Indicators | Done | Codex | GitHub issue #139 and Feature Slice `docs/feature-slices/2026-07-29-calendar-type-indicators.md`; non-personal events use shared accessible type badges in all four views without weakening private masking. Human review is `HR-2026-07-29-005`. |
| Calendar Ownership Filters | Done | Codex | GitHub issue #140 and Feature Slice `docs/feature-slices/2026-07-29-calendar-ownership-filters.md`; server-scoped groups and `Only mine` filter by Calendar owner, preserve navigation/search state, intersect explicit Calendar selections, and expose clear empty states. Human review is `HR-2026-07-29-006`. |
| Calendar Mobile Readability | Done | Codex | GitHub issue #141 and Feature Slice `docs/feature-slices/2026-07-29-calendar-mobile-readability.md`; month/week retain accessible scrolling and dense month days expose a filtered `+N more` drill-down. Human review is `HR-2026-07-29-007`. |
| Calendar Ownership Rollout Tests And Knowledge | Done | Codex | GitHub issue #142 and Feature Slice `docs/feature-slices/2026-07-29-calendar-ownership-rollout-tests-knowledge.md`; regression coverage, Calendar docs, TODO, website handoff, and manual review tracking complete the rollout. Human review is `HR-2026-07-29-008`. |
| Domain API Foundation | Done | Codex | Scoped Sanctum API keys now enforce Client/Site, Custom Fields, Asset, Contact, Marketing, Ticket, Task, Knowledge, Storage, Calendar, Risk, Email Inbox, Notification, Sales, Taxonomy, Commercial, Economy, Report, and User Management API scopes. |
| Work Context / Organization Scope | Done | Codex | Discussion #149 completed through RFC `docs/rfc/2026-07-01-work-context-organization-scope.md`: foundation plus Ticket, Task, Asset, Documentation, Risk, Calendar, Report/API filters, and client-only Commercial/Economy/Sales guardrails are implemented, documented, and tested. Legacy self-client cleanup remains a separate non-goal/future cleanup item. |
| Nexum Relationship / Vendor Provider Routing | Done | Codex | Discussion #150 implemented with Relationship module, signed Nexum-to-Nexum transport, ticket escalation/public reply/status sync, selected attachment sync, documentation/Knowledge sync with conflict review, admin UI, audit logs, documentation, migration, seed updates, and tests. |
| Data Exchange Platform | Done | Codex | Approved RFC `docs/rfc/2026-07-03-data-exchange-platform.md` with ADR `docs/adr/2026-07-03-data-exchange-platform-ownership.md`; v1 profile builder, export/import runtimes, schedules, delivery attempts, API, Clients basic import, and Economy Orders export are implemented. Tripletex/PowerOffice remain future provider-profile slices. |
| Custom Fields Core | Done | Codex | Adds generic definitions/values with Client UI/API value support, Client workspace tab, and read-only definition discovery API for MSP Manager/n8n sync identifiers. |
| Client Contract Timebank Quick Consumption | Done | Codex | Approved RFC 2026-06-08; first slice adds Client Contracts tab timebank bars, audit-backed quick usage modal, permissions, and default Commercial policy. |
| Commercial Timebank Policy Admin UI | Done | Codex | Commercial settings now expose quick Client timebank policy controls backed by `common_settings`. |
| Quick Timebank Overuse Billing Integration | Done | Codex | Quick Client timebank overuse now stores rate snapshots and Economy Generate orders creates draft order lines for overused minutes. |
| Client Time Usage Tab | Done | Codex | Client profile now has a Time tab with quick registration, unified quick/ticket/task time usage history, and source-safe edit actions before Economy ordering. |
| Marketing Domain And Email Campaign Automation | Done | Codex | Approved RFCs 2026-06-09, 2026-06-17, and `docs/rfc/2026-08-24-evergreen-marketing-contact-sequences.md`; domain foundation, Email marketing defaults/templates, mailing lists, Marketing API surface, campaign approval, due sending, dashboard, tracking, campaign email cards, preview/test-send, snapshot auto-fill, AI email draft assist, AI campaign plan assist, campaign-level send rhythm, recipient batch throttling, new-contact schedule policy, multi-list campaign audiences with recipient deduplication, ongoing contact-specific progression, lifetime campaign-email delivery guards, suppression hardening, richer segmentation UI, WordPress content pull as AI/content context, and Sales/Leads marketing engagement context are implemented. Automatic repeat/stop completion behavior is retired; caught-up Contacts remain enrolled and newly appended emails are delivered once. Human review is tracked under `HR-2026-08-24-002`. Separate Marketing engagement/call lists remain intentionally out of scope. |
| Marketing Google And Social Integrations | Future |  | Add provider-backed Google Analytics/Search Console/Ads context and social publishing/import workflows under Integration-owned provider settings. Do not expose controls until each provider workflow is functional and tested. |
| Lead Intelligence / AI Prospecting Foundation | Done | Codex | Approved RFC 2026-06-12; first slice adds settings, segment policy, planned and executable research runs, scan ledger, source evidence, suppression entries, contact marketing eligibility, API abilities, simple admin/tech UI, guarded candidate promotion into Clients, Contacts, and Marketing list members, Run Now, configurable BRREG discovery, shallow website email discovery, AI discovery planning with editable prompt, OpenAI AI-provider web search for candidate URLs, provider-neutral web-search endpoint adapter, Laravel queued execution job, and grounded AI candidate review with editable prompt. Run Now queues an immediate run and dispatches the same Laravel queue job used by scheduled automation. It intentionally does not run deep crawling, hallucinated contact generation, or email sending. Next slice: deeper discovery and richer evidence review UI. |
| Lead Intelligence Schedule Foundation | Done | Codex | Approved RFC 2026-06-12; segments now have schedule period, run time, weekdays, run interval, lead target, token budget or unlimited-token mode, max runs, next/last run tracking, planner command, Laravel queued execution job, description-as-goal-prompt run context, AI segment draft assist, Run Now, and promotion targets through segment Marketing lists. Still no deep crawler, enrichment worker, or email sending. |
| Lead Intelligence Deeper Discovery + AI Enrichment | Future |  | Add controlled multi-page discovery, role/person extraction, AI evidence summarization UI, manual review queue for AI `review` decisions, website/contact confidence scoring, richer BRREG/company filters, and optional provider-backed search. Must keep settings, suppression, scan ledger, and dry-run/review guardrails in front of Marketing promotion. |
| Lead Intelligence AI-Led Discovery Worker | Done | Codex | Worker now uses an editable AI discovery planner, configured source adapters, BRREG, AI-provider or endpoint web-search results, company-specific homepage lookup for registry candidates with missing contact data, shallow website evidence collection, grounded AI candidate review, scan ledger checks, and guarded promotion. AI output cannot create companies, contacts, emails, roles, URLs, or facts unless source evidence exists. |
| Signal Domain Active Automation | Done | Codex | Approved RFC 2026-06-09; active Signal records, rules, execution audit, webhook delivery, UI, protected API ingest, Marketing producer integration, Email bounce/autoreply/unsubscribe/vendor classifiers, settings-controlled AI-assisted classification fallback, configurable rule/action settings, Client/Contact signal history, Sales follow-up action, and Ticket follow-up action are implemented, documented, and tested. |
| Report Builder And Scheduled Client Reporting | Post-Beta |  | Version 2 item. Build custom report builder, saved report templates, and automatic client report delivery. |
| Shared HTML Content Editor | Post-Beta |  | Version 1 item. Build a reusable WordPress-like Bootstrap editor with HTML/source mode, visual drag/drop content blocks, reusable template sections, preview, and safe output for Marketing emails, Email templates, Documentation, Knowledge, and future content surfaces. |
| Storage Barcode Scanning | Post-Beta |  | Version 1 item. Storage must support barcode scanners from PC and mobile workflows. |
| Storage Default Warehouse | Done | Codex | Approved RFC 2026-06-03; Storage now ensures a default Company warehouse and lets admins change it. |
| Ticket Manual Costs | Done | Codex | Approved RFC 2026-06-03; Ticket costs now support manual non-stock entries alongside Storage reservations. |

## Ready To Pick Up

### 1. Technician Profile Completion

**Status:** Done
**Owner:** Codex
**Domain:** UserManagement / Ticket
**Goal:** Finish the unified profile cleanup and remove remaining ambiguity.

Initial scope:

- Keep `/tech/profile` as the canonical technician profile shell.
- Keep UserManagement as owner of name, email, phone numbers, timezone, work hours, availability notes, and profile notes.
- Keep Ticket Assignment Settings limited to assignable state, capacity, ticket category matching, ticket tag matching, and ticket assignment notes.
- Confirm production deploy path after the legacy Ticket profile table cleanup:
  - `php artisan optimize:clear`
  - `php artisan migrate --force`
  - `php artisan user-profiles:backfill`
- Update Knowledge documentation after final UI polish.
- Profile image/avatar upload.
- Personal company default/light/dark/system theme preference after branding.

Future scope:

- Decide whether category/tag matching is ticket-only or should become general skills.

### 2. Company Profile And Branding

**Status:** Done  
**Owner:** Codex  
**Domain:** System / UserManagement / UI  
**Goal:** Add company profile and branding defaults for Nexum PSA.

Initial scope:

- Company name and organization details.
- Logo/header branding.
- Brand colors stored in settings.
- Bootstrap-compatible theme variables.
- Prepare personal light/dark mode after global branding exists.

### 3. Company Theme System

**Status:** Done
**Owner:** Codex
**Domain:** System / UI
**Goal:** Finish branding as a proper theme system.

Initial scope:

- Keep Branding as its own admin view under System.
- Keep brand/action colors separate from layout surface colors.
- Add configurable header background/text, page header background/text, footer background/text, body background, content background, sidebar background/text, card background, and border color.
- Add light theme and dark theme surface sets.
- Let company default theme be `light`, `dark`, or `system`. Done.
- Let technician preference choose `company default`, `light`, `dark`, or `system`. Done.
- Update CSS variables so shell layout never depends on hardcoded brand colors.
- Add tests and Knowledge documentation.

Future scope:

- Full Bootstrap component theming beyond the current shell, card header, and primary/secondary buttons.

### 4. Reporting Domain Foundation

**Status:** Done
**Owner:** Codex
**Domain:** Report / Platform
**Goal:** Create a proper reporting system for cross-domain reports.

Why this is needed:

- `/tech/reports` started as a global placeholder, not a real report module.
- Ticket SLA reporting is currently owned by the Ticket module as a pragmatic beta step.
- Future reports need consistent navigation, permissions, filters, exports, saved views, and ownership.

Initial scope:

- Use `Report` as the domain name unless an RFC decides otherwise.
- Create a report registry where modules can register report entries.
- Let domain modules own their report data/query logic while the Report domain owns the hub, shell, navigation, permissions, filters, and export behavior.
- Move or register the Ticket SLA report through the Report domain.
- Document report ownership rules in architecture docs and Knowledge.

### 5. Module Settings Audit

**Status:** Done
**Owner:** Codex
**Domain:** Platform / All Existing Domains
**Goal:** Audit existing modules for beta-critical settings and hardcoded behavior.

Initial scope:

- Check System, User Management, Clients, Contacts, Tickets, Email, Inbox, Calendar, Notification, Knowledge, Nextcloud, Commercial, Sales, Economy, Storage, Assets, and Tasks.
- Identify behavior that is currently hardcoded but should be configurable.
- Verify settings live in the correct domain and are reachable from Admin or Profile where appropriate.
- Verify defaults exist for clean installs.
- Verify permissions protect settings routes.
- Update `docs/TODO.md` with scoped follow-up items instead of starting large unrelated fixes.
- Update Knowledge documentation when the audit changes documented behavior.

Audit output:

- `docs/audits/2026-06-01-module-settings-audit.md`

### 6. Admin Settings Discoverability Cleanup

**Status:** Done
**Owner:** Codex
**Domain:** System / Admin Navigation
**Goal:** Make existing beta-ready settings surfaces discoverable from the Admin hub and sidebar.

Initial scope:

- Add Calendar settings to Admin landing page and admin side navigation.
- Add Notification channels to Admin landing page.
- Add Nextcloud settings to Admin landing page.
- Add integration-specific links for N-able RMM, Tactical RMM, and BookStack to Admin landing page.
- Add User roles, permissions, and two-factor settings to Admin landing page.
- Add Ticket assignment rules and technician assignment settings to Admin landing page.
- Do not add links to unfinished settings surfaces.
- Add/adjust tests for Admin hub visibility.

### 7. Visible Unfinished UI Cleanup

**Status:** Done
**Owner:** Codex
**Domain:** Platform / Existing Domains
**Goal:** Remove or implement visible beta UI that advertises unfinished behavior.

Initial scope:

- Remove or replace Asset detail "Feature coming soon" related-ticket text.
- Review N-able RMM "Fetch network equipment (Coming soon)" card and either hide it or implement useful disabled/help behavior.
- Review integration cards for buttons/toggles that expose unfinished functionality.
- Review login branding and old `tdPSA` placeholder under branding/naming cleanup.
- Add tests where behavior changes.

Completed:

- Asset detail now shows a neutral related-ticket empty state without promising unfinished behavior.
- N-able manual sync no longer exposes the network-device coming-soon action.
- Login views now use Bootstrap, current company branding defaults, and neutral email placeholder text.
- Commercial Contract/Service settings routes now render working admin hub pages instead of legacy view specifications.
- Contract create no longer renders appended legacy specification text.
- Sales lead detail now renders a working beta detail page instead of a legacy "not started" specification.
- Email account health check jobs now persist real IMAP/SMTP test results instead of unconditional OK placeholders.
- Scheduled BookStack pull/push jobs now surface missing server/token/actor configuration in integration health.
- Commercial Contract settings route now uses `/tech/admin/settings/cs/contracts`; the old `/contacts` typo redirects.
- Commercial Units creation now uses POST with CSRF instead of a GET route that created database rows.
- Commercial Cost deletion now uses DELETE instead of a GET route.
- Queue and Worker setup examples now render the current Laravel `base_path()` in the UI instead of a hardcoded development path.

### 8. Asset Settings Slice

**Status:** Done
**Owner:** Codex
**Domain:** Asset
**Goal:** Add beta-ready Asset settings for behavior that works immediately.

Completed:

- Asset settings route: `/tech/admin/settings/assets`.
- Settings storage in `common_settings` with `type=asset` and `name=defaults`.
- Admin can configure enabled asset types, default asset type, default IP mode, and default manual status.
- Manual Asset form and HTTP fallback create/update paths use the settings.
- Asset Knowledge documentation added.

### 9. Contact Settings Slice

**Status:** Done
**Owner:** Codex
**Domain:** Contact
**Goal:** Add beta-ready Contact settings for defaults and relation choices.

Completed:

- Contact settings route: `/tech/admin/settings/contacts`.
- Settings storage in `common_settings` with `type=contact` and `name=defaults`.
- Admin can configure default contact type, default status, default relation type, and enabled relation types.
- Contact form and StoreContact action use the settings.
- Duplicate protection remains mandatory.

### 10. Legacy Planning Files Cleanup

**Status:** Done
**Owner:** Codex
**Domain:** Platform / Documentation
**Goal:** Move planning/specification Markdown out of production view paths.

Initial scope:

- Move `resources/views/tech/tasks/*` planning files into `docs/` or module `Docs/`.
- Move `resources/views/tech/admin/billing/*` planning files into `docs/` or module `Docs/`.
- Move or delete obsolete `app/Modules/*/Views/**/*.md` and `*.blade.md` files.
- Keep production view paths limited to renderable Blade/PHP views.
- Verify route rendering and tests after cleanup.

Completed:

- Moved resource view specs to `docs/legacy/view-specs/resources/views`.
- Moved module view specs to `app/Modules/{Domain}/Docs/legacy-view-specs`.
- Updated runtime documentation file references in Asset, Storage, and Integration views/controllers.
- Verified no `.md` or `.blade.md` files remain under `resources/views` or module `Views` folders.
- Moved remaining task, task-template, and billing view specifications out of `resources/views`.
- Moved remaining Commercial contract and Sales lead module view specifications into module legacy docs.
- Removed empty unused runtime Blade files from Clients, Commercial, Sales, Ticket, and global admin settings paths.

### 11. Missing Settings Ownership RFC

**Status:** Done
**Owner:** Codex
**Domain:** Platform / Existing Domains
**Goal:** Decide settings ownership for active modules that do not yet have clear settings surfaces.

Initial scope:

- Create one RFC covering Asset, Contact, Knowledge, Risk, Task, Warroom, and Report settings ownership.
- Define which settings are beta-critical versus post-beta.
- Decide admin route placement and permission names.
- Define default seed behavior for clean installs.
- Define Knowledge documentation requirements.

Progress:

- RFC created and approved: `docs/rfc/2026-06-01-module-settings-ownership.md`.
- Asset Settings slice completed.
- Contact Settings slice completed.
- Task Settings slice completed.
- Warroom Settings slice completed.
- Knowledge Settings slice completed.
- Risk Settings slice completed.

### 12. Domain API Foundation

**Status:** Done
**Owner:** Codex
**Domain:** API / Platform / All Existing Domains
**Goal:** Define and implement consistent API surfaces for domains that need external integration access.

Why this is needed:

- Nexum PSA has focused heavily on UI workflows, but external integrations, automation, mobile clients, and future AI tooling need stable APIs.
- API ownership, authentication, permissions, versioning, validation, rate limiting, and documentation must be consistent before each domain invents its own API style.

Initial scope:

- API ability naming and ownership is defined in the Integration module.
- Visible "Scopes coming soon" UI was replaced with working Sanctum abilities.
- Client/Site, Asset, Contact, Ticket, Task, Knowledge, Storage, Calendar, Risk, Email Inbox,
  Notification, Sales, Taxonomy, Commercial, Economy, Report, User Management, and Custom Fields
  API scopes are implemented.
- Client create/update API supports `custom_fields`.
- Custom field definitions are exposed through a read-only discovery API.
- Current API routes are documented in Integration Knowledge documentation.
- OpenAPI documentation is generated for the current beta API surface.
- Representative auth, ability, validation, and route tests exist across the domain modules.

Future scope:

- Add domain APIs for future modules as they become beta-ready.
- Add richer filtering, includes, bulk operations, and webhooks when there is a concrete workflow.
- Add stricter service-token governance if external automation grows beyond scoped Sanctum tokens.

### 13. Report Builder And Scheduled Client Reporting

**Status:** Post-Beta
**Owner:**
**Domain:** Report / Client / Notification / Email
**Goal:** Let admins build reusable reports and schedule automatic delivery to clients.

Initial future scope:

- Custom report builder with selectable data sources, filters, grouping, and columns.
- Saved report templates.
- Client-specific scheduled reporting.
- Delivery through email and, later, customer portal surfaces.
- Per-client report preferences and recipient lists.
- Report preview before sending.
- Delivery history and failure tracking.
- Permissions for creating, editing, scheduling, and sending reports.

### 14. Email Branding And HTML Template Editor

**Status:** Done On Dev / Reviewed
**Owner:** Codex
**Domain:** Email / System / Branding
**Goal:** Make outbound email templates brand-aware and easier to edit safely.

Implemented 2026-08-24:

- Added shared, self-hosted visual/source HTML editing for reusable Email template bodies and
  Marketing campaign bodies.
- Separated editable Body HTML, plaintext, and complete Layout HTML.
- Added explicit `branding` and `custom` layout modes. Subject/body/plaintext edits do not freeze
  branding; only `Customize layout` materializes a custom layout, and reset resumes branding.
- Replaced hardcoded email chrome with a shared layout sourced from current Company Profile logo,
  light-theme surfaces, content/action colors, support email, and website.
- Added authoritative, sandboxed previews for unsaved Email template and Marketing editor values.
- Added server validation for active/interactive HTML, unsafe schemes/CSS, full documents in body
  fragments, and the required single `{{ email_body }}` custom-layout slot.
- Added immutable Marketing layout snapshots so later template or branding changes do not alter
  already-created campaign emails.
- Migrated Dev with a verified database backup and backfilled all existing campaign-email layouts.
- Added renderer, policy, controller, snapshot, preview, and regression tests plus Knowledge docs.

Review gate:

- Human review `HR-2026-08-24-004` was approved by Svein on 2026-08-25.
- The Knowledge body-size constraint was resolved by the reviewed `MEDIUMTEXT` migration under
  BookStack rate-limit coordination workstream `HR-2026-08-25-005`.

Future scope:

- Dedicated email-specific branding fields if web theme colors are not suitable for email clients.
- Per-client, per-language, per-queue, or per-workflow template selection.
- Safer variable validation and missing-variable warnings.
- Optional block/drag-and-drop project data and asset workflows behind a separately approved shared
  content-editor direction.

### 15. Ticket Workflow Requirements Enforcement

**Status:** Done
**Owner:** Codex
**Domain:** Ticket
**Goal:** Enforce the requirements already stored on workflow transitions.

Initial scope:

- Enforce `requires_note` before a transition can run.
- Enforce `requires_resolution` before resolve/close style transitions.
- Enforce `requires_knowledge_update` once documentation request tracking exists.
- Surface blocked reasons in Ticket show.
- Add tests and update Knowledge documentation.
- Update Knowledge page under `Nexum PSA -> Ticket`.

Out of scope for first pass:

- Large drag-and-drop workflow builder.
- Complex timers.
- AI write-tool execution.

### 14. Ticket Knowledge Follow-Up

**Status:** Done
**Owner:** Codex
**Domain:** Ticket / Knowledge
**Goal:** Make missing documentation visible from ticket work.

Initial scope:

- Add a lightweight “Documentation needed” action or event on Ticket show.
- Store a traceable request that points to ticket, category, client, and reason.
- Show pending documentation requests in Knowledge or Ticket settings.
- Keep articles/manual creation separate for now.
- Add tests and Knowledge documentation.

Future scope:

- KI-assisted article draft from ticket context.
- Workflow requirement: cannot close some categories without documentation update.

### 15. Contract SLA UI Polish

**Status:** Done
**Owner:** Codex
**Domain:** Commercial  
**Goal:** Make structured SLA binding clearer in contract screens.

Initial scope:

- Show SLA policy in contract index.
- Improve contract create/edit wording around “System default SLA”.
- Add a compact SLA summary to contract show.
- Ensure active contract SLA behavior is documented.
- Add tests if views or validation change.

### 16. SLA Reporting Foundation

**Status:** Done
**Owner:** Codex
**Domain:** Ticket / Reports  
**Goal:** Start basic operational reporting for SLA.

Initial scope:

- Query counts for response overdue, resolve overdue, responded within SLA, resolved within SLA.
- Start with a simple tech/admin report page or rightbar summary.
- Use ticket timestamps already available.
- Keep business-hours calculations out of first pass unless explicitly needed.
- Added `/tech/reports/tickets/sla` and linked it from the Reports hub.

### 17. Storage Barcode Scanning

**Status:** Post-Beta
**Owner:**
**Domain:** Storage
**Goal:** Support barcode-driven storage workflows from both desktop and mobile.

Initial scope:

- PC barcode scanners that behave like keyboard input.
- Mobile camera scanning for warehouse and technician workflows.
- Barcode lookup for storage items, boxes, reservations, picking, and stock adjustments.
- Settings for barcode formats and duplicate handling.
- Manual search fallback when barcode scanning is not available.

### 18. Storage Unit-Aware Picking And Adjustments

**Status:** Post-Beta
**Owner:**
**Domain:** Storage / Ticket
**Goal:** Replace the conservative safety blocks for identified inventory with explicit unit selection.

Initial scope:

- Add serial selection and batch/expiry-aware quantity allocation to Ticket picking.
- Add unit-aware inventory corrections that update Item, StockUnit, and Movement atomically.
- Define FEFO defaults and an authorized override for expiry-controlled stock.
- Preserve provenance and location when moving, consuming, or correcting identified units.
- Keep generic quantity-only adjustment and picking blocked for identified stock until this workflow exists.

### 19. AI Tool Hardening For Tickets

**Status:** Blocked  
**Owner:**  
**Domain:** Integration / Ticket  
**Blocked by:** Ticket Workflow v1 and stronger action guards.

Initial future scope:

- Expose safe read tools for SLA risk, my tickets, and ticket details.
- Add write tools only for explicitly allowed Ticket Actions.
- Log every AI tool execution.
- Require agent and role permission for write tools.

## Recently Completed

### Ticket Assignment Settings Split

- `user_profiles` now owns timezone, working hours, availability notes, and profile notes.
- Ticket assignment settings own only assignment-specific fields.
- Legacy `ticket_technician_profiles` data is migrated to `ticket_assignment_settings`.
- Legacy ticket technician profile tables are dropped after migration.
- Ticket assignment scoring reads assignment settings plus UserManagement profile data.

### Ticket SLA v1

- Tickets store `sla_id`, `sla_source`, `sla_source_id`, `sla_snapshot`, `first_response_due_at`, and `resolve_due_at`.
- SLA resolution order: Ticket Rule, active Contract, Default SLA.
- Ticket Rules can set SLA.
- Contracts can store structured `sla_id`.
- Ticket show displays SLA details.
- Ticket index displays SLA risk badges and supports `SLA risk` sorting.
- Knowledge article: `Ticket SLA - v1`.

### Ticket Actions v1

- Shared action definitions in `TicketAction`.
- Basic action gate in `TicketActionGuard`.
- Controller guards mutable ticket operations.
- `ApplyTicketSla` backend action exists for future Workflow/KI.
- Knowledge article: `Ticket Actions - v1`.

### Ticket Workflow v1

- Workflow, state, and transition tables/models exist.
- Default workflow is generated from active ticket statuses.
- New tickets use the active global default workflow.
- Ticket show displays available workflow transitions.
- Status changes are validated against workflow transitions.
- Blocked transitions write ticket events.
- Knowledge article: `Ticket Workflow - v1`.

### Ticket Workflow Editor v2

- Admin workflow index links to create/edit.
- Workflow form persists metadata, active/default flags, states, and transitions.
- Transition requirements are stored for later enforcement.
- Tests cover create and edit flows.
