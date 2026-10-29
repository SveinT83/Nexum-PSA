# Issues 262, 285 And 290: Merge Readiness

Date: 2026-10-28
Owner: Codex / Svein Tore
Status: Done On Dev / Merged To Main / Production Testing Owned By Svein Tore
Human review: HR-2026-10-27-OPS-RULES-COORDINATOR, completed Dev implementation Reviewed by Svein Tore ("Godkjent"); overall In Review for remaining operational steps.

## Purpose And Scope

Svein requested completion so the work can be merged to Main. The core runtime implementation is
already on Main through PR #372; the Ticket Rules UI correction is already on Main through PR #374.
This candidate adds only the missing minimal-workflow acceptance test, the existing narrow
coordinator setup guide and this bounded readiness/review record. It does not repeat either merge
or turn issue closure into a prerequisite for making verified source available on Main.

Authoritative implementation and tests: /var/Projects/tdPSA, branch Dev. Tested latest-Main base:
a0594efa1a3c4438bd20e25f9ecba93db10fa4fd. More than 2,000 shared Dev paths exist; their code, staged
index and active branch are preserved. Only owned files/sections are selected for the candidate.

## New Minimal Workflow Acceptance

TicketRuleMinimalWorkflowAcceptanceTest uses the supported workflow draft/publication, Rule
draft/publication, explicit authority/enablement and canonical StoreTicket boundaries with
synthetic SQLite data and suppressed/faked notifications. Exactly ticket.created and
select_workflow are enabled only inside each isolated test.

For manual/UI, email, API and integration channel contexts:
- A Ticket in the Implementering test queue gets the exact published workflow version, initial
  state and status, with one successful durable Rule run and one workflow selection history row.
- A Ticket in another queue does not receive that workflow.
- Re-delivering the canonical created event keeps one run/history row.
- Publishing a later workflow version does not repin either the existing Ticket or the Rule.
- Publication alone does not enable the Rule; other triggers/actions and full rerun stay off.

The four cases pass 80 assertions. They exercise the shared domain boundary; they are not live
HTTP/IMAP/provider transport acceptance or production activation evidence.

## Fresh Verification

- Authoritative Dev affected matrix: 69 tests / 720 assertions, all passing.
  Includes RuntimeHealth, minimal workflow, Rule builder, creation parity, workflow actions,
  coordinator governance and generated Worklog OpenAPI contract.
- Isolated latest-Main candidate: 68 tests / 691 assertions, all passing, without the separately
  generated OpenAPI case. Private vendor/autoload, synthetic test-only environment and the
  existing asset manifest are used. No runtime credentials are copied.
- New PHP file: Pint, syntax and whitespace checks pass.
- Earlier 4-test / 80-assertion batch overlaps; totals must not be added.
- The first private Main harness lacked its synthetic .env and asset manifest. Its incomplete
  run was stopped, the harness was repaired, and the complete final matrix above passed.
- No full application suite was run on the shared server. No observed verification failure is
  deferred as passing.

## Current Operational Evidence And Exact Remaining Work

Read-only Dev snapshot at 2026-10-28T21:08:52Z:
scheduler/default/email/supplier-orders were healthy; economy/email-live/notifications were
operator-review blocked. Overall health remains blocked and business delivery remains unverified.
Current-user cron hash f737c6613c206887ab60a045f05e982b7e203a123405b98f1775409758622faa still matches
the reviewed single scheduler and separate default/email/supplier workers; every entry has flock
and umask 0002. No direct domain trigger was reintroduced.

Pending observations: 76,047 email-live, 70,202 notifications, one current Email job. These are
timestamped moving counts, not a frozen dataset. Ten failed records remain preserved: eight
Email health-lock failures, one historical scheduled-Ticket failure and one IMAP max-attempt
failure. Their latest failure timestamps have not advanced beyond 2026-10-27 in this snapshot.
No retry, delete, suppression, cold-queue consumer or delivery test was performed.

#262 still requires an owned, bounded disposition/recovery decision for exact historical/cold
cohorts, complete cross-domain duplicate-path operating acceptance and controlled required-queue
acceptance. Passing canaries or merging source cannot satisfy those side-effect criteria.

#285 still requires the separately scoped production identity/backups/compatibility/capability
cutover and actual match/non-match read-back across deployed creation paths. Its proposed minimal
scope is ticket.created + select_workflow. Other capabilities, Custom Field gates and full rerun
remain deferred/off unless explicitly approved. No production object or switch changed here.

## Coordinator Receiver And Setup Readiness

Existing Report documentation identifies Paperclip -> NexumMCP -> Nexum PSA as the intended chain.
Live Paperclip read-back confirms TRN-2 is assigned to the CEO agent, which uses codex_local and an
OpenAI AI connection. The adapter does not explicitly pin a model. This establishes the receiving
service/provider, not the effective model, permitted processing mode or completed governance.
No further model/review facts were supplied by the owner.

Live Dev still has disabled installation revision 1, aggregate/local_only maximum, zero provider
governance records, zero model policies, zero workloads and zero token bindings. These defaults
are preserved. Do not label external Codex execution local_only, invent contractual declarations,
reuse the shared connection token or invoke broad Activate AI.

The narrow setup guide existed on Dev and in BookStack but was absent from Main. Promote only
that owned section, preserving all other Main guide content. Its instructions cover purpose,
pseudonymized output, necessary existing source rights, <=7-day approval/token, a dedicated
connection, no names/free text/payload retention, the exact 2026-08-31 single-row request,
deny checks and revocation rollback. The ordinary report discovery API has no Worklog result
export and is not an approved substitute.

#290 remains an operational dependency until the actual model/mode and provider/model review
records are established, then one legitimate authorized request is read back. Current known
receiver facts supersede the older blanket "unknown recipient" wording; nothing here establishes
a live credential or authorized external result.

## Handbook And Release Handoff

Authoritative Integration guide updated first; BookStack page 541 revision 9 was read back against
the expected body, with only the service's trailing-newline normalization. Page permissions and
inherited book scope are unchanged; the existing /staff-internal Editor role retains page-view-all.
Employee OpenWebUI retrieval remains a separate untested claim.

The candidate requires no migration, seeding, asset rebuild, queue restart, cron/configuration,
provider/permission change or production deployment command. Only test coverage/documentation
changes are new. Preserve all unchecked operational review items. Do not close #262/#285/#290
with this PR, and do not describe the whole runtime or external Worklog integration as complete.

## Published PR And Final Read-Back

PR #381: https://github.com/SveinT83/Nexum-PSA/pull/381. Head a0a7edea35617813601dfc00f7211ae015d31b2b; base a0594efa1a3c4438bd20e25f9ecba93db10fa4fd. GitHub independently reports open, non-draft and mergeable. No CI checks/statuses are registered; the verified Dev/private-Main results above are the test evidence. No Main merge was performed in this session.

Issue bodies and comments #262/6481092667, #285/6481093492 and #290/6481094261 were independently read back, all still open. Only their exact remaining operational criteria prevent closure.

## Verified Main Merge And Production Handoff (2026-10-29)

Svein Tore explicitly assigned production testing to himself and instructed Codex to make the remaining decisions, fix the scoped work and merge it. Svein owns production testing. Codex owns scoped Dev verification and Main promotion and makes routine technical choices within that scope. No additional generic approval is required for the completed source work.

PR #381 is merged, independently read back as closed/merged. Merge commit
2bff45703db4ce5fd82210b8c8f79c9e2142d111 has tree
8cfea0cdcced6023601f1d825818b97c4a7b8ffb, exactly matching the verified candidate.
Main base/head had not changed since the passing 69/720 Dev and 68/691 private-Main matrices;
no redundant full rerun was made after the source-identical merge. No full application suite was run.

Completed Dev implementation remains Reviewed by Svein Tore. Overall
HR-2026-10-27-OPS-RULES-COORDINATOR stays In Review for the unperformed operational/production
checks; this is not a remaining source-merge approval request. Required production configuration,
actual external Worklog authorization/read-back and historical/cold-cohort effects are not inferred
from source availability. Existing purpose/profile/scopes/expiry/privacy and queue protections remain.

Dev branch, HEAD and staged index are unchanged. No production deployment, configuration switch,
worker/cron change, historical retry/delete/suppression, token issuance or secret replacement occurred.
