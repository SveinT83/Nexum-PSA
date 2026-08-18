# ADR: Mailbox Access And Email Rule Authority

Status: Accepted
Date: 2026-08-11
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Related ADR: `2026-08-11-email-conversations-as-ticket-communication-channels.md`

## Context

Email accounts and rules are currently global. The technician Inbox and Email Inbox API query all
unrouted messages, message actions primarily check Ticket linkage rather than account access, all
active Email rules can evaluate every inbound message, and unmatched inbound mail follows a default
Ticket/lead Ticket policy.

That behavior is appropriate only for explicitly selected shared/system intake accounts. Adding an
`owner_id` to `email_accounts` without changing every read/action/poll/rule path would expose personal
mail and could convert it into Tickets automatically.

Discussion #38 also proposes personal rules on owned accounts and limited personal behavior in shared
mailboxes. A durable decision is needed for global permissions, per-mailbox grants, delegation,
administrator access, rule precedence, legacy rule migration, and high-risk actions.

Shared addresses are real provider-backed IMAP/SMTP accounts configured under Admin, not local aliases
or synthetic combined inboxes. Admin must be able to decide independently who may see, organize, and
send from each account without receiving content access merely by configuring it.

## Decision

### Effective Mailbox Authorization

Every Email UI query, route-model binding, API request, attachment operation, search/index query,
notification, job, rule, AI workload, and cross-domain action must use one effective authorization
decision. The most restrictive intersection wins:

1. authenticated human or service identity,
2. active global Email role/token ability,
3. account ownership, membership, delegation, or system-workflow binding,
4. operation-specific mailbox grant,
5. current account and processing policy,
6. linked Work Context and target-domain record authorization,
7. Integration data-egress policy for AI/provider disclosure.

An API token ability is only a request ceiling. It does not grant every account or personal message.
A background/system actor is limited to the explicitly bound account, workload, and actions.

### Account Defaults

- **Personal account:** exactly one owner; content is private by default; Ticket ingress is off; no
  legacy organization/shared rule is inherited implicitly.
- **Shared account:** a real provider-backed IMAP/SMTP account configured under Admin; access comes
  from explicit user/group grants. Ticket ingress and account rules are explicit settings rather than
  consequences of membership or a nullable owner.
- **System/service account:** operations are bound to documented workflow scopes and a non-login
  system actor where needed; ordinary technicians do not receive content access automatically.

All existing accounts migrate to shared/system-compatible state. No account becomes personal during
backfill. Current Ticket ingress remains enabled only for the existing accounts deliberately mapped
to that behavior. Ambiguous accounts fail closed for automatic Ticket creation until reviewed.

### Account Administration Versus Content Access

Configuring account address, provider connection, health, workflow purpose, and grants does not imply
permission to read bodies, raw source, attachments, search snippets, or AI summaries.

Personal mailbox content is available to:

- the owner,
- an explicitly delegated user for named operations and a bounded period, or
- a user exercising a distinct time-bounded break-glass permission with a reason and audit event.

Ordinary administrator/supervisor status alone is insufficient at the application layer. Break-glass
access is visible to the mailbox owner where legally/operationally appropriate, expires automatically,
and never grants hidden permanent access.

Offboarding disables new sync/send and preserves data according to company retention. Transfer,
conversion to shared, export, deletion, or delegated continuity are explicit audited decisions.

### Operation Grants

Admin presents three independent primary mailbox grants:

- **`view`:** discover/list the account and folders, read safe content, preview/download allowed
  attachments, search authorized content, explicitly update only the user's local `unread for me`
  and other personal view state, and participate in privacy-filtered opened-by/reading presence. It
  does not mutate provider/shared state, and opening content never marks it read.
- **`organize`:** adds provider read/unread, flags, folder create/rename, message move/copy, archive,
  normal trash, and shared account-scoped conversation category/tag assignments. A user-facing
  organize action also requires `view`; this grant alone never discloses content. Permanent delete
  remains a distinct high-risk permission and confirmation.
- **`send`:** permits new compose through the account's real provider/SMTP identity without granting
  Inbox or Sent access. Reply and forward require both `view` of the source and `send`. `Send as` and
  `send on behalf` are exposed only when provider capability and narrower policy permit.

Personal account owners receive the ordinary primary grants unless a stricter company/account policy
removes an operation. Shared-account membership alone grants none of them.

Advanced grants remain explicit rather than hidden effects of the primary levels. They include:

- read raw source,
- permanently delete/expunge messages or delete shared folders,
- manage personal rules,
- publish shared/system rules,
- manage account access,
- run poll/import/re-baseline/reprocessing,
- review audit and break-glass history.

Opening mail with `view` records the current user's authorized opened-by fact and may advertise
short-lived reading presence, but it does not change `unread for me` or provider `Seen`. Personal
read/unread changes are explicit user actions. Provider `Seen` is a distinct explicit action requiring
`organize`; another user's or external client's provider change never clears the current user's
personal unread state. Personal rules in a shared mailbox may likewise change only local metadata
with `view`. Provider/shared state requires `organize`; external sending requires `send`;
cross-domain writes and Signal handoff require their own authority and rule publication permission.

### Conversation, Presence, Draft, And Taxonomy Authority

Authorization is evaluated for the account-scoped conversation and again for each projected message,
placement, attachment, draft, participant, and collaboration fact. A correlation to another account
does not widen access. Conversation counts, snippets, opened-by users, typing indicators, categories,
tags, and live invalidations must not reveal inaccessible accounts or messages.

Reading and typing presence is ephemeral, private, heartbeat-based, and limited to users who may
currently view the same account-scoped conversation. Presence payloads contain opaque account/thread
identifiers and state only, never sender/recipient addresses, subjects, snippets, body, attachment
names, or draft content. Access is rechecked when subscribing and when handling an invalidation;
revocation ends future delivery and authorized queries immediately. Durable opened-by facts are
visible only through an authorized Email query and are not inferred from provider `Seen`.

A reply draft requires `view` of its conversation and `send` through its account. Other authorized
collaborators may see who is currently drafting and a short-lived responder reservation, but draft
content remains protected by the ordinary message/draft authorization and is never broadcast. A
concurrent send or changed thread state requires revalidation and a visible conflict warning before a
stale draft is sent.

Email uses the existing Taxonomy domain's category and tag definitions. Email owns account-scoped
conversation assignments; viewing them requires `view`, changing them requires `view` plus
`organize`, and creating or administering definitions additionally requires the applicable Taxonomy
permission. Assignments do not cross to correlated messages in another account and do not mutate
provider folders/keywords unless a separate approved mapping explicitly says so.

Ticket and other domains call Email's guarded outbound Actions rather than bypassing mailbox grants.
A Ticket permission alone does not grant access to an Email account, draft, conversation, attachment,
or sender identity. Ticket communication-channel ownership and the allowed explicit/system actor are
defined by `2026-08-11-email-conversations-as-ticket-communication-channels.md`.

### Rule Scope And Precedence

Every rule has an explicit owner/publisher, account scope, optional folder/view scope, trigger phase,
published version, and action authority. There is no implicit scope covering all present and future
accounts.

Rule precedence is:

1. non-overridable ingestion, security, trusted-routing, retention, and compliance rules,
2. organization/shared/system account rules published by an authorized mailbox rule publisher,
3. personal technician rules within the technician's current mailbox grants,
4. explicit guarded handoff to Signal or another domain.

A lower layer cannot undo, skip, or widen a higher restriction. `stop_processing` affects only its
eligible layer/lower-priority evaluation and takes effect after successful ordered actions.

Existing global rules migrate as legacy organization/shared rules with concrete account scope. Order,
routing phase, active state, and stop behavior are preserved only when the account scope is
unambiguous. Ambiguous rules are disabled and placed in an administrator review queue. They never
apply to a new personal account automatically.

### Rule Execution Authority

- Draft rules have no runtime effect.
- Publishing snapshots an immutable version and revalidates current owner, account scope, actions,
  and permissions.
- Preview/dry-run has no side effects and reports policy/permission denials.
- An action failure marks later actions in the rule `not_run`; other eligible rules may continue.
- Execution attempts are immutable. Retry runs only failed/not-run action positions that have not
  already reached a terminal successful state.
- Stable idempotency keys include message/placement, published rule version, and snapshotted action
  position.
- Full rerun is an explicit warned operation; undo exists only for a verified reversible action whose
  target has not changed incompatibly.
- `Always do this` creates a visible draft rule and preview; it never activates hidden learned
  behavior.

### High-Risk Actions

Permanent delete, provider/server writes, external send, Reply All/CC/BCC, new-recipient delivery,
webhooks, attachments, and writes in other domains require distinct guards and publication authority.
The target domain always reauthorizes its own write.

Automatic external replies are excluded from ordinary rule approval. A later Feature Slice requires
organization enablement plus publication of each rule by a user with explicit mailbox auto-send
authority. Personal owners remain bounded by the organization maximum. Marketing/bulk mail cannot be
sent through Email rules and stays inside Marketing approval/suppression policy.

## Rationale

- Global permission plus per-account/operation grants prevents both broad administrator access and
  accidental API/service-token disclosure.
- Ticket ingress as an explicit account policy preserves current shared intake without applying it to
  personal mail.
- Separate personal-local and shared/provider state lets technicians organize shared mail safely.
- Scoped opened-by/presence and shared-draft coordination show active handling without changing
  manual unread state or leaking content through broadcasts.
- Reusing Taxonomy definitions avoids a parallel label system while Email keeps assignment access
  aligned with the real account-scoped conversation.
- Fixed precedence protects security, retention, and trusted automation from personal override.
- Draft/publish, immutable attempts, and idempotent retries make powerful rules reviewable and
  recoverable.
- Dual authorization for high-risk cross-domain/send actions prevents Email rules or AI from becoming
  a general write bypass.

## Consequences

Positive:

- Personal accounts can be added without exposing or ticketizing their mail by default.
- Shared accounts can grant the minimum operations needed by each technician.
- The Admin surface maps understandable `view`, `organize`, and `send` choices to one enforceable
  operation-level policy used by UI, API, jobs, rules, and Livewire refreshes.
- Administrator health/config workflows remain possible without normal content access.
- UI, API, jobs, rules, search, notifications, attachments, and AI share one security contract.
- Legacy rule behavior is preserved only where its intended account scope is known.

Negative:

- Every existing Email query/action/job needs an explicit scope audit before personal accounts are
  activated.
- Permission/grant administration and break-glass workflows add schema, UI, audit, expiry, and
  operational complexity.
- Some legacy rules/accounts may require manual mapping before automatic processing resumes under the
  new model.
- Per-user local state and provider/shared state can differ and must be explained clearly in the UI.
- Presence expiry, draft concurrency, opened-by retention, and per-event authorization add operational
  and negative-leakage test cases.
- Administrators need clear effective-access previews because group and direct grants can otherwise be
  difficult to reason about.
- Automated tests must cover a large role/account/operation matrix and negative leakage cases.

## Alternatives Considered

- **`owner_id` plus administrator bypass.** Rejected because it leaves polling, API, actions, rules,
  search, attachments, and AI vulnerable and treats admin configuration as content authorization.
- **All personal mail visible to supervisors.** Rejected as an unsafe default for employee and
  client-confidential data; explicit audited access is available when justified.
- **Apply current global rules to every account.** Rejected because current default Ticket routing and
  shared rules would mutate personal mail unexpectedly.
- **Let users run any rule on shared mail they can read.** Rejected because read permission does not
  imply provider mutation, cross-domain write, or send authority.
- **Let `view` imply provider Seen, move, archive, or delete.** Rejected because a read-only member
  must not change a real shared IMAP account merely by opening or inspecting mail.
- **Let opening imply personal read.** Rejected because inspecting a message and deliberately marking
  it read are separate user actions in the shared-workflow model.
- **Broadcast message or draft details with presence.** Rejected because private opaque events plus
  authorized re-query are sufficient and reduce accidental disclosure.
- **Create an Email-only category/tag registry.** Rejected because the existing Taxonomy domain owns
  reusable definitions; Email only needs account-scoped assignments and authorization.
- **Let `send` imply organize or access administration.** Rejected because sending from an approved
  shared identity does not justify changing folders, deleting mail, or managing other members.
- **Store personal rules in the browser only.** Rejected because background execution, API parity,
  audit, versioning, and consistent policy require server ownership.
- **Use Signal as the only rule engine.** Rejected because mailbox-local facts/actions and personal
  scope belong to Email; Signal remains an explicit cross-domain handoff.

## Follow-Up

- Implement this accepted decision through the parent RFC's ordered Feature Slices.
- In the first Feature Slice, inventory every current Email UI/API/query/action/job and prove account
  scope before enabling one personal account.
- Add account kind, explicit Ticket-ingress policy, owner/membership/delegation/grant records, and
  legacy account/rule review reports.
- Add the Admin shared-account membership editor with independent `view`, `organize`, and `send`
  controls plus effective-access preview and audit.
- Preserve current shared/system routing only for deliberately mapped accounts.
- Add separate policy classes/queries reused by web, API, jobs, rules, notifications, search, and AI.
- Test explicit personal read actions, distinct provider `Seen`, opened-by visibility, presence TTL and
  revocation, typing/draft conflicts, event payload privacy, and account-scoped Taxonomy assignments.
- Verify Ticket-originated sends use the same Email grants and guarded outbound pipeline under the
  dedicated related ADR without regressing current Ticket-reference correlation.
- Add Knowledge and an operational break-glass/offboarding runbook.
- Require a new explicit approval and ADR before implementing automatic external replies.
