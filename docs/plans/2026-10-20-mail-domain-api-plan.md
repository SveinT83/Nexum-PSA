# Mail Domain API: Full Coverage Plan

Status: Approved for implementation by Svein Tore on 2026-10-20; see ../rfc/2026-10-20-mail-full-api-parity.md
Date: 2026-10-20
Owner: Codex (inventory and plan); product/review owner: Svein Tore
Parent: [approved full Mail RFC](../rfc/2026-07-04-mail-module-full-email-client.md)
Implementation queue: [existing Mail completion index](2026-08-16-email-mail-completion-slice-index.md)
Evidence: [coverage matrix](2026-10-20-mail-domain-api-gap-matrix.md) and its JSON inventory
Environment: authoritative `/var/Projects/tdPSA`, branch `Dev`, HEAD `36918edbcaae1ce7b1208ba34f84a3a1719f0e98`, including uncommitted work

## Requested Outcome And Scope

The requested work is to map today's API and plan full coverage. The finished target is an
official, documented API for every supported Mail read, write, administration operation and
observable result. An authorized actor must be able to discover a mailbox, navigate folders,
read a conversation, act on exact messages, compose/send, inspect the resulting operation and
independently read back the result without HTML scraping or Livewire transport.

Mail is the product name. The existing singular `Email` module remains the owner. Build on its
queries, actions, policies, ledgers and provider pipeline. This plan creates no second Mail domain,
generic execution API, SMTP pipeline or permission system.

Coverage has two boundaries:

1. **Present product parity:** all implemented browser capabilities, including settings and
   persisted Livewire outcomes, with current authorization and side effects.
2. **Complete Mail target:** API contracts delivered with the remaining already-planned Mail
   capabilities. A missing product feature is tracked in its existing parent slice; adding an
   endpoint alone does not complete it.

The existing July/August approvals remain authoritative for their scope. This planning request
does not activate a runtime capability, reprioritize the active Ticket/Task implementation, or
approve a new permission/data contract. Reuse an existing authorized slice where it covers the
gap; obtain an RFC amendment/ADR approval only for decisions outside its approved contract.

## Verified Baseline And Limits

- Laravel `route:list --json` on Dev returned **145 Email-owned route rows: 73 API and 72
  browser**. GET/HEAD is counted as one route row. Nine adjacent Marketing/Ticket API rows whose
  URLs mention email are excluded from this total and retained separately in JSON.
- All 73 API rows have Sanctum authentication and an explicit `email.*` ability middleware.
  This verifies registration, not correctness of every controller or effective mailbox policy.
- Twelve API controller families cover the legacy Inbox, provider operations, drafts/attachments,
  submission status, shared drafts/presence, classification, Smart Inbox and deterministic rules.
- Forty-six runtime API method/path pairs have a matching OpenAPI HTTP attribute in Email API
  controllers; **27 do not**: Rules 19, conversation actions 5, shared drafts 3. This is a source
  comparison, not a claim that generated OpenAPI has been fully validated.
- MailWorkspace, MailSidebar, shared-composer concerns and SmartInboxReviewQueue contain
  user-visible behavior that route counting alone misses. The matrix inventories their public
  methods and distinguishes commands, queries, presentation helpers and capability checks.
- Mail code, tests, the parent RFC, TODO and human-review records already contain uncommitted work.
  This plan uses that working copy as evidence and does not replace it with the Git HEAD snapshot.
- GitHub open-Issue searches for Mail/Email and API found no dedicated full Mail Domain API Issue.
  #262 is a scheduler/queue dependency, #261 concerns AI context, and #285 owns Ticket Rules.
  No GitHub item/comment was created. Discussion #38 is referenced by the existing approved RFC;
  its current comments were not independently refreshed in this audit.
- This run performed source/contract analysis and runtime route discovery. No feature tests,
  authenticated API requests, real IMAP/SMTP operations, schema migration or production checks ran.
  Historical test counts in existing documents are historical evidence only.

## The Main Coverage Gaps

The current Inbox API is not the current Mail reader. `InboxController::messages()` selects
provider Inbox messages with no `ticket_id`; `show()` rejects linked messages. It cannot serve the
Mail workspace's account-scoped conversation list, Sent/Archive/Trash/Drafts views or a complete
linked conversation. Preserve that compatibility contract and add placement/conversation reads.

Most write building blocks already exist. Reuse private fenced drafts, signed preview/send,
submission/Sent reconciliation, seven provider placement operations and remote retry/cancel/undo.
The remaining work includes discoverable read context, ordinary selected-message read state,
incoming binary downloads/raw source, provider folder management, refresh receipts, personal
rules, Smart Inbox batch/rule-prefill, direct AI assistance, signatures and administration.

An API route's presence does not prove that its runtime feature is enabled or reviewed. Shared
drafts, presence, advanced acknowledgement and live invalidation retain their existing independent
gates. Capabilities must report unavailable/disabled states honestly.

## Recommended Contract

### Discovery, Reading And Search

Use explicit typed endpoint families under `/api/v1/email`, owned and registered inside Email.
New domain routes follow `AGENTS.md` module `routes.php` ownership; preserve existing `api.php`
compatibility wiring until its scoped reconciliation, with no domain routes in Laravel entry files.
The matrix lists proposed paths; these are design proposals, not newly registered endpoints.

- `GET /email/mailbox/context`: permitted view modes, account/sender choices, reference data,
  supported actions, current readiness and safe blocked reasons. Keep send-only identities
  separate from content-visible accounts; a configuration administrator gains no message access.
- `GET /email/mailbox/accounts` and account folders: safe identity, hierarchy, special roles,
  capabilities and permission-filtered counts. Include bounds/truncation and current authority.
- Conversation list/detail and placement detail: use the existing Mail list/thread projection,
  canonical-content resolver and current mailbox policy. Extract reusable Queries from inline
  Livewire queries where necessary. Do not create a second SQL implementation of Mail semantics.
- Keep account-scoped conversation IDs and exact source placement IDs distinct. Support bounded
  continuation for compatibility conversations without a durable ID; do not fabricate a global
  conversation identity from Message-ID or subject.
- Preserve the default unread-for-me Inbox and existing all-mail/folder/Sent/Archive/Trash/Drafts
  filters. Linked Mail remains readable when provider placement and current grants allow it.
- Search uses the same current supported text/filter semantics as Mail. Advanced full-text,
  attachment indexing and facets belong to existing Order 22; expose them only with that feature.
- Binary attachment/raw responses reauthorize the exact placement and source part, verify private
  storage/canonical evidence, use safe content headers and no-store, and return no storage path.
  Attachment metadata and a session-protected browser download do not establish bearer API access.
- GET must not mark read, record opened-by, create signatures, start sync, publish rules or run AI.
  Access/security audit required by policy remains permitted. Record an explicit opened action
  separately, then return only authorized collaboration facts.

### Commands And Exact Results

The selected-message read command must follow the accepted
[September read-action ADR](../adr/2026-09-03-email-simple-read-action-authority.md):

- Shared/system mailbox or personal delegate: change only the actor's local unread state.
- Personal owner: also request provider Seen/Unseen through the existing remote-operation ledger.
- Return local success and provider pending/failed/succeeded separately. Provider failure does not
  undo the local state. Opening a message alone does not acknowledge it.
- Reuse/extract the browser's `setSelectedReadState` orchestration. The gated conversation-wide
  acknowledgement API is not a replacement for this ordinary action.

Every stateful operation returns its authoritative record/resource or a receipt with a permitted
status/read-back link. A queued job is queued; SMTP acceptance is acceptance; a verified Sent copy
is Sent reconciliation. None proves recipient inbox delivery. Folder refresh currently queues an
account fetch, so the API must not promise a narrower provider action than it actually performs.

Keep existing draft UUIDs, HMAC generation fences, source/attachment versions, preview checksum,
shared lease fences and once-only pre-SMTP submission. Never blindly retry an ambiguous send.
Existing remote-operation identity includes the source account/folder/UID namespace and version.
New mutating contracts need actor/payload-bound idempotency, concurrency/precondition behavior and
explicit omitted/null semantics. Administrative activation should set a target state rather than
expose a retry-sensitive toggle. Do not add a second generic job or mutation ledger by default.

Provider folder create/rename/move/delete must use the current guarded actions and blockers.
Expose source-folder emptying as bounded per-item results with remaining count; the browser moves
at most 50 placements per request. Never report complete evacuation after one partial batch.

### Permissions, Privacy And Administration

Intersect active actor status, token ability, current Email permission, account/operation grant,
delegation/break-glass policy, current provider/account policy and target-domain scope. Recheck
after revocation and at worker execution, not only at preview. Keep the existing human-actor
restrictions in drafts/rules; a service workload needs its own approved binding contract.

Retain the implemented abilities: `email.read`, `email.update`, `email.drafts.read/write`,
`email.send` and `email.rules.read/write/execute`. Proposed new ability families are mailbox
content/context, attachments/raw, personal state/preferences, folders, account configuration,
access/delegation/emergency, maintenance, templates/settings and AI. Freeze exact names and
field rights per approved slice. A new full-mail read contract should not silently widen the
legacy Inbox token contract. Register abilities only with real routes and negative tests.

Configuration APIs reuse the accepted account-owned IMAP/SMTP workflow: encrypted write-only
password input, omitted/blank-password preservation, safe masked reads, binding-version fencing,
separate incoming/outgoing test status and activation only after the required successful tests.
Do not revive the superseded Integration provider-first setup. OAuth governance for future drivers
remains Integration-owned. Health/configuration access remains independent of content access.

Expose current administrative reads and commands: account grants, delegation and history,
reasoned expiring emergency access, unread handover, bounded historical import, reconciliation,
cursor rebaseline, canonical correlation/cutover/rollback, configuration/retention preview and
templates. Preserve their existing warnings, authority, preview/apply checks and runtime gates.
Do not invent account deletion, template deletion or draft download as current browser behavior.

Rules keep draft/publish/version/checksum semantics. Admin toggle can be represented by the
existing draft-and-publish outcome; personal rule creation and delete/draft discard/catalog/run
discovery require explicit coverage. An API must not activate a draft or replay successful rule
actions. Smart Inbox batch cleanup returns independent item results; Always do this prepares a
visible reviewed rule proposal, not hidden learning.

Mail AI summary, rewrite and draft-reply use the existing Mail AI runtime and Integration egress
gate. They never send or apply inferred writes. Errors must be sanitized; current browser raw
exception text is not an API response contract. The existing #261 AI-context work remains separate.

Ticket create/link/suppression and selected-relationship replies require both Email and Ticket
authority. Keep `TD-...`, one primary routing target per account-conversation, provenance,
audience and provider source placement. The current browser can create a Ticket before link audit
fails; freeze the approved idempotent/atomic result or explicit recovery contract before exposing
this through API. Coordinate with active Ticket/Task API work rather than implement it twice.

## Delivery Sequence After Target Review

These are proposed work packages, not newly opened Feature Slices. Before starting each, reconcile
the existing queue, approvals, dirty files and human review; define or amend one complete slice.

| Order | Complete outcome | Matrix coverage | Required validation / dependency |
| --- | --- | --- | --- |
| 0 | Freeze current contracts and reconcile deletion discrepancy | M40-M41 | Named decision on legacy delete; baseline schema/OpenAPI inventory; preserve active work |
| 1 | Discover accounts, folders, senders and readable conversation/placement context | M01-M05, M21, M42 | Same authorized list/count/thread results as Mail; no writes during reads; bounded pagination |
| 2 | Read binary content and perform ordinary opened/read actions | M06-M09, M44 | Exact-part bytes; two-user shared isolation; personal-owner provider outcome; advanced acknowledgement stays separately gated |
| 3 | Complete folder/refresh workflows around existing remote operations | M10-M12 | Provider fake plus explicitly controlled provider run; partial batches/recovery; no blanket worker start |
| 4 | Verify existing compose/send/Sent contracts and add signature parity | M13-M19, M27 | Existing Orders 9/11; generation/lease replay and concurrent sends; current human-review gates retained |
| 5 | Complete rules, Smart Inbox and governed assistance | M22-M26 | Personal/admin distinction; checksum publication; batch results; no implicit send; existing Order 10 |
| 6 | Complete account, access and ordinary settings/template APIs | M30-M34, M39, M42 | Write-only credentials, owner privacy, grant expiry/revocation, auditable configuration, queued tests |
| 7 | Expose operator maintenance and live catch-up contracts | M20, M35-M38 | Existing Orders 1-8/13-14; bounded preview/run/cancel/status; current runtime review and #262 |
| 8 | Finish Mail/Ticket cross-domain outcomes jointly | M28-M29 | Existing Orders 13-20 and Ticket API owner; no recipient/audience/source mutation by accident |
| 9 | Close contract and operational acceptance for every row | M01-M44 | OpenAPI, bearer reads, parity regression, handbook read-back, named human review |

Runtime-disabled features can have their contracts verified with isolated fakes while remaining
disabled on shared Dev. Implementation order cannot bypass a defect or a required predecessor.

## Complete Mail Target Beyond Current Browser Parity

The existing completion index remains the source for unfinished product capabilities. Its current
Orders 17-32 include recipient/audience/closed-Ticket behavior, malware quarantine, advanced search,
hold/export/offboarding/restore, permanent provider deletion, governed entity matching, restricted
automatic replies, Microsoft 365 and Gmail drivers, calendar invitations/OOO, file handoff, advanced
collaboration and reporting/productivity. Deliver domain API reads/actions/status with each owning
slice, including denial and capability discovery. Do not mark these complete because a placeholder
route exists, or duplicate them as a second implementation backlog.

## Dependencies, Data And Rollout

Email owns transport/content/operation records. Integration owns the API ability catalog, OAuth and
AI governance; UserManagement owns identity/global permissions; Taxonomy owns definitions; Ticket
owns its workflow and audience; Notification owns delivery; Documentation and Calendar own their
target writes. Password mailbox settings remain Email-owned.

Planning changes require no migration, queue restart, scheduler/cache/build command or production
action. API adapters should reuse existing schema where possible. Before any implementation slice,
verify real Dev schema, indexes, guards and migration ledger; do not infer deployed schema from the
presence of migration files. New schema/permission/integration contracts require their scoped plan
and architectural approval. Do not create an API-wide migration to bypass module-owned ledgers.

For implementation, list only the migration/permission/cache/build/worker actions actually needed.
Verify the external minute scheduler and specific supervised queues before declaring async actions
healthy. #262 owns shared runtime reconciliation. Never start a worker or retry historical cohorts
as a side effect of planning/API registration. Preserve forward-disable/recovery paths and ambiguous
send evidence. Svein owns commit, Main promotion and deployment.

## Verification And Completion Criteria

1. Every matrix capability is implemented with a tested contract, or linked to its explicitly
   approved product dependency/decision. No missing row disappears during slice planning.
2. Every permitted read and persisted browser outcome has a bearer API equivalent. GETs have no
   business writes; every command has truthful independent result read-back.
3. Feature tests compare browser and API outcomes with the same user/accounts: owner, delegate,
   shared View, Organize, Send-only, configuration admin, emergency actor, inactive/revoked user,
   limited token and system actor. Include unauthorized linked Ticket and cross-account copies.
4. Cover personal unread/provider Seen/opened-by separately, revoked/expired grants, UIDVALIDITY,
   stale folder/placement/draft versions, canonical drift and duplicate attachment parts.
5. Fake provider and queue tests cover failure, unknown, partial success, cancellation and replay.
   Disposable MariaDB tests establish relevant locking/concurrency; SQLite alone does not.
6. Existing Email/Integration/Ticket/Notification/Taxonomy affected tests pass on Dev. Add actual
   contract checks comparing registered method/path/abilities/OpenAPI and a maintained capability
   inventory; route count alone cannot detect Livewire-only behavior.
7. In a separately controlled test mailbox, verify bearer account discovery -> conversation ->
   attachment bytes -> draft/preview/send -> exact submission/Sent result. Preserve unread mail,
   record intended side effects and verify the exact read-back. Recipient receipt is a separate claim.
8. Update Email/Integration Knowledge, API docs and module README. Publish only reviewed changed
   articles through the supported BookStack path, read back content and employee scope, and verify
   employee-assistant retrieval separately when claimed. The current Ticket/Task TODO records no
   configured Dev BookStack publication path; recheck configuration and record owner/blocker before
   publishing. Do not bulk-push unrelated pending articles.
9. Open/update a stable human-review entry for each implemented large slice. Existing pending
   reviews, including HR-2026-09-03-002 and HR-2026-08-16-008/009/010/011/012/016 plus account-setup
   HR-2026-09-01-001, are not satisfied by this plan. They retain their documented promotion,
   migration, deployment or runtime gates until a named human confirms the required checks.

## Product Decision Required Before Deletion API Work

`Tech/InboxController::destroy` can call provider `deleteByUid` when `delete_policy=sync_delete`,
then soft-delete the local message even when provider deletion is unconfirmed. The accepted Mail
design instead distinguishes ordinary Trash from separately gated permanent deletion (Order 24).
This discrepancy is verified source behavior; its live use and provider outcome were not tested.

Recommendation: ordinary API Delete means the existing provider Trash operation. Keep any legacy
local hide behavior explicitly named and scoped if it must remain compatible. Permanent provider
deletion must use Order 24's distinct permission, retention/hold, confirmation and review gates.
Svein must decide the legacy browser compatibility behavior before that endpoint is exposed or
the browser behavior is changed. This decision does not block non-destructive API planning.

## Planning Handoff

Deliverables are this plan, the capability/route/method matrix and its machine-readable evidence.
Only planning documentation and one additive TODO register entry change. No application behavior,
secrets, token abilities, provider state or human-review approval changes. Planning requires no new
human-review gate; implementation and existing runtime reviews remain separate work.

## Implementation Approval Update (2026-10-20)

Svein approved complete browser read/write/action/admin parity and Swagger. He explicitly selected
ordinary Delete-to-Trash in both interfaces. The earlier deletion decision is resolved; historical
baseline evidence remains above. Execution is tracked in the approved Mail API RFC and six slices.


## Implementation Completed On Dev

Approved full scope implemented: 102 added APIs, 175 total operations, 109 writes/actions;
Swagger coverage 175/175. All six slices are Done On Dev. The 72 browser routes and 155 public
Livewire declarations have a maintained parity register. See 2026-10-20-mail-api-verification.md.
Ordinary Delete is provider Trash in both paths; the prior delete question is resolved.
HR-2026-10-20-MAIL-API is Reviewed. Svein Tore approved the delivered Mail API scope on 2026-10-21 in chat: "Godkjenner".
Main promotion/deployment/release remain separate actions; independent runtime gates remain.
