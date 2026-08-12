# ADR: Email Owns The Full Mail Client Domain

Status: Accepted
Date: 2026-08-11
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Related ADR: `2026-08-11-email-conversations-as-ticket-communication-channels.md`
GitHub Discussion: https://github.com/SveinT83/Nexum-PSA/discussions/38

## Context

Nexum already has a singular `Email` module that owns account configuration, inbound IMAP polling,
stored messages and attachments, the technician Inbox, Email rules, templates, account health,
SMTP account sending support, and the Email Inbox API.

Discussion #38 uses **Mail** as the product name for a finished Outlook-style client. The original RFC
left one architecture question unresolved: extend the existing `Email` domain, create a separate
`Mail` domain for the client while Email keeps transport, or split the capability another way.

A new personal Rules & AI proposal increases the importance of one clear owner. Accounts, folders,
messages, drafts, sending, mailbox permissions, rules, AI suggestions, and provider synchronization
must use the same authorization and idempotency contracts. Splitting them across overlapping Mail and
Email modules would make data ownership, routes, API actions, retries, and audits ambiguous.

The boundary must also acknowledge current implementation reality. Password-based IMAP/SMTP secrets
are encrypted and stored directly on `email_accounts`, and Email services use them today. Integration
already owns broader provider, OAuth, API ability, AI provider/model, data-egress, governance,
workload, and usage/cost concerns. Moving existing secrets is real migration work, not a boundary that
can be declared complete by documentation alone.

The finished workspace must use the project's existing Livewire 3 stack and update automatically when
provider synchronization commits new mailbox state. Shared addresses remain real IMAP/SMTP accounts
configured under Admin; the user's unified Inbox is an authorized Email-owned projection across every
real account the user may view.

## Decision

### One Email Domain

The existing singular `Email` module remains the sole code and data domain for the full mail client.
No parallel `Mail` module will be created.

The user-facing workspace may be labelled **Mail**, and `/tech/mail` may become the canonical product
route after a complete tested surface exists. Code remains in `app/Modules/Email`, and current
`/tech/inbox` routes remain compatible until an explicit migration/redirect slice is delivered.

Email owns:

- mailbox account purpose, ownership, personal/shared/system kind, real shared provider-account Admin
  configuration, and enabled processing modes,
- per-mailbox membership, delegation, break-glass record, and operation grants,
- provider-neutral mailbox capabilities and operations,
- folders, synchronization state, canonical messages, mailbox placements, threads, drafts, outbox,
  sent/trash state, and message/attachment safety,
- explicit per-user read state, durable opened-by facts, ephemeral reading/typing presence, shared
  draft coordination, and account-scoped conversation category/tag assignments,
- read, organize, compose, send, reconcile, search, and conversation actions, including the guarded
  outbound pipeline used by authorized cross-domain callers such as Ticket,
- module-owned Livewire Mail UI, Email-owned API controllers/resources/routes, private live-update
  invalidation events, and domain authorization,
- deterministic Email rules, rule versions, previews, execution attempts, retries, and Email-local
  audit,
- typed Mail AI suggestions and accepted-action handoff after Integration policy evaluation,
- links from mail records to records owned by other domains.

### Credential And Provider Boundary

Integration owns:

- new reusable provider connections,
- OAuth/client registration and token lifecycle,
- shared provider secret governance,
- provider/model/agent/workload configuration for AI,
- the central AI data-egress policy and privacy gateway,
- AI governance, access metadata, usage/cost telemetry, and the shared API ability catalog.

Email references the applicable Integration connection and owns the normalized mailbox behavior that
uses it. A provider adapter cannot widen Email mailbox authorization.

Existing encrypted IMAP/SMTP credentials on `email_accounts` remain a supported compatibility path
until a dedicated Feature Slice introduces an Integration-owned credential/connection reference,
backfills it safely, verifies runtime parity, provides rollback, and later removes obsolete secret
fields. No first-slice migration may break current Email polling or SMTP callers merely to make the
target boundary look complete.

### Authorization Boundary

UserManagement owns users and global role permissions. Email owns per-mailbox grants and the
intersection between global ability, account grant, requested operation, linked-record authorization,
and current account policy.

Account administration does not imply content access. Personal content is owner-only by default;
delegation and break-glass access are explicit and audited. Shared/system access is grant-based.
Integration token abilities are request ceilings and never replace Email's account/record policy.

Shared accounts expose independent `view`, `organize`, and `send` grants. `view` may change only the
current user's local view state through an explicit action; merely opening content records an
authorized opened-by fact but does not mark the message read. Provider `Seen` remains separate and
requires `organize`, and the account may appear as a sender only with `send`. Narrower permissions
protect raw source, permanent deletion, rule publication, access administration, and operator
actions.

### Mail Workspace And Live-Update Boundary

Email owns the Livewire 3 components for `/tech/mail`, including the virtual unified `unread for me`
conversation Inbox, account/folder navigation, reading pane, and targeted refresh behavior. Livewire
does not own domain logic: it calls the same Email Actions, Queries, and policies as the API and jobs.

Provider sync commits durable mailbox projection state before Email publishes a versioned
invalidation. Private broadcasts contain opaque identifiers/change versions only; Livewire re-queries
authorized data. Reconnect/version catch-up and a visibility-aware automatic fallback preserve
correctness when transient events or the primary push connection are unavailable. No manual browser
refresh is required for normal operation.

Opened-by history is a durable Email fact distinct from manual personal unread and provider `Seen`.
Reading and typing presence plus responder reservations are ephemeral, heartbeat/TTL-bound, and
private to users who may currently view the same account-scoped conversation. Presence and
invalidation payloads never contain addresses, subject, snippets, body, attachment names, draft
content, categories, or tags. Subscription and re-query both reauthorize account/thread access;
access revocation must not leave a user subscribed to future events.

Shared reply drafts remain Email-owned, account- and conversation-scoped records. Draft/reply edits
require the applicable `view` and `send` grants; presence may identify the active drafter without
broadcasting draft content. A concurrent reply or stale draft is surfaced for revalidation rather
than silently sent.

This ADR fixes the UI/domain boundary but does not preselect a new broadcast package. The transport
receives a separate slice-level ADR and operational review because authoritative Dev currently has
Livewire 3 but no Echo/Reverb/WebSocket stack. A transport choice may not introduce a second Alpine
runtime or bypass Email authorization.

### Cross-Domain Boundary

- Contact, Clients, and Relationship own identity and organization/vendor records.
- Taxonomy owns reusable category and tag definitions. Email owns their account-scoped conversation
  assignments, assignment audit, and mailbox authorization; assignments do not implicitly become
  provider folders, keywords, or labels.
- Ticket, Sales, Task, Calendar, and other domains own their guarded writes and workflows. Ticket owns
  Ticket lifecycle, internal notes, timeline/evidence presentation, and communication-channel links;
  Email owns real mailbox conversations, sender authorization, outbound email, threading metadata,
  and provider Sent reconciliation.
- Signal owns normalized cross-domain events and automation after explicit `emit_signal` handoff.
- Notification owns notification channels, preferences, and delivery.
- Documentation/file-provider domains own a durable external file only after an authorized save/link
  handoff; Email owns the original attachment reference and access decision.
- Report and future Intelligence own aggregate/derived presentation, not copied private mail content.

Email and Mail AI may propose or request a cross-domain action only through the target domain's public
guarded action. They do not write around that domain's permission, workflow, or idempotency contract.

Ticket and other authorized callers send email through Email's public guarded compose/send Actions;
they do not implement a second SMTP/outbox pipeline. Email reauthorizes the selected account, sender,
recipients, conversation, attachments, and idempotency request, then reconciles the result into the
real Sent mailbox. How existing Ticket-reference correlation and multiple Email conversations attach
to one Ticket is defined by `2026-08-11-email-conversations-as-ticket-communication-channels.md`.

### Rule And AI Boundary

Email rules remain Email-owned because they act on mailbox-local facts and placements. They may emit
Signals explicitly but are not stored or executed as Signal rules. Email adopts the proven immutable
attempt/idempotent recovery principles from Signal without creating one generic shared rule engine.

Mail AI uses explicit Integration-managed structured workloads with general write tools removed.
Integration decides whether/how authorized data may leave; Email decides what Mail data means and
whether an accepted typed result may be applied. AI never becomes a second domain owner or an
unscoped mailbox actor.

## Rationale

- Existing Email records, services, routes, and tests already form the closest source of truth.
- One domain avoids duplicate account/message models and circular Mail-versus-Email dependencies.
- A single action/policy layer keeps UI, API, jobs, rules, and AI behavior consistent.
- Livewire can provide the requested no-refresh workspace while opaque invalidations and authorized
  re-query keep mailbox content out of broadcast payloads.
- Manual read, durable opened-by history, and ephemeral presence can coexist without treating an IMAP
  flag or opening the reading pane as proof that a technician finished handling the message.
- Existing Taxonomy definitions can classify shared conversations while Email enforces the placement
  and account boundary for assignments.
- Product wording can improve without renaming namespaces, migrations, permissions, and integrations
  prematurely.
- Integration remains the correct shared boundary for secrets/OAuth and AI/provider governance while
  Email retains domain behavior.
- Explicit legacy credential migration prevents a documentation-only architecture claim from hiding
  runtime and rollback risk.
- Signal, Notification, Contact, Ticket, Sales, Task, and other modules keep established ownership.

## Consequences

Positive:

- Contributors have one place to add and test mail behavior.
- Personal/shared authorization can be enforced uniformly across UI, API, search, rules, jobs, and
  AI.
- Existing inbound ingestion, ticket routing, provider health, and templates can be evolved rather
  than duplicated.
- New provider and AI connections can use shared Integration governance.
- `Mail` remains available as clear user-facing terminology without a destructive domain rename.

Negative:

- The Email module becomes larger and needs disciplined Actions, Queries, policies, provider
  contracts, and internal subfolders to stay maintainable.
- Existing encrypted credentials require a staged migration before the target Integration boundary is
  fully realized.
- Product route/label compatibility must be maintained while `/tech/inbox` and `/tech/mail` coexist.
- True push delivery introduces a supervised long-running transport/reverse-proxy concern plus a
  required automatic fallback and reconnect contract.
- Email tests must cover more modes and cross-domain contracts.
- Presence, shared drafts, opened-by history, and account-scoped classification add expiry,
  concurrency, authorization, audit, and privacy obligations.
- Reusing architectural principles from Signal without sharing its runtime requires deliberate
  consistency checks.

## Alternatives Considered

- **Create a separate `Mail` module and keep Email as transport.** Rejected because mailbox accounts,
  folders, messages, drafts, sending, rules, and provider state would span two owners and duplicate
  authorization/audit boundaries.
- **Rename `Email` to `Mail` immediately.** Rejected because it creates broad route, namespace,
  migration, permission, documentation, and integration churn without changing product capability.
- **Put all mail behavior in Integration.** Rejected because Integration should govern connections,
  secrets, AI, and external boundaries; it should not own email meaning or user workflows.
- **Move Email rules into Signal.** Rejected because mailbox-local parsing/placement belongs to Email
  and the approved Signal boundary requires explicit handoff rather than universal event creation.
- **Create a generic Communication domain for all messages first.** Rejected because it would delay
  beta completion and force email-specific synchronization, drafts, provider state, and permissions
  into an abstraction that has not been proven by another channel.
- **Let Ticket send mail through a separate transport.** Rejected because it would duplicate sender
  authorization, threading, idempotency, outbox, provider reconciliation, and audit behavior.
- **Store categories and tags inside Email definitions.** Rejected because Taxonomy already owns the
  reusable definitions; Email owns only its scoped assignments and behavior.
- **Leave credentials permanently split without a target boundary.** Rejected because OAuth/provider
  governance and secret lifecycle would drift. Compatibility is temporary and must have a reviewed
  migration path.

## Follow-Up

- Implement this accepted decision through the parent RFC's ordered Feature Slices.
- Implement personal account ownership, ingress isolation, mailbox grants, and legacy rule scoping as
  the first Feature Slice on authoritative Dev.
- Define the Integration connection reference and legacy credential migration in the provider slice,
  including backfill, compatibility reads, secret-safe logs, runtime parity tests, and rollback.
- Keep all new Email routes in `app/Modules/Email/routes.php` and Email API routes in the module-owned
  API file loaded by the existing API entry point.
- Build the workspace with the existing Livewire 3/Alpine runtime and shared Email Actions/Queries;
  do not embed mailbox authorization or provider mutation rules only in a component.
- Add privacy and concurrency tests for no-read-on-open, explicit read state, provider `Seen`,
  opened-by history, presence expiry/revocation, shared replies, and opaque event payloads.
- Reuse Taxonomy definitions through Email-owned account/conversation assignments and require the
  dedicated Ticket communication-channel ADR for Ticket linkage and outbound integration.
- Add an ADR later if the selected search engine, provider adapter framework, real-time transport, or
  canonical-message migration creates a durable architecture decision not resolved here.
