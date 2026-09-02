# RFC: Email Full Client, Personal Mailboxes, Rules And AI

Status: Approved
Date: 2026-07-04
Last updated: 2026-09-02
Owner: Svein / Codex
Level: 3
GitHub Discussion: https://github.com/SveinT83/Nexum-PSA/discussions/38
Architecture decisions:

- `../adr/2026-08-11-email-owned-mail-client-domain.md`
- `../adr/2026-08-11-email-canonical-message-mailbox-placement.md`
- `../adr/2026-08-11-email-mailbox-access-and-rule-authority.md`
- `../adr/2026-08-11-email-conversations-as-ticket-communication-channels.md`
- `../adr/2026-09-01-email-account-owned-imap-smtp-configuration.md`

## Context

GitHub Discussion #38 defines the finished direction for an Outlook-style mail client inside Nexum.
The core principle is still **Mail is Mail**: technicians must be able to read, send, reply, forward,
search, organize, move, delete, and otherwise manage email as email. Ticket, Client, Contact, Sales,
Task, Signal, and other PSA behavior should add context and safe automation without reducing Mail to
a ticket intake queue.

The discussion was moved to `In review` on 2026-08-11. A new discussion comment also proposes a
personal **Rules & AI** workspace for each technician and account, an API-first rule engine, message
and thread summaries, entity matching, reply drafts, supervised inbox cleanup, and a later controlled
automatic-reply capability.

Historical issue #121 described the first practical slice, personal account ownership and technician
inbox scope. The issue is closed as `not planned` because it was created during idea triage before the
parent RFC was ready. It remains useful source material but is not implementation authority. The
first scoped Feature Slice now replaces that issue as the implementation contract when work starts.

This is Level 3 work. It affects the Email data model, mailbox authorization, API contracts, provider
integration, IMAP synchronization, SMTP sending, background jobs, search, attachments, rules,
AI/privacy policy, cross-module actions, and substantial technician/admin workflows.

## Goals

- Finish one full mail client inside Nexum, using the existing Email domain as owner.
- Support multiple personal accounts per technician alongside shared, ticket, alert, and other
  admin-managed accounts.
- Keep personal mailbox content private by default and shared mailbox access explicit per account.
- Preserve current shared/ticket intake behavior while personal mailbox support is introduced.
- Support provider-neutral account drivers, starting with the existing IMAP/SMTP path and leaving
  Microsoft 365, Google Workspace, and other drivers as later slices.
- Make every external mailbox a real bidirectional provider client: the mail server is authoritative
  for folders and standard mailbox state, while Nexum keeps a rebuildable cache, operation ledger,
  search index, local work metadata, and explicitly captured PSA records.
- Deliver the Mail workspace as a module-owned Livewire 3 surface that updates automatically from
  committed mailbox events without requiring a manual page refresh.
- Default each technician to one permission-filtered conversation view of Inbox mail across every
  real external account they may view, limited to messages that are `unread for me`.
- Keep opening/viewing separate from manually acknowledging mail: opening records who has viewed and
  may show live reading presence, but only an explicit action changes `unread for me` or provider
  `Seen` state.
- Support folders, read/unread state, flags, drafts, sent items, trash, conversations, attachments,
  search, signatures, and mailbox-specific metadata.
- Reuse the existing Taxonomy-owned category and tag definitions for account-scoped Email
  conversation classification instead of creating a parallel Email taxonomy.
- Let one Ticket collect several distinct account-scoped Email conversations while preserving each
  conversation's participants, reply path, provider placement, and current Ticket-number routing.
- Make Mail queries and actions API-first so the web UI, PWA, controlled automation, and future
  clients use the same authorization and business operations.
- Provide deterministic personal and shared-mailbox rules with validation, preview, versioning,
  execution history, idempotent reprocessing, and reversible actions where possible.
- Add AI summaries, classification, Nexum entity matching, and reply drafts through the existing
  Integration-owned AI governance and data-egress gate.
- Keep AI behavior visible and inspectable. Repeated user behavior may produce an `Always do this`
  suggestion, but accepting it creates a normal versioned rule rather than hidden learned behavior.
- Keep automatic external sending off by default and behind a separate, explicitly approved
  high-risk Feature Slice.
- Preserve Email, Ticket, Signal, Contact, Notification, Integration, and other domain ownership
  boundaries.
- Deliver the target in independently testable Feature Slices without describing the first slice as
  the finished product.
- Make password-based IMAP/SMTP account setup as direct as an ordinary mail client: one Email account
  form owns the mailbox address, incoming and outgoing server settings, usernames and replaceable
  passwords. Saving runs one connection check and reports IMAP and SMTP separately.
- Keep provider lifecycle, credential versions, cutover, staging, and compatibility migration
  terminology out of ordinary Email administration.

## Non-Goals

- Do not create a second `Mail` domain beside the existing `Email` domain.
- Do not implement the full target as one change or one pull request.
- Do not replace Email rules or Ticket rules with Signal rules.
- Do not move OAuth client registration, shared third-party provider governance, or AI provider
  governance out of Integration. Ordinary password-based IMAP/SMTP mailbox settings are not a
  reusable Integration product: they belong to the Email account that uses them.
- Do not require a separate provider record, provider activation, credential-version workflow,
  migration preview, cutover, or replacement provider to create or repair an IMAP/SMTP account.
- Do not give an administrator content access to personal mailboxes merely because the administrator
  can configure accounts or inspect connection health.
- Do not let a personal rule override mandatory security, retention, routing, or compliance policy.
- Do not permit AI to gain mailbox access, tools, or write authority that the authenticated actor and
  selected account do not already have.
- Do not silently train tenant-wide or cross-client behavior from technician actions.
- Do not automatically send AI-generated external mail as part of the summary/draft slices.
- Do not automatically permanently delete mail. Safe cleanup uses reversible folder, archive, tag, or
  category operations unless a later policy explicitly authorizes more.
- Do not introduce an offline mail cache or offline write queue in the PWA without a separate approved
  conflict, encryption, retention, and device-loss design.
- Do not add PST, OST, or MBOX import in the initial delivery plan.
- Do not build a customer-facing mail client, mail merge, gateway rule synchronization, or every
  provider driver under this RFC's first slices.
- Do not replace the existing `TD-...` Ticket-number subject/reference correlation contract;
  standards-based threading and durable conversation links extend it additively.
- Do not expose supplier, partner, other-provider, or other third-party correspondence in the customer
  portal merely because its Email conversation is linked to the same Ticket.

## Current Behavior

The following state was checked against the local planning copy and the registered `origin/Dev`
snapshot on 2026-08-11, then confirmed against the matching clean authoritative
`/var/Projects/tdPSA` working copy on 2026-08-12 (`Dev` at `ef5efbf`). Fresh runtime/schema
revalidation remains a mandatory first-slice gate before implementation:

- `app/Modules/Email` already owns account configuration, inbound ingestion, stored messages,
  attachments, templates, account health, the technician Inbox, Email rules, and the Email Inbox API.
- `EmailAccount` has encrypted IMAP/SMTP secrets and workflow default scopes but no account owner,
  account kind, or per-user mailbox grants. Existing accounts are effectively global/shared.
- `/tech/inbox` lists all unrouted `EmailMessage` rows across accounts. The controller and API
  do not have a personal-account visibility boundary because no ownership model exists.
- Automatic IMAP polling is forward-only for INBOX, tracks UID validity/live cursors, and stores
  messages idempotently by account, mailbox, and IMAP UID. Explicit historical import and operator
  cursor recovery remain separate planned work.
- `email_messages` currently combines message content with one account/mailbox/UID placement. It does
  not yet represent one canonical message with several mailbox placements.
- The technician Inbox can list, search, open, mark spam, download allowed attachments, poll, and
  locally or server-delete according to account policy. It is not a complete mail client.
- There is no full folder synchronization, mailbox tree, user-owned read state, move/archive UI,
  drafts, sent-item synchronization, normal inbox composer, reply/forward flow, shared draft lock,
  conversation preference, or personal mail settings.
- Email rules are global and admin-managed. They support ordered inbound/preclassification phases,
  active/stop-processing state, deterministic conditions, ticket linking/creation, archive, tag, and
  explicit Signal handoff. Logs record a matched rule/action snapshot, but rules are not account- or
  user-scoped and do not yet have a full preview/version/retry/undo model.
- The current API exposes unrouted message list/show, mark-spam, and poll operations under broad
  `email.read` and `email.update` abilities. It does not expose accounts, folders, standard message
  actions, drafts, sending, rules, previews, or execution history.
- Email remains ticket-first for unmatched inbound shared-mailbox messages. Known client contacts can
  become support tickets, unknown senders can become lead tickets, and selected Email rules can emit
  Signals. This behavior must not be applied blindly to personal inboxes.
- Current Ticket linking is message-level through `email_messages.ticket_id`: linked rows are filtered
  from the technician Inbox and its API/read/attachment paths reject them. Create/link actions copy
  message-level Email tags into Ticket; `Mark as not Ticket` clears the link, adds suppression
  tag/rule behavior, returns the row to the Inbox model, and soft-deletes the generated Ticket;
  Ticket merge moves message-level Ticket links without a specific same-client/site guard. These are
  explicit compatibility contracts and risks to migrate, not behavior the conversation model may
  silently discard.
- The approved organization-controlled AI RFC and central data-egress ADR define an Integration-owned
  privacy gate, but the required implementation is still a prerequisite rather than infrastructure
  Mail may assume is complete. No Mail AI slice may process mailbox data externally until that gate,
  provider governance, usage telemetry, and their human-review requirements are implemented and
  verified; Mail cannot build a bypass.

## Proposed Change

### 1. Domain And Product Ownership

The existing singular `Email` module remains the domain and code owner for the complete mail client.
The technician-facing product may be labelled **Mail**, and `/tech/mail` may become the canonical
workspace URL when the surface is real, but controllers, actions, queries, models, policies, views,
API resources, jobs, and routes remain under `app/Modules/Email`.

The existing `/tech/inbox` route must remain stable until a tested redirect or compatibility route is
introduced. No navigation or control may advertise `/tech/mail` before its underlying workflow is
implemented.

The accepted ADR set records this ownership decision, the canonical message/placement boundary, the
mailbox authorization/rule authority contract, and Email conversations as Ticket communication
channels.

### 2. Mailbox Kinds And Account Ownership

The finished account model supports these product concepts:

- **Personal:** owned by one technician. A technician may own several active accounts.
- **Shared:** a real provider-backed IMAP/SMTP account configured under Admin and operated by
  explicitly granted users or groups, such as support or sales.
- **System/PSA managed:** used for ticket intake, alerts, automated sending, and other system flows.

The implementation may represent shared and system purpose through an account kind plus existing
workflow scopes. It must keep these invariants:

- Existing accounts migrate to a safe shared/system-compatible state; no existing account becomes
  personal automatically.
- A personal account has exactly one user owner.
- Personal accounts default to `ticket_ingress_enabled = false`. They do not run the shared-mailbox
  fallback that turns every unmatched inbound message into a Ticket or lead Ticket.
- Existing global rules are never inherited implicitly by a new personal account. Every
  organization/shared rule has an explicit account scope, and any rule whose legacy scope cannot be
  resolved is disabled for review rather than widened.
- A nullable owner never means public access. Access comes from account kind, role permission, and an
  explicit mailbox grant or system workflow policy.
- Account administration and content access are separate capabilities.
- Admin assigns shared-account membership and the primary `view`, `organize`, and `send` grants
  independently. Configuring or testing the provider connection does not grant content access.
- A shared account's provider credentials and server state remain account-owned; a user's unified
  inbox is a virtual authorized view and never a replacement IMAP account or server folder.
- Ticket ingress, account rules, and inclusion in background workflows are separate explicit account
  policies and are not implied by shared membership.
- Disabling or removing a user must disable or transfer their personal account deliberately; it must
  not silently convert it to a shared account.
- One technician can choose a default personal sender while workflow scopes continue to resolve
  system/shared senders.

Every personal, shared, or system account backed by IMAP or another external mailbox provider is
**provider authoritative** for remote folders, message presence and placement, provider read/unread
state, standard flags, drafts, trash/deletion, and sent-item placement where the provider supports
them. Nexum's separate per-user `unread for me` and opened-by facts remain Nexum-owned collaboration
state.
Nexum does not offer a mode in which the database silently becomes the competing mailbox truth.

`Hybrid` describes additional Nexum-owned metadata such as PSA links, rule history, per-user viewed
state, snooze, and suggestions; it is not an alternative source-of-truth mode. `PSA managed` applies
only to clearly identified synthetic/system records without a corresponding external mailbox and is
not presented as a normal IMAP account.

Storage mode remains independent of ownership and provider authority. It may be provider-only,
cached, or separately archived under an approved retention policy. Every mode defines supported
operations, retention, cache eviction/rebuild, and rollback. Unsupported combinations stay
unavailable in the UI.

### 3. Authorization And Privacy Boundary

Every UI, API, job, notification, search result, attachment, and AI request must resolve the same
effective mailbox access decision. The most restrictive result of these layers wins:

1. Authenticated user or service identity and global Email ability.
2. Per-account owner, membership, delegation, or system-workflow grant.
3. Operation-specific mailbox grant, including the primary `view`, `organize`, and `send` levels plus
   narrower high-risk or administrative abilities.
4. Work Context and linked-record authorization when PSA context is requested.
5. Integration's AI data-egress decision when data is sent to an AI workload/provider.

Personal mailbox defaults:

- Only the owner can read message content and attachments.
- Administrators with account-management access may configure connection settings and inspect
  sanitized health without automatically reading content.
- Another person can access content only through an explicit delegation or a time-bounded,
  reason-bearing, audited break-glass grant protected by a distinct permission.
- Search counts, snippets, notifications, AI context, logs, and side panels must not leak the existence
  or content of inaccessible mail.

Break-glass is an emergency access path, not an administrator convenience. It is bound to named
accounts, operations, actor, reason, and a short expiry; it can be revoked immediately and cannot be
renewed silently. The mailbox owner and designated security/operations recipient are notified without
undue delay, except where a documented legal or incident-response restriction requires delayed owner
notice. Every activation and sensitive follow-up action, including raw-source access, attachment
download, bulk search, and export, creates a metadata-only audit entry visible only through a separate
audit permission and receives post-event review. Ordinary break-glass activation does not require a
second approver because it must remain usable during an emergency; bulk export and separately defined
high-risk actions may require an additional approval policy.

Disabling a user or removing an account grant revokes new UI/API access, private event delivery,
presence, drafts, personal rules/delegations, provider tokens owned by that user, and queued work whose
execution still depends on that authority. Every send and provider mutation reauthorizes at execution;
no queued external send proceeds after the actor or bound system policy loses `send`. Shared/system
rules continue only when they are account/organization-owned and their current policy remains valid.

Shared mailbox defaults:

- Membership is explicit and does not imply all operations.
- `view` permits account/folder discovery, safe message/attachment reading, search, and personal
  `unread for me`/view state. Opening content records an authorized per-user `opened by` receipt and
  may publish short-lived reading presence, but does not itself change personal unread state or
  provider `Seen`. `view` alone never changes flags, folders, placement, or trash.
- `organize` adds provider read/unread, flags, folder operations, move/copy, archive, and normal trash.
  A user-facing organize action also requires `view`; the grant alone does not disclose content.
  Permanent deletion and other destructive administration remain separately guarded.
- `send` permits new compose using that account's real provider/SMTP identity without implicitly
  granting Inbox/Sent access. Reply and forward require both `view` of the source and `send`. `Send
  as` or `send on behalf` is shown only where provider capability and the effective grant allow it.
- Raw source, rules publication, access management, poll/import/re-baseline, audit, and break-glass
  operations remain narrower advanced permissions rather than hidden effects of the three primary
  grants.
- Shared mailbox locks coordinate mutating work but never replace authorization.
- Taxonomy definitions retain Taxonomy permissions. Assigning an existing shared category or tag to
  an Email conversation is an Email organize operation and never grants access to that conversation.

API token abilities remain a request ceiling, not mailbox authorization. Service/workload tokens must
be bound to explicit accounts and operations; `email.read` must never mean every personal mailbox.
Manual poll/re-baseline/import actions likewise operate only on accounts the actor may administer;
scheduled system polling uses the account's explicit enabled processing policy rather than user UI
visibility.

### 4. Canonical Messages And Mailbox Placements

The target data boundary separates common message content from provider/account-specific state:

- A canonical message stores normalized headers, safe body representations, thread/correlation data,
  attachment references, direction, and content-level provenance that is safe for every verified
  placement. Account-, audience-, and workflow-sensitive PSA relationships do not live on the
  cross-placement canonical row.
- A mailbox placement stores account, provider folder/identifier, UID and UID validity where relevant,
  provider read/unread state, flags, provider labels/keywords where supported, deleted/trash state,
  follow-up state, and sync metadata.
- An account-scoped Email conversation groups the messages that form one reply chain for one real
  mailbox account. Cross-account correlation may present related copies together, but it does not
  collapse their conversation identity, access boundary, taxonomy, or Ticket routing.
- Per-user message/conversation state stores manually acknowledged `unread for me` state and durable
  `opened by` receipts independently from provider `Seen`. Live reading and typing presence is
  short-lived coordination state, not message history or provider state.
- Taxonomy owns category/tag definitions and hierarchy. Email owns account-scoped conversation
  assignments: one primary Email category and zero or more Email tags. Provider labels and IMAP
  keywords remain separate unless an approved provider mapping explicitly connects them.
- A durable Email-conversation/Ticket link records provenance, linking actor/reason, portal visibility,
  correlation evidence, and which single Ticket, if any, is primary for automatic routing.
- One canonical message may have several placements only when the same normalized delivery variant is
  verified safely. Cross-account matches start as correlation/link candidates, not physical content
  merges; BCC, provider-added headers, personal/shared privacy boundaries, and attachment differences
  can make two copies materially different.
- Drafts and outbound messages have explicit lifecycle states and idempotency keys; a draft is not
  treated as a delivered inbound message.

Message-ID is a correlation input, not a sufficient global unique key. Missing, reused, or malformed
Message-IDs must not merge unrelated mail. Correlation should combine normalized Message-ID,
In-Reply-To/References, account/provider evidence, participants, timestamps, and content fingerprints
under conservative rules. An unverified cross-account candidate remains separate even when the
Message-ID matches.

The exact table names may be refined in the data-foundation Feature Slice, but these ownership and
isolation invariants may not be weakened.

### 5. Provider And Synchronization Contract

Email defines provider-neutral operations for:

- account capability discovery,
- folder discovery and synchronization,
- incremental message synchronization,
- body and attachment retrieval,
- read/unread and flag updates,
- folder create/rename/delete and message move/copy/archive/trash/delete operations where supported,
- draft save/update/delete,
- send and sent-item placement,
- quota, cursor, authentication, and health state.

For an external mailbox, the provider is the authoritative source for its folder hierarchy, message
existence and placement, and provider-supported state. Nexum's database is a synchronized local
projection and cache, not an independent copy whose state may drift permanently from the server.
Mail content may still be cached for responsive reading, authorized search, rules, AI, and PSA
context, but the mailbox projection must be rebuildable from the provider plus explicitly retained
Nexum-owned metadata.

Synchronization is bidirectional across all enabled provider folders, not only forward polling of
INBOX:

- Provider changes made by Outlook, a phone, webmail, or another client are discovered and reconciled
  into Nexum through provider notifications/IMAP IDLE where supported plus a scheduled incremental
  reconciliation fallback.
- Nexum read/unread, flag, folder create/rename, move/copy, archive, trash/delete, draft, and sent-item
  actions are submitted to the provider through an idempotent operation ledger. The UI may update
  optimistically only while showing a pending state; it may never report remote success before
  acknowledgement or reconciliation.
- Opening or previewing a message uses a non-mutating provider/body retrieval path such as IMAP
  `BODY.PEEK`. It records the authorized user's durable `opened by` receipt but never automatically
  clears `unread for me` or queues provider `Seen`, regardless of reading-pane duration.
- `Merk som lest` is an explicit acknowledgement of the selected message in the active account. For
  an actor with `organize`, it marks that message read in the actor's personal state and queues
  provider `Seen` for its active-account placement; it does not acknowledge other messages in the
  conversation or correlated copies in other accounts. Other users' personal `unread for me` state
  remains unchanged. A view-only personal-state action, where offered, is explicitly labelled `for
  meg` and cannot mutate the provider. Provider mark-unread likewise requires `organize` and does not
  silently make every user's personal state unread.
- A separate bulk or `Merk samtalen lest` action must preview and snapshot the currently authorized
  messages and account placements it will change, apply only to that snapshot, and reauthorize every
  account/placement. It never acknowledges later arrivals; a new message in the same conversation is
  `unread for me` until the user explicitly handles it. Correlated copies in another account require
  an explicit multi-account selection rather than inheriting the active-account action.
- Per-user read state has a deterministic grant baseline. A new inbound message that first becomes
  visible after the user's account-access baseline starts as `unread for me`, regardless of provider
  `Seen`. A new shared-mailbox grant defaults existing history to read-for-me so it does not flood the
  unified Inbox; an authorized handover may explicitly preview and select an existing backlog as
  unread. Initial account onboarding and legacy migration must preview and record their baseline and
  any imported backlog. A missing per-user row follows this baseline rule and never silently falls
  back to current provider `Seen`.
- Shared and personal mailboxes retain per-user state so another technician or external client
  changing provider `Seen` does not erase individual awareness. After the recorded onboarding/grant
  baseline, external `Seen` changes never clear `unread for me`; the provider state remains visible
  and synchronized separately until the user explicitly acknowledges the message in Nexum. Opening
  in Nexum remains non-mutating.
- Normal delete moves to the provider's Trash/Deleted special-use folder where supported. Permanent
  provider deletion is a distinct, explicitly confirmed and separately authorized action.
- Drafts are reconciled with the provider Drafts folder, and successful sends are reconciled with the
  provider Sent folder without creating duplicate sent copies.
- IMAP moves and UID validity changes are treated as placement identity transitions. Nexum follows
  provider evidence such as target UID/copy results where available and re-baselines safely when the
  remote identity is ambiguous.
- When no authorized local operation is pending, confirmed provider state wins a conflict. A pending
  local operation that conflicts with a provider change becomes visible for retry, cancellation, or
  reconciliation rather than silently overwriting either side.

Email owns ordinary password-based IMAP/SMTP account configuration together with mailbox behavior.
The account form accepts and edits the address, endpoints, ports, TLS modes and usernames. Passwords
are write-only: an edit form leaves them blank and preserves the stored encrypted value unless the
administrator enters a replacement. One save action runs bounded IMAP authentication and bounded
SMTP authentication. A passing check activates the requested account state; a failed check leaves
the account editable and inactive with a specific safe error.

Integration continues to own OAuth client registration, shared third-party token lifecycle, AI
provider governance and central external-data policy. Email owns every mailbox account and its
password-based IMAP/SMTP configuration. Historical provider-first rows may remain only as inert
metadata after their credential ciphertext is destroyed; they are neither a connection source nor a
second runtime. An existing account is repaired by editing and testing that same account in place.

Every provider path has a non-configurable security floor: verified TLS with hostname/certificate
validation and no silent downgrade or certificate bypass; least-scope OAuth or provider credentials;
encrypted, rotatable, and revocable secrets; secret-safe logs; bounded connection/rate behavior; and
administrator-approved endpoints. User-controlled arbitrary hosts, redirects, DNS rebinding, and
provider callbacks cannot become SSRF paths. An intentionally private/internal mail endpoint requires
an explicit trusted administrator configuration and the same transport verification rather than an
implicit exception.

Each provider advertises capabilities. Email must not emulate an unsupported destructive operation or
show a control that cannot work. Initial and incremental sync are retry-safe, bounded, observable,
folder-aware, and fail-closed on UID or provider cursor ambiguity. Historical import is always an
explicit, permissioned operation with preview, account/folder/date scope, caps, and progress; unread
state is never used as an import cursor.

Provider/server-side rules such as Sieve retain explicit provenance and one declared source of truth.
Nexum never overwrites an externally managed rule silently. Rules that need PSA context, AI, or
cross-domain actions execute inside Nexum after safe ingest rather than being projected to a mail
server that cannot enforce the same authorization and audit contract.

Attachment safety applies to normal Mail use, not only AI. Known malicious content is quarantined and
cannot be previewed, downloaded through an ordinary path, indexed, extracted by a rule, or sent to AI.
Inline preview, content extraction, and AI require a successful clean result from the configured
scanner/policy. Encrypted, unsupported, oversized, indeterminate, or not-yet-scanned content fails
closed for preview/extraction/AI; a separately authorized raw download may be offered only where
installation policy permits it and after an explicit risk warning. Scanner unavailability is visible
and retryable and does not discard the message or block safe header/body synchronization.

### 6. Mail Workspace

The finished `/tech/mail` workspace will be implemented as module-owned Livewire 3 components following
the existing Bootstrap operational-workspace guidelines. Livewire owns reactive presentation and
interaction state; Email Actions, Queries, policies, and API resources remain the reusable business
boundary so the browser is never a separate authorization or behavior path.

The default `Unified Inbox` / `Felles innboks` is a Nexum-owned virtual view, not an IMAP folder. It
combines placements in the provider-declared Inbox folders of every real external account the
current user may `view`, then filters to conversations with at least one visible inbound placement
that is `unread for me`. A separate `All unread` view may include authorized non-junk/non-trash
folders so account rules can keep filed mail out of the default Inbox without hiding it completely.

The default row is one permission-filtered conversation:

- A conversation qualifies and sorts by its newest visible `unread for me` Inbox placement.
- Authorized Sent/Archive messages may appear as context in the reading pane but do not make a
  conversation qualify for the default Inbox on their own.
- Every row and message keeps visible account/folder badges. Safe correlation may group placements
  for presentation but never physically merges uncertain deliveries or widens access.
- Conversation subject, participants, snippets, message counts, unread counts, and reply identity are
  computed only from placements the current user may view.
- Conversation actions remain placement/account operations. Move, archive, or delete affects only the
  active account by default; a multi-account action is separate, enumerates every affected account,
  reauthorizes each placement, and requires explicit confirmation.
- Opening a conversation records `opened by` only for messages actually presented to the user; it
  never performs read acknowledgement. A new message arriving in an open conversation remains
  `unread for me` until that user explicitly marks it read.

The three-pane layout is:

- Left: favorites, unread, personal/shared/system accounts, folder tree, saved views, and account
  scope.
- Center: dense message/conversation list with accessible unread, flag, category, attachment,
  account, and follow-up indicators.
- Right: safe reading/conversation pane, attachments, sender/contact and authorized PSA context,
  related records, and optional AI suggestions.

Primary badges count unique unread conversations so the count matches default list rows; secondary
details may show unread message counts. Physical folder badges may continue to show provider-unread
message counts and must be labelled so shared provider `Seen` is not confused with `unread for me`.

#### Categories And Tags

Mail reuses the existing Taxonomy domain instead of adding Email-local category/tag definitions:

- Taxonomy owns names, hierarchy, lifecycle, and definition permissions; Email owns assignment and
  removal through its guarded conversation actions.
- Classification is scoped to the account-scoped conversation. A shared-account category/tag is
  therefore visible consistently to authorized members of that account, while the same correlated
  mail in a personal or different shared account is not changed.
- A conversation has at most one primary Email category and may have several Email tags. Existing
  message-level Email tags remain valid routing/history facts and are not blindly promoted to every
  message in the conversation. Any migration or projection preserves scope and provenance.
- New rule/API actions distinguish message classification from shared conversation classification,
  for example `tag_message`, `tag_conversation`, and `set_conversation_category`. The legacy `tag`
  action keeps its existing message-level meaning during compatibility migration.
- Provider labels, categories, and IMAP keywords are displayed separately unless an approved mapping
  explicitly defines direction, conflict handling, authorization, and rollback.
- Ticket classification remains Ticket-owned. Linking a conversation may display its Email
  classification as context but does not silently overwrite Ticket category/tag state.

Current create/link behavior copies message-level Email tags into Ticket. Existing Ticket tag
assignments remain unchanged during migration, but a new conversation link does not silently add or
remove Ticket tags. `Opprett Ticket` may initialize Ticket classification only through an explicit,
previewed mapping or approved policy. Email tags may remain immutable Ticket-rule input facts without
becoming Ticket assignments.

#### Collaborative Reading And Reply Presence

Authorized shared-mailbox members can coordinate without turning Mail into a chat transport:

- Opening presented content records a durable `opened by` receipt with user and first/last-viewed
  timestamps, shown only to users who may currently view the same account-scoped conversation.
- `X leser nå` and `Y skriver svar` are ephemeral private-channel presence signals maintained by
  heartbeat and a short expiry. Navigation, disconnect, grant removal, or timeout clears them; the
  system does not infer continued presence from a stale browser.
- Presence events contain opaque identifiers and activity type only. They never contain draft text,
  quoted content, recipients, subject, attachment names, or message snippets.
- Reading presence never locks a conversation. Typing presence is a soft coordination warning; shared
  drafts use the explicit draft-lock contract. If another reply is sent or the conversation changes,
  an open composer is marked stale and recipients/thread context are revalidated before send rather
  than merged automatically.
- Presence is operational collaboration state, not employee-performance telemetry. Durable audit
  covers explicit read acknowledgements, sends, links, and mutations, not a permanent heartbeat log.

#### Live Updates And List Stability

The no-refresh experience requires two separate real-time legs: provider-to-Nexum synchronization and
Nexum-to-browser delivery. After a mailbox projection transaction commits, Email publishes one
idempotent versioned invalidation event for the affected account, placement, and conversation.

- Events use private authorized user/mailbox channels and contain only opaque identifiers, change
  type, and projection version. They never broadcast subject, sender, snippet, body, attachments, or
  inaccessible counts.
- Livewire re-runs the normal permission-filtered Query for only the affected conversation/counts;
  the event payload is never trusted as readable mail data.
- Grant removal, account disablement, or break-glass expiry invalidates current results immediately
  and prevents reconnect/subscription. Content authorization is repeated even if an old socket remains
  connected long enough to receive an opaque invalidation.
- Reconnect and browser resume compare projection versions and perform an incremental catch-up, so
  correctness does not depend on receiving every transient push event.
- A visibility-aware automatic polling/reconciliation fallback keeps the view current when the push
  transport is unavailable. It does not require a manual refresh and must not reload the full mailbox
  for every tick.
- Events are coalesced during bursts. While the user is scrolled away from the top, new conversations
  update counts and a `New mail` indicator instead of moving rows under the pointer. A conversation
  that stops matching `unread for me` remains pinned while selected and leaves the list when the user
  navigates onward.
- New inbound messages, reconciled Sent messages, manual read acknowledgements, opened-by receipts,
  taxonomy changes, Ticket links, and presence state update affected authorized views without manual
  refresh. Durable state uses versioned after-commit invalidation; ephemeral presence uses bounded
  private-channel events and does not become mailbox projection history.

The exact private broadcast transport requires a slice-level ADR and operations review because the
checked Dev dependency snapshot has Livewire 3 but no current Echo/Reverb/WebSocket stack; the live
authoritative working copy must be reverified before implementation. The selected transport
must include authentication, TLS/reverse-proxy configuration, long-running process supervision,
horizontal delivery where applicable, health checks, and the automatic fallback above.

The same responsive/PWA application adapts to small screens with list-first navigation and full-screen
message/composer views. It is not a separate reduced mobile product. Offline private mail storage and
offline writes remain unavailable without a separately approved design.

Personal settings include default account/sender/signature, favorites, conversation behavior,
reading-pane/list density, notifications, and allowed AI assistance. Opening-to-read automation is not
a user preference: opening never acknowledges mail. Company and mailbox policy can be stricter than a
personal preference.

Remote images and tracking content are blocked by default. Stored/rendered HTML remains sanitized,
and raw source or unsafe attachment access requires explicit permission and safe delivery controls.
Where providers supply SPF/DKIM/DMARC or equivalent authentication results, Mail presents them as
security context rather than proof of identity. Display-name/domain mismatches, suspicious links, and
known phishing indicators receive clear non-content-leaking warnings; neither a passing result nor an
AI classification suppresses ordinary recipient, link, attachment, or authorization safeguards.

### 7. Compose, Draft, Send, And Sent Items

Email owns one outbound action layer used by Mail, Ticket, Sales, Marketing, Notification, and future
callers without erasing each domain's workflow ownership.

The mail composer supports recipient autocomplete, sender selection limited by account grants,
reply/reply-all/forward, plain text plus sanitized HTML, signatures, inline images, attachments, and
autosaved drafts. Sending uses a client-generated idempotency key so retries cannot duplicate an
external message.

Defaults:

- New personal mail uses the user's selected default personal sender.
- Replies use the account/placement that received the message unless an authorized user changes it.
- Shared-mailbox sent items remain shared by default.
- Personal drafts remain private; shared drafts use explicit access and edit locks.
- A successful send records provider response, Message-ID, sender identity, account, recipients,
  linked object context, and sanitized audit metadata before the UI reports success.
- An ambiguous provider result is reconciled before retrying; it is not blindly sent again.
- A shared-account reply is reconciled into that real account's Sent placement and the same
  account-scoped Email conversation. Every currently authorized member sees the sent message and
  updated conversation through the live-update contract without refreshing.
- Replies preserve standards-based `Message-ID`, `In-Reply-To`, and `References` threading. Ticket
  callers additionally preserve the established `TD-...` Nexum Ticket reference in the subject;
  neither correlation mechanism replaces the other.

### 8. Deterministic Rules And Rules API

Email continues to own message-local parsing, placement, tagging, archiving, linking, and ticket
ingress rules. Signal continues to own cross-domain automation after an explicit `emit_signal`
handoff.

Rule precedence is fixed and visible:

1. Mandatory ingestion, security, trusted-routing, retention, and compliance rules.
2. Shared/system account rules managed by authorized administrators.
3. Personal technician rules for accounts/messages the technician may access.
4. Explicit Signal handoff and other authorized cross-domain actions.

A lower layer cannot bypass, reorder, or undo a mandatory higher layer. Existing preclassification
behavior remains available for trusted system routing.

Rules support:

- explicit account, selected-owned-account, shared-account, folder/virtual-view, and
  inbound/outbound/draft scope, with no implicit `all current and future accounts` scope,
- grouped `all`/`any` conditions and ordered actions,
- deterministic mail facts plus authorized Contact/Client/Vendor/Ticket/Task/Signal facts,
- separate draft/publish state, immutable published version snapshots, enabled/disabled state,
  priority, stop behavior, categories, tags, and version history,
- validation and dry-run against one message, selected messages, a search result, folder, account, or
  bounded date range,
- an exact preview of matched messages, skipped messages, proposed changes, irreversible actions,
  and permission/policy denials,
- immutable execution attempts, per-action results, stable idempotency keys, progress, and reason
  codes,
- retry of failed/not-run actions without replaying successful side effects,
- an explicitly warned full rerun using the same idempotency contract,
- undo only for actions with a verified inverse and unchanged target state.

An action failure records all later actions in that rule as `not_run`; other eligible rules may still
continue. `stop_processing` takes effect only after the matching rule's ordered actions complete
successfully. Stable idempotency keys include the message/placement, published rule version, and
snapshotted action position.

Rule-created cleanup defaults to reversible move/archive/tag/category operations and preserves unread
state unless the rule explicitly changes it. Permanent delete and external sending are not ordinary
personal-rule actions.

The rule UI, REST API, background jobs, and AI-created rule proposals all call the same Email actions
and policies. The API must expose rules, versions, validation/preview, executions, and reprocessing
with explicit domain abilities and mailbox scope.

### 9. AI Summaries, Matching, And Drafts

Mail AI is a set of explicit Integration-governed workloads, not a mailbox actor with hidden tools.
Initial capabilities may include:

- summarize one message, an authorized conversation, or a bounded unread/account view,
- extract questions, action items, owners, dates, deadlines, and missing information,
- suggest Contact, Client, Vendor, Ticket, Task, Lead, folder, category, priority, and tags,
- classify machine/noise mail for supervised cleanup,
- draft a reply using selected authorized thread and company/user tone context,
- explain why a suggestion was made and which authorized records supported it.

Every request passes the central Integration data-egress gate. Installation, provider/model,
workload, account, user, Work Context, and record authorization are intersected. Attachments and raw
message source are separate data classes and remain excluded unless explicitly allowed. A privacy
gateway or provider failure fails closed and never falls back to unapproved raw data.

Mail body, quoted history, signatures, HTML, and attachments are untrusted model input. They cannot
alter system instructions, tools, abilities, recipient policy, or mailbox scope. The workload strips
general write tools even when its selected base agent has them. Deterministic filtering removes
credentials, authentication headers, raw integration data, unnecessary quoted history, and signatures
before egress. Attachment use additionally requires an allowed data class, type/size limits, malware
and content-policy checks, and explicit workload approval.

AI output is typed, schema-validated, provenance-bearing, and non-mutating. The Email action layer
performs any accepted change after a fresh authorization/policy check. Confidence never bypasses
permissions, recipient policy, security rules, or required human approval.

Prompt/response bodies are not copied into mandatory audit logs. Stored summaries or drafts have a
clear owner, source version/fingerprint, provider/model/workload trace, retention state, and stale
indicator when the source conversation changes. Provider/model usage and cost use Integration's
shared telemetry; Mail does not create a second token/cost ledger. Raw provider responses are never
stored in ordinary Email rule, audit, or security logs.

Every automated or bulk AI workload has organization maximums plus stricter workload/rule/account/user
limits for calls and input/output tokens per request, day, and month. Limits default to bounded, not
unlimited. The runtime reserves budget before queued execution and reconciles actual input, output,
cached, and tool usage plus provider request ID and price version afterward. Retries and fallbacks use
the same budget. Unknown price never enables unattended unlimited processing. A soft limit warns; a
hard limit records `budget_blocked` and leaves normal synchronization and deterministic rules running
without silently selecting a cheaper or more permissive provider.

When repeated user behavior suggests a rule, Nexum shows the pattern and proposed deterministic
conditions/actions. `Always do this` opens a normal rule preview and creates a versioned rule only
after explicit acceptance. Dismissal/correction feedback is tenant/workload scoped and must not leak
across users, clients, or installations.

### 10. Supervised Cleanup

The Smart Inbox presents AI and deterministic suggestions in a review queue. Each suggestion clearly
shows whether accepting it will:

- change only Nexum metadata,
- change the provider mailbox,
- create/link a PSA record,
- emit a Signal, or
- prepare an external draft.

Accept, dismiss, correct, and `Always do this` are auditable. Bulk acceptance repeats authorization
for every message and reports partial failures. Cleanup suggestions may never silently mark customer
or vendor requests read, hide unresolved work, permanently delete mail, or create cross-domain writes
without the configured action policy.

### 11. Restricted Automatic Replies

AI-generated drafts with manual approval are part of an earlier slice. Automatic external replies are
a later high-risk slice and are not authorized merely by approving the rest of this RFC.

That slice requires a second explicit product approval and all of these gates:

- installation, account, and individual rule enablement; all default `off`,
- organization-level permission plus publication of each auto-send rule by an explicitly authorized
  mailbox rule publisher; a personal owner cannot exceed the organization maximum,
- an allowlist of scenarios, recipients/domains, and response boundaries,
- approved template or structured response constraints,
- reply-only behavior for an existing authorized thread in the first version,
- recipients resolved from the original thread or an authoritative Contact record, never an
  AI-generated address,
- no automatic Reply All, CC/BCC, new-recipient, bulk, or marketing delivery; Marketing mail remains
  inside Marketing's approval and suppression workflow,
- current confidence/evidence requirements without treating confidence as authorization,
- duplicate, retry, mail-loop, bounce, out-of-office, and auto-generated-message protection,
- a maximum reply count per thread/time window plus a configurable delay/cancel window,
- recipient, message, and time-window rate limits plus provider and cost budgets,
- sensitive-content, attachment, credential, legal/contract, finance, complaint, and security-incident
  exclusions unless a later reviewed policy explicitly allows a bounded case,
- preview/test mode, complete execution/audit history, delivery reconciliation, and a global emergency
  stop,
- named human review on Dev before any production enablement.

Automatic replies must be visually and operationally distinct from summaries and suggested drafts.

### 12. PSA And Cross-Domain Boundaries

| Domain | Ownership |
| --- | --- |
| Email | Mailbox accounts, password-based IMAP/SMTP settings and health, folders, sync, canonical messages/placements, threads, drafts, sending, Mail UI/API, message-local rules, rule execution, and Mail AI suggestions. |
| Integration | OAuth client registration and shared token governance for driver-specific account connections, AI providers/models, central data-egress policy, managed workload execution, usage/cost telemetry, and the API ability catalog. It does not own password-based IMAP/SMTP accounts or credentials. |
| UserManagement | User lifecycle and global role permissions; Email owns per-mailbox grants/delegation. |
| Contact / Clients / Relationship | Identity and organization/vendor records; Email stores links and suggestions, not duplicate master records. |
| Ticket / Sales / Task | Domain record creation and mutation through their guarded actions. Ticket owns case workflow, portal visibility, internal notes, and retained case documentation. Email owns the live mailbox placement/cache and account-scoped conversation; guarded links project authorized conversation messages into the Ticket timeline. |
| Signal | Normalized cross-domain events and automation after explicit Email handoff. |
| Notification | In-app, email, and Web Push policy/preferences/delivery for Mail events. |
| Calendar | Calendar records and invitation decisions after a later approved slice. |
| Documentation / file providers | Durable file/provider records when an attachment is explicitly saved or linked outside Email. |
| Report / future Intelligence | Aggregated reporting and derived insight presentation; Email supplies permission-filtered facts and feedback events. |

#### Email Conversations Linked To Tickets

For an email-based case, Ticket is a case-management layer over one or more real Email
conversations. Status, assignee, SLA, time, tasks, internal notes, and portal publication remain
Ticket-owned; transport, provider placement, participants, threading, Sent reconciliation, and mailbox
classification remain Email-owned.

The linking model has these invariants:

- One Ticket may link several separate account-scoped Email conversations, for example the original
  customer thread, a new supplier thread, and correspondence with another provider's ticket system.
- One account-scoped Email conversation has at most one **primary Ticket** for automatic routing of
  later inbound and Sent messages. Other Tickets may hold audited references to it, but do not
  automatically copy or route new messages from that conversation.
- Each link records the account, conversation, primary/reference role, actor, reason/source,
  timestamps, correlation evidence, portal classification, and lifecycle. Unlinking does not rewrite
  provider history and requires an audited guarded action.
- A linked conversation remains a real Mail conversation in its provider folders. Ticket shows a
  permission-filtered chronological case timeline without pretending several independent reply
  chains are one Email thread.

Ticket merge locks both Tickets and their affected Email relationships in one transaction. Primary
links and secondary references owned by the source transfer to the surviving target. Duplicate target
relationships/captures collapse to the strongest valid role while preserving both provenance trails;
a target secondary reference to a source-primary conversation is promoted atomically to the target's
primary link. Every relationship and capture is reauthorized in the target Work Context. Any
customer-visible evidence requires the source and target client/site/portal identity to match; a
mismatch, uncertain identity, competing primary ownership, or audience/correlation conflict aborts
without partial changes and enters authorized review. Merge never silently reclassifies audience,
sends or recaptures mail, changes provider placement/read state, or publishes content; later
correlated mail routes only to the surviving primary Ticket.

The retired source Ticket key remains an audited, non-authorizing correlation alias to the survivor
so a later key-only reply is not lost merely because it still names the old `TD-...` reference. Alias
resolution rechecks installation, account, target Work Context, and audience boundaries. If a retired
alias, an active Ticket key, or verified header/durable-link evidence resolve to different targets,
the message enters conflict review rather than routing automatically.

Every Mail read path derives authorization from the account and placement, never from whether a
Ticket link exists. This includes technician UI and API list/show, route-model binding, raw/source,
and attachment download. After conversion the actor may leave the default unread-only projection
because the selected source was acknowledged, but the source remains available in its real folder
and all-mail views; another authorized user's personal unread projection remains unchanged.

`Opprett Ticket` from Mail performs one guarded, idempotent transaction and follow-up operation:

1. Create the Ticket and primary conversation link through Ticket and Email domain actions.
2. Capture the required Ticket-owned documentation/provenance while retaining the original Email
   placement in the real Inbox; conversion does not hide, move, archive, or delete the source mail.
3. Explicitly mark only the selected source message read in the converting actor's `unread for me`
   state and, because conversion is an organizing acknowledgement, queue provider `Seen` only for
   that message's active-account placement. Other messages in the conversation, future arrivals, and
   correlated copies in other accounts are not acknowledged. Other Nexum users retain their own
   personal unread state even though provider `Seen` is shared. The action requires the relevant
   Ticket-create, Email-view, and Email-organize authority and shows pending/failed provider
   reconciliation honestly.
4. Publish the durable link/read changes to authorized Mail and Ticket views after commit.

An existing Ticket supports both `Knytt e-postsamtale til Ticket` from Mail and `Ny e-post i saken`
from Ticket. A new outbound conversation is linked before the idempotent send so a successful send,
an ambiguous provider result, and every later reply can reconcile against the same link. Linking an
already received conversation captures the selected source message and later correlated messages by
default without removing them from Mail. Older authorized history is previewed and included only by
an explicit user selection or a separately approved account/Ticket-ingress policy; linking never
silently publishes an entire old or mixed-audience thread into Ticket.

Ticket replies use Email's same outbound action and the selected conversation's authorized
account/sender. `Svar` and `Svar alle` calculate recipients only from the selected source message and
show the effective recipient preview; the conversation-wide participant history is never a recipient
list. Where valid identifiers exist, the reply sets `In-Reply-To` to the selected source message's
Message-ID and builds `References` from its verified chain followed by that source Message-ID. It
derives its reply subject from the same selected message so an external provider's ticket token is not
stripped. The established `TD-...` Nexum Ticket reference is preserved or added without
replacing that external subject context, and the
provider Sent placement is reconciled. The Sent message appears once in the Email conversation and
once as a source-linked timeline item in Ticket; it is not represented as unrelated duplicate
correspondence.

Inbound correlation is additive and conservative:

1. A pre-existing durable conversation/Ticket link or outbound operation link is authoritative.
2. Valid `In-Reply-To`/`References` against a message in a linked conversation routes the new message
   to that conversation's primary Ticket even when the sender omitted the Ticket number.
3. The current `TD-...` Nexum Ticket-number subject/reference logic remains supported as an independent
   fallback and regression contract when standards-based headers are missing or rewritten.
4. A message with neither authoritative evidence may be shown as a possible link for manual review;
   sender and normalized subject alone do not silently attach customer-confidential content.

A valid standards-based thread may include replies from the customer, another supplier/provider, or
any original To/CC participant. Those messages remain part of that Email conversation and therefore
flow to its primary Ticket. Each timeline item displays its actual From, To, CC, account, direction,
and source conversation so a participant is not misrepresented as the customer. CC/BCC participants
are not automatically created as verified Contacts, Clients, or portal users.

If authoritative evidence conflicts, for example reply headers resolve to Ticket A while the subject
contains Ticket B's reference, Nexum does not auto-route or rewrite either Ticket. It records sanitized
evidence, surfaces a conflict triage item to authorized users, and requires an audited manual choice.
That choice can link a new conversation or correct future routing without deleting the original mail.

`Mark as not Ticket` becomes a selected-conversation routing correction rather than a return-to-Inbox
operation, because the source already remains in its provider Inbox. The guarded action suppresses
future Ticket ingress for that account-scoped conversation, removes its active primary link, and
audits the correction without deleting provider mail or already captured Ticket evidence. It does
not delete a multi-conversation Ticket or affect its other links. Closing, deleting, redacting, or
removing evidence from the Ticket is a separate Ticket-owned action. Legacy not-Ticket tags/rules
retain their scoped suppression meaning; a replacement rule follows the new account/rule authority
and publication model.

Reply and visibility boundaries are explicit per linked conversation:

- `Svar` and `Svar alle` operate only on the selected Email conversation and selected source message.
  Nexum never borrows recipients, quoted history, attachments, sender identity, or threading headers
  from another conversation merely because both belong to one Ticket. Reply All always previews the
  effective recipients.
- Customer correspondence may be portal-visible under the Ticket's explicit communication policy.
  A supplier, partner, other-provider, or other third-party conversation is **internal-only in the
  customer portal by default**, including when it concerns the same case.
- Making third-party correspondence visible to the customer is a separate explicit, authorized,
  audited portal-publish action with a content/attachment and audience preview. Linking or sending the
  Email is not portal publication, and changing a thread default never retroactively exposes messages
  without the documented policy/action.
- Ticket internal notes, time entries, tasks, assignments, workflow events, and presence indicators
  are never sent as Email and never become provider messages. Conversely, an external Email reply is
  never silently converted into an internal note.

Ticket-originated external mail is classified before Email sending. A customer reply retains the
current Ticket customer-reply action guard, client-scoped Published requirement, active same-client
primary-contact rule, and validated CC behavior. A reply in an already linked internal third-party
conversation requires the dedicated Ticket external-communication guard plus Email `view` and `send`;
its recipients come only from the selected source message, and its Ticket capture stays internal-only
until separately published. Starting a new supplier/vendor/third-party conversation requires the same
dedicated Ticket guard, an authorized From account, and recipient/audience preview. The recipient may
come from an existing verified Contact/Vendor address or be entered manually only after explicit
new-recipient confirmation; this never creates or verifies a Contact automatically, and organization
policy may disable manual recipients. Email send authority alone never bypasses Ticket workflow,
recipient, or portal policy.

Email uses one Contact-owned identity-resolution contract with deterministic exact matching and
manual fallback. It does not let separate `ContactEmail`, legacy `ClientUser`, or AI matching paths
silently create conflicting identities.

A remote delete removes the corresponding Mail placement and eventually its unretained cache under
Email policy. It does not delete a message snapshot/evidence record already created in Ticket through
an explicit user action or an explicitly enabled Ticket-ingress policy. Conversely, polling,
indexing, previewing, or suggesting a Ticket link does not by itself create a durable Ticket copy.
Ticket owns access, retention, correction, portal visibility, and deletion of its captured
documentation, while Email keeps provenance and shows when the original provider placement no longer
exists. Later messages on a linked conversation follow the same guarded capture/projection contract;
ordinary Mail viewing or correlation suggestions alone still do not create Ticket-owned copies.

After inbound storage and routing commit successfully, Email emits one idempotent post-routing domain
event with safe record references. Notification, indexing, and other approved consumers run from that
event. Their failure is visible and retryable but never rolls back the durable message or Ticket link.

### 13. Search, Indexing, And Communication Context

Search covers authorized subject, participants, body text, attachments where policy allows, dates,
accounts, folders, flags, tags, categories, threads, and linked PSA records. The search backend is
selected in a Feature Slice after Dev capability and operational cost review; this RFC does not
preselect a package.

The default search boundary is local or installation-controlled. Sending mailbox content or derived
full-text data to an external search/index service requires a separate provider/data-egress approval,
documented tenant isolation, encryption, regional/legal basis, retention and deletion propagation,
usage/cost limits, and proof that access revocation removes searchable data. Enabling external AI does
not implicitly authorize external indexing, and an external index may not become a hidden durable
mailbox archive.

Indexing and result counts are mailbox-permission aware. Revoking access removes results, snippets,
facets, cached suggestions, and AI retrieval immediately enough to meet the documented security
contract. Search/index failures must not block inbound storage, sending, or core provider sync.

Communication History remains a reusable, permission-filtered presentation of source-domain records,
not a new owner of copied message bodies.

### 14. Operations And Reliability

- Sync, indexing, reprocessing, AI, and outbound work use bounded queues with account/workload locks,
  retry/backoff, idempotency, and visible failure state.
- The external Laravel scheduler runner and required queue workers are deployment prerequisites and
  must be verified separately; `schedule:list` alone is insufficient.
- Account health distinguishes authentication, provider connectivity, cursor/UID validity, sync
  backlog, folder failure, send ambiguity/failure, quota, indexing, rule, and AI readiness.
- Manual `poll`, historical import, re-baseline, reprocessing, and test-send operations are separately
  permissioned and rate/cap limited.
- No failed AI, notification, Signal, indexing, or webhook side effect may roll back a successfully
  stored inbound message or a reconciled outbound send.
- Initial provider rollout is one account on Dev, with reversible/shadow behavior where applicable,
  before broader account enablement.

#### Data Lifecycle, Legal Hold, And Recovery

Every implementation slice supplies an explicit lifecycle mapping for live provider data, Nexum
cache/projections, search data, AI-derived artifacts, captured Ticket evidence, exports, and backups.
The minimum target behavior is:

| Event | Mail cache, index, and derived data | Ticket-owned evidence | Backup, export, and hold behavior |
| --- | --- | --- | --- |
| Provider message is deleted | Remove the placement and purge unretained content, search entries, previews, and Mail AI artifacts through bounded cleanup; retain only minimal deletion/provenance facts. | Unchanged only when the message was explicitly captured under Ticket authority. | Ordinary backups expire under their declared schedule; they are not a hidden archive. |
| Account or user is offboarded/revoked | Stop tokens, sync/IDLE, sockets, presence, personal rules, drafts, and actor-bound queued operations immediately; then execute the approved transfer/delete/retention decision. | Existing authorized capture follows Ticket policy and does not grant access to the former mailbox. | Recovery material remains encrypted and access-controlled; restoration cannot reactivate revoked credentials or queued sends. |
| Access/erasure/DSAR request | Export or erase only through an authorized, scoped workflow that includes relevant projections and derived artifacts without exposing other mailbox users or recipients. | Ticket applies its own lawful access, correction, retention, and erasure decision. | Document backup expiry and any lawful limitation; never claim immediate physical backup erasure when the backup system cannot provide it. |
| Legal hold | Preserve only the explicitly scoped content and derived evidence named by the hold, with actor, reason, authority, start/end, review, and release audit. | Ticket and Email record their separate held copies and ownership. | A hold overrides ordinary expiry only for its documented scope; release resumes the normal lifecycle. |

Default IMAP caching is not permanent archive or legal hold. Permanent/archive retention is explicit,
visible, permissioned, and audited. Restore procedures decrypt only within the approved installation,
re-baseline provider state before declaring parity, invalidate old sessions/tokens, and quarantine
pre-backup pending sends/provider mutations until they are reconciled so restoration cannot duplicate
external mail or replay stale mailbox changes.

## Impact Analysis

- **Email:** primary domain, routes, controllers, actions, queries, policies, models, migrations, jobs,
  services, password-based IMAP/SMTP account configuration, views, API resources, tests, README, and
  Knowledge docs.
- **Integration:** OAuth/reusable-provider setup, Email API abilities, AI data-egress decisions,
  workload/provider readiness, and usage/cost telemetry.
- **UserManagement/permissions:** global Email abilities, user lifecycle, delegation/break-glass role
  permissions, and disabled-user handling.
- **Contact/Clients/Relationship:** sender/recipient resolution, permission-filtered context, and safe
  entity suggestions.
- **Ticket/Sales/Task:** guarded link/create/update actions, one-Ticket-to-many-Email-conversations
  timeline projection, portal/internal visibility, reply-audience isolation, and regression protection
  for current ticket-first shared-mailbox and Ticket-number routing.
- **Taxonomy:** existing category/tag definitions and permissions, plus Email-scoped category type and
  account-conversation assignments without provider-label conflation.
- **Signal:** explicit events only; no replacement of Email rules.
- **Notification:** personal/shared mailbox notification scope, unread/read sync, and non-spamming
  defaults.
- **Calendar:** later invitation and out-of-office slices only.
- **Documentation/file providers:** attachment-save/link behavior in later slices.
- **Report/Intelligence:** future aggregate rule/AI outcome reporting and feedback without copying
  unauthorized content.
- **API:** new Email-owned endpoints and explicit token abilities intersected with mailbox scope.
- **Database:** additive ownership, membership, folder/placement, conversation, per-user read/opened
  state, taxonomy assignment, Email-conversation/Ticket link, draft/outbound, rule version, execution,
  and suggestion/audit records followed by a separately reviewed legacy-field cleanup.
- **Queues/scheduler:** provider sync, outbound reconciliation, rule preview/reprocessing, indexing,
  retention, and AI workloads.
- **UI:** a dense responsive Bootstrap Mail workspace plus Admin account/rule/settings surfaces.
- **Privacy/security:** personal employee mail, client-confidential content, attachments, provider
  credentials, AI egress, delegated access, and automatic external communication.
- **Operations:** migrations, permission seeding, cache clear, queue/scheduler verification, provider
  smoke tests, index health, and rollback/runbook work per slice.

## Data And Migration Plan

Migrations are additive and staged. No migration may blindly merge, delete, move, mark read, or send
existing mail.

1. Add account kind/ownership and per-mailbox access records. Backfill all current accounts to the
   shared/system-compatible state, leave personal ownership empty, and make Ticket ingress an
   explicit account policy that defaults off for every new personal account.
2. Add provider folders and canonical-message/mailbox-placement structures. Shadow-correlate existing
   rows and report ambiguous or duplicate candidates before any cutover. Cross-account candidates
   remain separate unless a later verified merge operation proves content and privacy-boundary parity.
3. Establish a server-authoritative baseline for every enabled external folder without changing
   remote folders, flags, read state, drafts, sent items, trash, or message existence. Record cursors,
   UID validity, operation/reconciliation state, and cache completeness explicitly.
4. Dual-read or project old and new records during a bounded compatibility period. Preserve existing
   account/mailbox/UID uniqueness and Ticket links until placement and provider-state parity are
   verified. Classify existing Ticket-linked content and preserve current Ticket documentation;
   uncertain legacy links are reported for review rather than silently copied or deleted. Preserve
   current Ticket/tag assignments as-is, do not promote message-level Email tags into Ticket during
   backfill, and report ambiguous tag provenance rather than detaching assignments.
5. Add account-scoped conversation, per-user manual read/opened receipt, Taxonomy assignment,
   draft, outbound/send-attempt, sent-placement, shared-draft lock, and personal preference records
   only in the slices that implement them. Keep ephemeral reading/typing heartbeats in expiring
   presence storage rather than a permanent activity history.
6. Add additive Email-conversation/Ticket links without deleting or replacing existing message/Ticket
   links or Ticket-number references. Backfill only deterministic links; report multiple-ticket and
   conflicting-correlation candidates for review. Preserve source Inbox placements and personal
   unread state during migration; migrations do not simulate a user conversion action or set provider
   `Seen`. Backfill first-class links before retiring the `EmailMessage.ticket_id` compatibility
   write; enforce one active primary per account-scoped conversation and one Ticket/message evidence
   capture with database-supported uniqueness plus transactional locking. Shadow-report legacy
   conflicts before enforcement. Cut over technician UI and API list/show, route binding, raw/source,
   and attachment reads together so no `ticket_id` filter or linked-message 404 path remains. Preserve
   every historical Ticket-message visibility value unchanged; set a backfilled conversation audience
   to customer-visible only from authoritative same-client/customer-policy evidence, otherwise mark it
   internal/review without retroactively publishing or hiding historical evidence. Backfill retired
   Ticket-key aliases only from unambiguous historical merge provenance; ambiguous source/survivor
   candidates remain review items rather than guessed aliases.
7. Add rule scope/version/execution records and migrate current global rules as legacy
   organization/shared rules with concrete account scope. Preserve order, routing phase, active state,
   and stop behavior only where scope is unambiguous; disable and flag ambiguous rules for Admin
   review. Never apply a legacy rule to a new personal account implicitly. Migrate legacy not-Ticket
   tags/rules to explicit account-scoped ingress suppression; leave ambiguous global suppression
   disabled for Admin review and preserve soft-deleted historical Tickets and audit records.
8. Add AI suggestion/provenance records only when the central policy/workload path is ready. Reuse
   Integration audit and usage ledgers instead of duplicating raw prompts or provider costs.
9. Remove or repurpose legacy placement fields only in a later forward migration after data parity,
   rollback window, human review, and production-safe backfill verification.

Existing credentials remain encrypted in their current owner until an administrator saves that
account through the final account form. The save replaces the account's password-based runtime
configuration in place and leaves obsolete Integration provider rows as non-runtime audit evidence;
it does not require a bulk credential migration. New OAuth/reusable provider connections still
follow the Integration boundary. No data migration calls an external provider or AI model, sends
mail, re-runs rules, imports historical messages, or changes remote/read state.

Rollback order is feature disablement, worker/scheduler stop, provider-sync stop, read-path fallback,
and preservation/export of audit/send records. Destructive schema rollback is not the first recovery
step and must not erase external-send evidence or mailbox state needed for reconciliation.

## Testing Plan

Each Feature Slice adds focused tests and affected-module regression coverage. The complete program
requires at least:

- migration/backfill tests proving every current account remains shared/system-compatible and current
  ticket intake/rules continue unchanged,
- personal owner, explicit delegation, shared membership, admin-config-versus-content, break-glass,
  disabled-user, route-model binding, search, notification, attachment, and API isolation tests,
- shared-account grant matrix tests proving `view` can record authorized opened receipts and explicit
  personal-only acknowledgement but opening/preview/body retrieval changes neither personal unread
  nor provider state, and `organize` plus source visibility is required for user-facing provider
  Seen/flags/folders/move/archive/trash, only `send` accounts appear as usable sender identities, and
  reply/forward requires both `view` and `send`,
- negative assertions that inaccessible mailbox existence, counts, snippets, raw source, attachments,
  PSA context, and AI output do not leak,
- provider capability, folder discovery, UID/cursor change, bounded historical import, message
  placement, canonical correlation, missing/reused Message-ID, and retry/race tests,
- provider security tests for verified TLS/no certificate bypass, least-scope credential use,
  rotation/revocation, secret-safe errors/logs, approved endpoint enforcement, redirect/DNS-rebinding
  handling, and SSRF denial without blocking explicitly trusted internal endpoints,
- two-way all-folder synchronization tests proving provider-originated changes appear in Nexum and
  Nexum-originated read/unread, flag, move, folder, trash/delete, draft, and sent actions are confirmed
  remotely or remain visibly pending/failed,
- IMAP IDLE/notification loss, scheduled reconciliation, UID transition/UIDVALIDITY reset, shared
  `Seen` plus independent per-user unread/opened state, `BODY.PEEK` or equivalent non-mutating reads,
  selected-message versus snapshotted-conversation acknowledgement, future-arrival unread behavior,
  new-inbound and missing-row baseline semantics, new-grant no-history-flood plus explicit backlog
  handover, duplicate sent-copy, operation race, and provider-wins conflict tests,
- unified Inbox tests across multiple real accounts for Inbox-role filtering, `unread for me`, stable
  conversation ordering/counts, authorized Sent/Archive context, safe duplicate correlation, and no
  inaccessible thread metadata leakage,
- conversation action tests proving archive/move/delete defaults to the active account and every
  explicit multi-account operation enumerates, confirms, and reauthorizes each placement,
- Livewire tests for after-commit private invalidations, opaque payloads, targeted query refresh,
  out-of-order/duplicate versions, revoked grants, reconnect catch-up, burst coalescing, selected-row
  stability, new mail in an open conversation, and automatic fallback without manual refresh,
- collaboration tests for durable opened-by visibility, current-permission reauthorization, private
  reading/typing channels, heartbeat expiry/disconnect, grant revocation, no content in presence
  payloads or permanent heartbeat history, concurrent reply warning, and stale composer revalidation,
- Taxonomy tests for one primary category/many tags, account-conversation isolation, shared visibility,
  personal-account privacy, definition-versus-assignment permission separation, existing Email tag
  migration, legacy message-level `tag` behavior, explicit message-versus-conversation rule actions,
  and no implicit provider-label synchronization,
- Email-to-Ticket classification compatibility tests proving existing Ticket tags survive migration,
  linking an existing Ticket does not inherit Email tags, create conversion initializes Ticket tags
  only through an explicit mapping/policy, and Email message tags remain available as immutable rule
  facts,
- attachment safety tests for clean, malicious, encrypted, unsupported, oversized, indeterminate, and
  scanner-unavailable results across inline preview, ordinary/raw download, indexing, rules, and AI,
- safe-rendering tests for HTML sanitization, blocked remote/tracking images, sender-authentication
  context, display-name/domain mismatch, suspicious-link warnings, and no warning-based authorization
  bypass,
- Mail/Ticket retention-boundary tests proving remote deletion removes the Mail placement/cache under
  policy without deleting explicitly captured Ticket documentation, and proving ordinary
  poll/index/preview does not create a Ticket-owned copy,
- Mail-to-Ticket conversion tests proving the source stays in Inbox, the actor is explicitly marked
  read only for the selected source message/active-account placement, later conversation messages and
  future arrivals remain unread, provider `Seen` is queued/reconciled only for that source placement,
  other users' personal unread state remains, and partial provider failure is visible without
  duplicating the Ticket or link,
- linked-mail access tests proving authorized sources remain reachable through technician folder and
  all-mail UI, API list/show, route binding, raw/source, and attachments; Ticket access alone grants none
  of those Mail paths, inaccessible accounts return non-enumerating denials, and conversion removes
  only the actor's selected message from their unread projection while other users still see theirs,
- Email-conversation/Ticket link tests proving one Ticket can contain several independent
  conversations, one conversation has only one primary auto-routing Ticket, secondary references do
  not receive new messages automatically, default capture includes the selected source plus future
  correlated messages without silently importing older history, linking is idempotent, and
  unlink/correction is audited; database/transaction tests cover primary/capture uniqueness,
  concurrent link attempts, primary and secondary merge transfer, role promotion/deduplication,
  target Work Context reauthorization, cross-client/site/portal public-evidence mismatch denial,
  atomic conflict abort, and future routing to the surviving Ticket exactly once,
- not-Ticket correction tests proving one conversation can be suppressed/unlinked without deleting a
  multi-conversation Ticket, changing its other links, deleting provider mail/evidence, or allowing
  later automatic capture; legacy single-message suppression, account/rule authority, audit, and
  retry idempotency remain covered,
- correlation regression tests for durable links, `In-Reply-To`/`References`, missing or rewritten
  headers, the existing Nexum Ticket-number subject/reference logic, replies with no Ticket key, CC
  participant replies, a key-only reply using a retired source Ticket key after merge, and conflicts
  between retired aliases, active keys, or headers that enter triage instead of automatic attachment,
- Ticket outbound tests proving replies use the selected conversation's account, selected-message
  recipients, threading headers, and additive Ticket-number subject reference without stripping an
  external provider's ticket token; an older message's CC is not inherited when replying to a newer
  source message; Sent reconciliation updates both authorized Mail and Ticket views once; a matching
  Sent reply made through another IMAP client follows the same primary Ticket link; and recipients,
  history, and attachments never bleed between two linked conversations,
- Ticket recipient-policy tests retaining the current customer-reply guard, Published and active
  same-client primary-contact constraints, and validated CC behavior; third-party reply/new-thread
  actions require both their dedicated Ticket guard and Email account grants, show exact recipients,
  never auto-create contacts, enforce manual-recipient policy/confirmation, and remain portal-internal,
- portal-boundary tests proving third-party/supplier conversations and internal Ticket notes remain
  internal by default, linking/sending does not publish them, explicit portal publication is
  authorized/audited and previews content/attachments/audience, and no retroactive bulk exposure
  occurs accidentally; audience-backfill tests preserve every historical message visibility and put
  non-authoritative customer/supplier cases into internal review rather than publishing them,
- cross-surface collaboration tests proving a conversation draft/responder presence started in Ticket
  is visible to authorized Mail users and vice versa without widening conversation/account access,
- send idempotency, ambiguous provider response, reply sender, send-as/on-behalf, attachment, signature,
  bounce, and audit tests,
- deterministic rule precedence, grouped conditions, versions, preview, policy denial, immutable
  attempts, action failure, retry, idempotency, full rerun, progress, and safe undo tests,
- Email/Ticket/Signal loop and regression tests for existing preclassification, default ticket policy,
  trusted routing, Signal emission, and supplier-order Email routing,
- AI disabled/default-deny, mailbox/work-context policy intersection, provider governance, privacy
  gateway, attachment exclusion, fail-closed, schema validation, provenance/staleness, retention,
  telemetry, and accepted-action reauthorization tests,
- automatic-reply allowlist, sensitive-case exclusion, loop/duplicate/rate/cost limits, emergency stop,
  delivery reconciliation, and no-send-on-uncertainty tests before that later slice is enabled,
- queue, scheduler, failed-job, retention cleanup, index rebuild, and health-state tests,
- lifecycle tests for provider deletion, cache/index/AI-artifact cleanup, scoped legal hold/release,
  DSAR/export authorization, account/user offboarding, socket/presence revocation, actor-bound queued
  send cancellation, encrypted restore, provider re-baseline, and no replay of pre-backup pending sends,
- search tests proving local/installation-controlled indexing is the default and any separately
  approved external index enforces mailbox authorization, deletion propagation, and revocation without
  leaking snippets, facets, counts, or stale documents,
- Bootstrap/Blade/component checks, responsive browser QA, keyboard/accessibility checks, and verified
  absence of unfinished controls,
- focused Email tests plus every affected module suite on authoritative Dev; broad slices require the
  full Laravel test suite when practical,
- authenticated HTTP/API smoke tests and named human review entries before merge, migration,
  deployment, or release.

Automated tests never mark the product human-reviewed. Every completed Level 2/3 slice receives a
stable entry in `docs/human-review.md` with role, mailbox, provider, page, API, migration, rollback,
and expected-result checks.

## Documentation Plan

- Expand `app/Modules/Email/README-email-system.md` as each implemented contract changes.
- Update Email Knowledge for account ownership, shared Admin grants, manual `unread for me`, provider
  `Seen`, opened-by and live presence, Taxonomy classification, the unified conversation Inbox,
  live-update/fallback state, folders, sending, rules, AI, privacy, recovery, and administrator/operator
  workflows.
- Update Integration Knowledge for provider setup, OAuth/secrets, Email API abilities, AI workloads,
  governance, usage, cost, and troubleshooting.
- Update UserManagement permission documentation and delegated/break-glass access procedure.
- Document the Mail lifecycle matrix, legal hold, DSAR/export, offboarding/revocation, attachment
  quarantine, provider TLS/credential/endpoint floor, local-index default, backup restore, and
  reconciliation procedures before the affected slices are enabled.
- Update Ticket Knowledge for create/link/new-conversation workflows, several conversations per case,
  correlation precedence and conflict triage, reply-audience isolation, source/Sent visibility,
  internal notes, and explicit customer-portal publication. Update Signal, Contact, Notification,
  Calendar, Taxonomy, and attachment/file-provider documentation only when their slices are affected.
- Generate/update OpenAPI documentation with account-scoped examples and explicit denial contracts.
- Add deploy, provider migration, historical import, re-baseline, index recovery, scheduler/worker,
  send reconciliation, retention, incident, emergency-stop, and rollback runbooks.
- Keep `docs/TODO.md`, the RFC/Feature Slice indexes, the GitHub Discussion reference, and
  `docs/human-review.md` synchronized.
- Update the `nexumpsa.eu` handoff only after public-safe behavior is implemented and verified; this
  RFC is not a publication announcement.

## Dependencies And Implementation Gates

- This RFC and its four accepted Email architecture decisions were explicitly approved by Svein on
  2026-08-12. Implementation must still be selected and delivered through the ordered Feature Slices;
  approval does not make an unscoped implementation change acceptable.
- The first slice must use the authoritative `/var/Projects/tdPSA` Dev working copy and re-read its
  current database, routes, permissions, jobs, tests, and dirty state before creating migrations.
- Current supplier-order preclassification and ticket-first shared-mailbox routing are protected
  regression contracts, not legacy behavior to remove casually.
- The existing `TD-...` Nexum Ticket-number subject/reference logic is a protected compatibility
  contract.
  Conversation links and RFC threading may extend it but no slice may remove or weaken it without a
  separately approved migration and regression evidence.
- The central AI data-egress implementation and relevant provider/human-review gates must be healthy
  before Mail sends message content to an AI workload/provider.
- Shared provider usage/cost telemetry and enforceable bounded budgets must be verified before any
  unattended or bulk Mail AI workload is enabled; missing price/usage data fails closed for that AI
  step without stopping ordinary mail processing.
- Automatic external replies require their own later explicit approval even after this RFC is
  approved.
- Each slice must verify required queue workers and the external scheduler runner before claiming
  background behavior works.
- The Livewire workspace must use the existing Livewire/Alpine runtime rather than loading a second
  Alpine instance. The live-update slice must verify the chosen private broadcast service, reverse
  proxy/TLS, process supervision, reconnect catch-up, and automatic polling fallback on Dev before
  claiming no-refresh behavior.
- A search package/provider, Microsoft/Google driver, real-time transport, attachment malware scanner,
  or new file provider requires slice-level impact review and an ADR when the choice creates a durable
  architecture dependency.

## Feature Slice Order

1. **Simple account-owned IMAP/SMTP setup and mailbox authorization foundation.** Add safe account
   kinds, personal owner, real shared accounts, independent `view`/`organize`/`send` grants,
   config-versus-content separation, account-scoped rules, personal
   `ticket_ingress_enabled = false`, scoped Inbox/API views, tests, and Knowledge. Password-based
   IMAP/SMTP is created, edited, tested and activated in the Email account form without provider or
   migration terminology. Preserve current shared/ticket routing only for the explicitly selected
   shared/system accounts.
2. **Server-authoritative folders and canonical mailbox placements.** Add all-folder discovery,
   provider-authoritative placement state, bidirectional incremental sync, notification/IMAP IDLE plus
   scheduled reconciliation, idempotent remote-operation tracking, conservative canonical
   correlation, migration shadowing, and bounded recovery tooling.
3. **Deterministic personal/shared rules and API.** Add rule scope, draft/publish, grouped builder,
   versions, validation/preview, immutable execution attempts, background reprocessing, safe
   retry/undo, and documented precedence while preserving Email/Signal boundaries.
4. **Livewire unified Inbox and conversation workspace.** Deliver `/tech/mail` as a module-owned
   Livewire 3 surface with the default permission-filtered `unread for me` conversation view across
   real accessible Inbox folders, account/folder navigation, stable live updates, private opaque
   invalidations, reconnect/fallback behavior, permission-aware search, active-account actions,
   explicit manual acknowledgement, durable opened-by receipts, expiring reading presence, Taxonomy
   category/tag assignment, flags, move/archive/trash, responsive behavior, and an operations-reviewed
   transport ADR.
5. **Composer, drafts, sent items, and shared collaboration.** Introduce the unified outbound action,
   idempotent send/reconciliation, personal/shared drafts, signatures, Sent conversation projection,
   expiring typing presence, stale-composer protection, and shared-draft locks.
6. **Mail/Ticket multi-conversation case communication and PSA context.** Add guarded create/link/new
   conversation actions, one primary Ticket per account-scoped conversation, several conversations per
   Ticket, additive header plus Ticket-number correlation, conflict triage, source-Inbox preservation,
   Ticket-originated replies through Email/Sent, permission-filtered identity/entity suggestions,
   thread-specific audiences, portal/internal visibility, and reusable source-record timelines.
7. **AI summaries, classification, entity matching, and reply drafts.** Use explicit governed
   workloads, manual acceptance, provenance, cost telemetry, and central fail-closed data policy.
8. **Supervised Smart Inbox cleanup and `Always do this`.** Add review queue, correction feedback,
   bulk safeguards, and accepted conversion to deterministic rules.
9. **Restricted automatic replies.** Start only after separate explicit approval; deliver low-risk
   allowlisted scenarios, layered enablement, limits, reconciliation, emergency stop, and human review.
10. **Later provider and productivity extensions.** Microsoft/Google drivers, richer search,
   invitations/out-of-office, file-provider attachment workflows, advanced live collaboration, and
   reporting receive their own slices/ADRs when selected.

No later slice may start while a security, data-integrity, send-reconciliation, or human-review defect
from its required foundation remains unresolved.

## Risks And Mitigations

- **Personal-mail disclosure:** default-deny account scope, separate admin/content abilities, explicit
  delegation/break-glass, negative leakage tests, and sanitized health/logs.
- **Break-glass becomes standing access:** named account/operation scope, mandatory reason, short TTL,
  owner/security notice, immediate revocation, metadata-only sensitive-action audit, and post-event
  review.
- **Revoked users or restored queues still send mail:** execution-time reauthorization, immediate
  token/socket/presence shutdown, cancellation of actor-bound queued work, and restore quarantine for
  unresolved pre-backup sends/provider mutations.
- **Duplicate or lost mail:** conservative canonical correlation, provider placement identity,
  idempotent jobs/actions, cursor failure states, reconciliation, and staged migration.
- **Local/server divergence:** provider-authoritative standard state, visible pending operations,
  capability-aware commands, scheduled reconciliation, conflict evidence, and no false success UI.
- **Read-only users mutate shared mail:** separate `view`/`organize` grants, personal read state, server
  mutation tests, and no provider action from view-only paths.
- **Opening mail hides work:** non-mutating body retrieval, manual-only `unread for me`
  acknowledgement, independent provider `Seen`, and regression tests for open-pane/new-message paths.
- **Presence becomes surveillance or leaks content:** mailbox-private opaque events, current-permission
  checks, heartbeat expiry, no draft/content payload, no permanent heartbeat history, and documented
  collaboration-only purpose.
- **Live updates leak or become stale:** opaque private invalidations, permission-filtered re-query,
  projection versions, revocation handling, reconnect catch-up, bounded event coalescing, health
  checks, and an automatic visibility-aware fallback.
- **Conversation actions affect several real accounts:** active-account default, visible account/folder
  badges, explicit multi-account scope, per-placement authorization, and confirmation.
- **Taxonomy crosses account/privacy boundaries:** account-conversation assignments, Taxonomy/Email
  permission intersection, no implicit cross-account propagation, and provider labels kept separate.
- **Remote deletion erases PSA evidence:** durable copies are created only through guarded explicit
  capture or enabled Ticket ingress, then owned and retained by Ticket independently of Mail cache.
- **Mailbox cache becomes an undeclared archive:** explicit lifecycle matrix, purge propagation to
  index/AI artifacts, scoped legal hold, honest backup expiry, and separate Ticket retention.
- **Malicious or unscannable attachments execute or leave the boundary:** quarantine, clean-result
  requirement for preview/extraction/AI, fail-closed scanner behavior, and separately authorized
  warned raw download only where policy permits.
- **External search silently exports the mailbox:** local/installation-controlled default and a
  separate provider/data-egress approval with retention, deletion, revocation, tenancy, and cost
  controls.
- **Provider configuration weakens transport or exposes the network:** verified TLS, least-scope
  rotatable credentials, approved endpoints, secret-safe logs, bounded connections, and SSRF/rebinding
  protection.
- **Ticket correlation misroutes confidential mail:** one primary Ticket per account-conversation,
  additive durable/header/Ticket-number evidence, no sender/subject-only auto-link, conflict triage,
  audit, and idempotent correction.
- **Several Ticket conversations mix recipients:** every reply is anchored to one selected conversation
  and source message, with recipient preview and no cross-thread history, attachment, or header reuse.
- **Third-party correspondence leaks to the customer portal:** internal-only default, explicit
  authorized portal publication with audience/content preview, and negative visibility tests.
- **Duplicate external sends:** client operation keys, send-attempt ledger, ambiguous-result
  reconciliation, loop/rate guards, and no blind retry.
- **Rules hide real work:** mandatory precedence, dry-run, explicit unread behavior, reversible cleanup,
  audit, review queue, and no automatic permanent delete.
- **AI data leakage or hidden behavior:** central data-egress gate, least data, attachment exclusion,
  fail-closed routing, typed non-writing results, visible provenance, and normal rules for learned
  patterns.
- **Cross-domain loops:** explicit Signal handoff, current loop guards, stable idempotency metadata,
  and source-domain regression tests.
- **Large migration scope:** additive slices, shadow/backfill reports, compatibility period, bounded
  rollout, and delayed legacy cleanup.
- **Operational overload/cost:** bounded sync/import/reprocessing, queue isolation where required,
  provider backoff, search/index decoupling, AI usage telemetry, and settings-led limits.
- **UI overpromises:** no visible account, rule, AI, send, provider, or automation control until its
  backend, authorization, tests, and documentation are complete.

## Open Questions

No design question remains open in the approved target. Exact schema names, UI wording, search
provider, OAuth driver packages, live-update transport, and per-slice deploy commands are deliberately
deferred to the relevant Feature Slice and ADR without weakening the decisions above.

The approved product/privacy decisions are:

1. The existing Email module remains the sole Mail domain owner.
2. Personal mailbox content is private from ordinary administrators; non-owner access requires
   explicit delegation or audited break-glass authority.
3. Canonical message content is separated from per-account/folder placement and per-user state; the
   first migration creates a placement for each existing message without automatic cross-account
   deduplication.
4. External mailboxes are server-authoritative and bidirectionally synchronized. A remote deletion
   removes the Mail placement/cache under Email policy but does not delete an explicitly captured,
   separately Ticket-owned documentation record. Svein explicitly accepted this boundary on
   2026-08-11.
5. Shared mailboxes are real provider-backed accounts configured under Admin. Membership grants
   `view`, `organize`, and `send` independently. The default Livewire workspace is a no-manual-refresh,
   permission-filtered `unread for me` conversation Inbox across every real account the user may view;
   provider actions apply only to the active account unless a separate multi-account action is
   explicitly selected and confirmed. Svein accepted this direction on 2026-08-11.
6. Opening a message never marks it read. Personal manual acknowledgement, provider `Seen`, durable
   opened-by receipts, and ephemeral private reading/typing presence are separate states. Shared Sent
   replies update the same account conversation live, and Email reuses Taxonomy categories/tags at the
   account-conversation boundary. New inbound after a user's access baseline starts unread-for-me; a
   new shared grant does not flood historical mail without an explicit backlog handover. Svein
   specified the core read/collaboration behavior on 2026-08-11.
7. Creating a Ticket retains the source in Inbox, explicitly marks only the selected source message
   read for the actor, and queues provider `Seen` only for its active-account placement, while other
   users and later messages keep personal unread state. One Ticket may link several independent Email
   conversations; one account-scoped conversation has at most one primary Ticket for automatic
   routing. Standards-based headers and durable links extend, but do not replace, the existing Nexum
   Ticket-number correlation. Svein specified and accepted this direction on 2026-08-11.
8. Replies from customers, CC participants, suppliers, or other providers continue into the linked
   conversation and its primary Ticket. Recipients and reply subject derive from the selected source
   message without stripping another provider's Ticket token. Reply audiences remain isolated per
   conversation, and third-party conversations are internal-only in the customer portal until an
   explicit authorized portal-publish action. Svein explicitly accepted this portal default on
   2026-08-11.
9. Starting a new supplier/vendor/third-party conversation from a Ticket is a new dedicated guarded
   action, separate from the existing customer-reply path. It requires Ticket and Email send authority,
   an exact From/recipient/audience preview, and explicit confirmation for a manually entered new
   recipient; it never auto-creates a Contact and remains portal-internal by default.
10. The non-configurable security/lifecycle floor includes verified provider transport and endpoint
   handling, fail-closed attachment quarantine, local/installation-controlled search by default,
   immediate revocation and execution-time send authorization, scoped legal hold/DSAR/export, honest
   backup expiry, and cleanup of cache/index/AI artifacts without erasing separately captured Ticket
   evidence. Exact scanner/search/provider products remain slice decisions.
11. Automatic external replies remain separately gated and are not implementation-authorized by the
   general RFC approval alone.

## Approval

Approved by Svein in conversation on 2026-08-12 in direct response to the request for approval of the
complete RFC and all four related ADRs. The approval includes decisions 1-11, including the detailed
source-message/read-baseline scope, merge and not-Ticket compatibility rules, retained Ticket-key
aliases, the guarded third-party recipient policy, and the non-configurable security/lifecycle floor.

The four related ADRs are accepted by the same approval. Implementation has not started and must be
performed through separately scoped Feature Slices on authoritative Dev with their required tests,
documentation, deployment checks, and human review. This approval does not itself execute migrations,
change providers, enable external AI disclosure, activate production behavior, or publish anything
externally. Automatic external replies remain excluded and require a later explicit approval and ADR.

### Subsequent Completion Approval - 2026-08-16

Svein subsequently instructed Codex to complete **all** remaining documented Mail slices, followed by
the explicit clarification: `Ta alle slices. Også det som ikke er godkjent enda.` This is recorded as
the separate product approval required for the remaining program, including the restricted automatic
reply slice that the 2026-08-12 general approval intentionally excluded.

The ordered remaining work is reconciled in
`docs/plans/2026-08-16-email-mail-completion-slice-index.md`. This later approval authorizes scoped
design and implementation; it does not itself enable automatic replies, execute a migration, call an
Email/AI provider, change production, waive a required architecture or security review, or mark any
human-review entry `Reviewed`. Restricted automatic replies remain off by default until their
allowlists, sensitive-case exclusions, layered opt-ins, loop/duplicate/rate/cost limits, delivery
reconciliation, emergency stop, no-send-on-uncertainty behavior, tests, operational review, and named
human review are all complete.

### Account Setup Amendment Approval - 2026-09-01

Svein explicitly rejected the subsequently implemented provider-first administration workflow and
approved replacing it with the final account-owned flow described above. The approval requires one
ordinary Email account surface where an administrator can add or edit IMAP/SMTP settings, re-enter a
wrong password or server, and run the connection check again without creating another provider.

Terms and controls such as `Staged`, credential versions, `Legacy mailbox migration`, migration
preview, provider cutover and replacement provider are not part of the ordinary administrator
workflow. After the first simplified implementation still exposed an "older internal connection"
warning, Svein clarified that no old operational system may remain. Deployment therefore performs
one automatic, transactional, fail-closed promotion of exactly verified provider credentials into
the existing account, destroys the obsolete duplicate ciphertext, disables the old lifecycle
routes and makes Mail runtime accept only account-owned credentials. Historical rows may remain as
inert audit evidence, never as a configurable or runnable second system. This approval supersedes
the provider-first UI and ordinary password-credential ownership parts added after the original RFC
approval. It does not weaken the mandatory TLS, endpoint, secret encryption, authorization, audit,
bounded-I/O, no-blind-send or human-review requirements.
