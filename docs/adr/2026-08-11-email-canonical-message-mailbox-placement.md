# ADR: Canonical Email Messages And Mailbox Placements

Status: Accepted
Date: 2026-08-11
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Related ADR: `2026-08-11-email-conversations-as-ticket-communication-channels.md`

## Context

The current `email_messages` record stores both common message data and one account/mailbox/IMAP UID.
That model is sufficient for the current forward-only shared INBOX ingestion, but it cannot safely
represent the full client described in Discussion #38:

- the same delivered message may exist in several accounts or folders,
- provider folders have independent identifiers, cursors, UID validity, and capabilities,
- read/unread, flags, trash, follow-up, and sync state can differ by placement,
- a shared mailbox needs provider-shared state, manual per-user read state, durable opened-by history,
  and ephemeral reading/typing presence without treating those concepts as synonyms,
- drafts, outbound attempts, and sent items have different lifecycles from delivered inbound mail,
- categories and tags must reuse the existing Taxonomy definitions without leaking a classification
  between inaccessible account occurrences of a conversation,
- Message-ID may be absent, malformed, or reused and therefore cannot be a global unique key.

A full client must also converge with Outlook, phones, webmail, and other clients. A local database
copy that can move, mark, or delete messages without confirmed provider reconciliation would create a
second competing mailbox and is not the intended product.

The design needs one durable boundary before ownership, folder sync, rules, search, AI, and sending are
implemented in separate Feature Slices.

## Decision

### Canonical Message

Email owns one canonical message record for normalized content that is common across placements:

- normalized Message-ID, In-Reply-To, References, direction, participants, subject, and dates,
- sanitized HTML, normalized text, raw-source reference where retained, and content fingerprints,
- attachment references,
- conservative thread/correlation metadata,
- content-level provenance and explicitly captured evidence references that are valid for every
  verified placement; account-, audience-, and workflow-sensitive PSA relationships, including
  Ticket routing links, live on an account-scoped conversation or explicit evidence link instead,
- lifecycle/provenance fields required to distinguish inbound, draft, queued, sent, failed, and
  reconciled states without pretending they are the same event.

Message-ID is an indexed correlation fact, not a unique constraint. Canonical correlation uses a
conservative, explainable combination of normalized Message-ID, References/In-Reply-To, provider and
account evidence, participants, timestamps, direction, and content fingerprints. When evidence is
ambiguous, separate canonical messages are retained and flagged for review rather than merged.

Cross-account matches begin as correlation/link candidates. They are not physically merged merely
because Message-ID and subject match. BCC visibility, provider-added headers, personal/shared access
boundaries, body variants, and attachments must be equivalent before one canonical delivery variant
may own several placements. Otherwise separate canonical records may still belong to the same
permission-filtered thread.

### Mailbox Placement

Each provider/account occurrence is a mailbox placement linked to one canonical message. It stores:

- Email account and provider folder,
- provider/remote message identifier,
- IMAP UID and UID validity where applicable,
- remote/local sync version, cursor evidence, and last successful reconciliation,
- provider read/seen, answered, forwarded, draft, flag, category, trash/deleted, and other supported
  placement state,
- local archive/processed/follow-up state and provider capability/failure reason,
- idempotency/provenance required to apply or reconcile a requested move/state change safely.

Provider identity uniqueness is scoped to the provider contract. For IMAP it includes account,
folder, UID validity, and UID; an old UID namespace must never be reused silently after UIDVALIDITY
changes.

### Server-Authoritative Folders And Placement State

Provider folders are first-class Email records with provider identifiers, hierarchy, special-use
role, UID/cursor state, capabilities, visibility, and sync health.

For every personal, shared, or system account backed by IMAP or another external mailbox provider,
the provider is authoritative for folder hierarchy, message existence and placement, read/unread,
standard flags, drafts, trash/deletion, and sent-item placement where supported. Nexum stores a
synchronized projection and cache; it does not expose an alternative local-only mailbox truth.

Synchronization is bidirectional across all enabled folders. Provider changes arrive through
notifications/IMAP IDLE where supported and a required scheduled incremental reconciliation fallback.
Nexum-originated provider actions use idempotent operation records and remain visibly pending until
acknowledged or reconciled. If no authorized local operation is pending, confirmed provider state
wins. Conflicts and ambiguous UID/cursor transitions are recorded for retry, cancellation, or
re-baselining rather than silently overwritten.

Folder create/rename, message move/copy, read/unread, flags, trash/delete, drafts, and sent placement
are provider actions subject to advertised capabilities. A normal delete targets the provider's
Trash/Deleted folder; permanent delete is a distinct authorized action. IMAP moves may create a new
target UID, and UIDVALIDITY changes invalidate the old identity namespace, so reconciliation follows
provider evidence rather than reusing stale identifiers.

`Hybrid` means Nexum adds local metadata, links, rules, audit, and per-user work state while the
provider remains authoritative; it is not an alternative source-of-truth mode. `PSA managed` is
reserved for clearly identified synthetic/system records with no corresponding external mailbox and
is not presented as a normal IMAP account.

Storage mode independently controls whether content remains provider-only, is cached, or is archived.
It never widens access or changes provider authority implicitly. Cached mailbox content must be
rebuildable from the provider plus explicitly retained Nexum-owned metadata.

### User State

Per-user state is separate from provider placement state where the concepts differ. It may include:

- explicit `unread for me` / `read for me` state for an authorized placement or verified safe
  duplicate group,
- favorite/pin,
- local follow-up or snooze,
- personal display preference that is not a shared Taxonomy category,
- local dismissal of a suggestion.

Opening and rendering a message records an authorized durable opened-by fact for the current user and
may publish short-lived reading presence, but it never clears `unread for me` and never sets provider
`Seen`. Reads must use a provider operation such as IMAP `BODY.PEEK` that does not change remote state.
The user deliberately marks their personal state read or unread; this manual state remains independent
of another technician or external client changing provider-shared `Seen`.

The default read/unread command targets the selected message in the active account. It does not change
other messages in the conversation or correlated placements in other accounts. A separate
conversation/bulk command must preview and snapshot every currently authorized message and placement
in scope, reauthorize each account, and affect only that snapshot. Messages arriving after the
snapshot remain unread. Applying state across a verified safe duplicate group is therefore an
explicit multi-account operation, not an implicit consequence of opening or acknowledging one copy.

Per-user rows may be sparse, but their meaning is deterministic. Each user/account access grant has a
read baseline. An inbound placement first visible after that baseline is unread-for-me until explicit
acknowledgement even if provider `Seen` has changed; an older placement with no row is read-for-me.
A newly granted shared mailbox defaults its existing history behind the baseline, while an authorized
handover can preview and select an explicit unread backlog. Initial account onboarding and legacy
migration must record the chosen baseline/backlog. Missing state never falls back implicitly to the
current provider `Seen` flag.

Provider `Seen` is a separate mailbox-placement fact and a separate authorized provider action. It
requires the account's `organize` grant and remote acknowledgement/reconciliation. A UI command may
offer both explicit changes together, but it must describe both effects and persist/retry them as
separate operations. Durable opened-by history is auditable collaboration metadata; ephemeral
presence is not retained as reading history and expires automatically after heartbeat loss,
disconnect, navigation, or a short TTL.

### Threads

Threads are derived, explainable groupings. Correlation priority is:

1. validated References/In-Reply-To relationships,
2. normalized Message-ID relationships,
3. explicit Ticket/Sales or other guarded thread keys,
4. conservative participant/subject/time fallback only when safe.

Thread membership can be corrected without deleting canonical messages or mailbox placements. A
subject match alone never authorizes access or cross-client linking.

Thread identity and authorization are different concerns. Every thread row, participant, count,
snippet, message, attachment, opened-by entry, draft indicator, and live event is filtered against the
current user's accessible account placements. Correlation with an inaccessible message may improve an
internal thread key, but it must not disclose that the other message, account, or participant exists.

The default unified Inbox is a virtual permission-filtered projection of real provider Inbox
placements. One row represents one visible conversation only when it has at least one inbound Inbox
placement that is `unread for me`. Authorized Sent/Archive placements may provide conversation
context without qualifying the row. Subject, participants, snippets, counts, and reply identity are
computed after filtering every placement/message by current mailbox access.

Move, archive, trash, and delete remain placement/account actions even when the UI presents a
conversation. The active account is the default mutation scope. A multi-account action must enumerate
and reauthorize every placement and require explicit confirmation; conversation grouping never turns
several real IMAP accounts into one destructive target.

### Conversation Categories And Tags

Email reuses category and tag definitions from the existing Taxonomy domain. Email owns the
assignment, audit, and account/thread authorization for those definitions; it does not create a
second Email-local taxonomy registry.

Assignments are scoped to an Email account and conversation. They therefore remain consistent for
authorized users collaborating in one shared mailbox without silently classifying a correlated copy
in another personal or shared account. Category/tag metadata is Nexum work metadata, separate from
provider folders, flags, keywords, and labels unless a later explicit capability mapping is approved.
Conversation projections expose assignments only after account/thread authorization, and changes are
published as opaque invalidations rather than label names or message content.

Existing message-level Email tags remain message-scoped routing/history facts unless an explicit,
audited migration promotes a tag assignment to the account-scoped conversation. New APIs and rules
distinguish message tagging, conversation tagging, and primary conversation category; the legacy
`tag` action retains its message-level behavior during compatibility migration.

### Drafts And Sending

Drafts and outbound sends use explicit records/state transitions plus client-generated idempotency
keys. A send attempt snapshots authorized sender, recipients, content/attachment references, source
draft, linked context, and approval policy. Provider responses and sent placements are reconciled
before an ambiguous attempt can be retried.

Draft state is reconciled with the provider Drafts folder. A successful outbound send is reconciled
with the provider Sent folder, accounting for providers that automatically save a copy so Nexum does
not create duplicates.

A reply draft is scoped to its sender account and conversation. Authorized collaborators may see an
ephemeral `typing`/draft-in-progress indicator and a short-lived responder reservation, but presence
payloads never contain recipients, subject, body, attachment names, or quoted content. The draft and
send Actions reauthorize the account and conversation independently; stale/conflicting drafts or a
newly sent reply trigger a warning and revalidation rather than an unnoticed duplicate response.

Email's guarded outbound pipeline is the shared implementation for Email-owned composition and for
authorized callers such as Ticket. It owns Message-ID/References/In-Reply-To generation, idempotent
sending, and provider Sent reconciliation. Ticket retains ownership of its workflow and communication
timeline; the exact link between one Ticket and one or more account-scoped Email conversations is
decided in `2026-08-11-email-conversations-as-ticket-communication-channels.md`.

### Mail And Ticket Retention Boundary

Provider deletion removes the corresponding Mail placement and eventually any unretained Email cache
according to Email policy. It does not delete documentation already captured into Ticket through an
explicit guarded user action or an explicitly enabled Ticket-ingress policy.

Captured Ticket content is a separate Ticket-owned snapshot/evidence record with its own permissions,
retention, correction, and deletion rules. Merely polling, caching, indexing, previewing, or suggesting
a Ticket link does not create that durable copy. Email retains provenance and may show that the source
provider placement no longer exists without treating the Ticket snapshot as a live mailbox item.
Ticket conversation linking and outbound reply behavior are governed by the dedicated related ADR;
this retention boundary does not replace the existing Ticket-reference correlation contract.

### Migration

The first migration creates one placement for each existing `email_messages` row and preserves its
account, mailbox, IMAP UID, Ticket link, state, timestamps, and attachment relationships. It does not
deduplicate existing messages across accounts or rewrite provider/read state.

The provider baseline records all enabled folders, cursors, UID validity, placement state, cache
completeness, and outstanding operations without mutating the remote mailbox. Existing Ticket-linked
content is classified and preserved. Ambiguous legacy links are reported for review rather than
silently copied into Ticket or removed from either domain.

Canonical correlation first runs in shadow/report mode. Ambiguous and possible-duplicate groups are
reviewed before any merge/cutover; cross-account candidates stay separate by default. Existing
placement fields remain available during a bounded
compatibility period. Their removal requires parity tests, backfill verification, a rollback window,
human review, and a later forward migration.

## Rationale

- Common content can be indexed, summarized, linked, and retained once without losing provider state.
- Placement identity makes folder moves, read flags, trash, retries, and UID changes explicit.
- Server authority keeps Nexum, Outlook, phones, and webmail convergent instead of creating two
  competing postboxes.
- Separate user state prevents provider/shared flags from being confused with personal viewing state.
- Manual read state plus separate opened-by and ephemeral presence preserve follow-up intent while
  still showing who has inspected or is actively handling a conversation.
- The virtual unified Inbox can show one orderly conversation list without inventing a remote folder
  or making shared provider `Seen` a personal read receipt.
- Account-scoped Taxonomy assignments support shared classification without leaking or mutating a
  correlated occurrence in another mailbox.
- Separate Ticket capture preserves deliberately recorded PSA evidence without turning every cached
  message into permanent Ticket data.
- Conservative correlation avoids privacy and data-integrity failures from reused/missing Message-ID.
- Explicit draft/outbound state prevents duplicate sends and avoids treating an SMTP request as a
  confirmed sent message.
- An additive one-placement-per-existing-row migration provides a safe starting point and rollback.

## Consequences

Positive:

- Multiple accounts/folders can reference the same safe canonical content.
- Rules, search, AI, and PSA links can distinguish message-wide and placement-specific actions.
- Provider reconciliation and shared/personal state become testable.
- Historical records remain intact during migration.

Negative:

- Queries and authorization become more complex and require shared Email Queries/Actions.
- Provider writes need an operation ledger, pending/error UI, reconciliation workers, and conflict
  tooling; a local UI update alone is never sufficient proof of success.
- Unified conversation queries require placement-level authorization before metadata/count
  aggregation, and multi-account actions need explicit scope handling.
- Cached/archive retention must consider both canonical content and surviving placements/links.
- Thread/correlation corrections need audit and operator tooling.
- Opened-by history, shared draft coordination, and expiring presence add storage, privacy, cleanup,
  reconnect, and concurrency tests.
- Compatibility reads and background backfill temporarily increase code and operational load.
- Exact schema/index choices require live database review in the Feature Slice.

## Alternatives Considered

- **Keep one `email_messages` row per account/folder forever.** Rejected because common content,
  threads, AI, search, attachments, and PSA links would duplicate and drift.
- **Make Message-ID globally unique.** Rejected because real mail can omit or reuse it, and synthetic
  messages may be malformed.
- **Deduplicate all existing rows during the migration.** Rejected because false merges can disclose
  private mail or corrupt provider state and links.
- **Store only canonical state and derive provider state live.** Rejected because sync, offline provider
  failures, retries, auditing, and shared actions need durable placement evidence.
- **Let Nexum-owned folder/read/delete state override the server.** Rejected because other IMAP clients
  would drift and Nexum would remain an import copy rather than a real mail client.
- **Use provider read state as the only user read state.** Rejected because shared mailboxes and
  per-user awareness/preferences have different semantics.
- **Mark a message read merely because it was opened.** Rejected because users deliberately keep
  inspected work unread until they explicitly mark it handled/read.
- **Broadcast bodies or draft content to implement presence.** Rejected because opaque scoped events
  and authorized re-query provide coordination without disclosing message content in the event layer.
- **Attach Taxonomy assignments to a cross-account canonical message.** Rejected because one user's
  private or unrelated mailbox occurrence must not inherit a shared account's work classification.
- **Treat a conversation as one mutable provider object.** Rejected because its placements may belong
  to different real accounts, folders, grants, and provider identities.
- **Delete captured Ticket documentation when the provider message disappears.** Rejected because an
  explicitly captured Ticket record has separate operational, audit, and retention meaning.
- **Copy canonical content into every user state.** Rejected because it duplicates sensitive data and
  complicates retention/revocation.

## Follow-Up

- Implement this accepted decision through the parent RFC's ordered Feature Slices.
- Inspect authoritative Dev schema, indexes, row counts, duplicate candidates, and provider behavior
  before naming tables/foreign keys in the Feature Slice.
- Implement account ownership/authorization before exposing personal placements.
- Deliver additive folder/placement structures, shadow correlation, compatibility queries, and
  migration verification before broad UI cutover.
- Prove bidirectional all-folder sync, remote operation acknowledgement, scheduled reconciliation,
  provider-wins conflict handling, and Mail/Ticket deletion isolation before broad UI cutover.
- Add tests for no-read-on-open, explicit personal read state, separate provider `Seen`, opened-by
  authorization, presence expiry, shared-draft conflict warnings, and account-scoped Taxonomy labels.
- Implement Ticket conversation linkage only with the dedicated related ADR and preserve the existing
  Ticket-reference correlation path during migration.
- Add a separate ADR if the selected search/index engine creates a durable external dependency.
- Keep raw-source and attachment retention under explicit security and data-class policy.
