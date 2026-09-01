The Email Domain owns inbound email accounts, mailbox access, inbound message storage, inbox triage,
email rules, email templates, account health checks, and IMAP polling.

The Tech inbox is available at `/tech/inbox`.

Admin email account settings are available under `/tech/admin/settings/email/accounts`.

## Current Dev Rollout Status

Mail completion migrations `2026_08_24_110000`, `120000`, `125000`, `130000`, and `140000` ran in
Dev batches 124 through 128. All live/Vite/operations/collaboration/UI/acknowledgement gates remain
off. Both Dev accounts use Integration-owned binding version 2 and overlap-safe polling. Dev has one
database `email,default` worker but no full Laravel scheduler or `notifications` worker. The 136
unattempted inbound fanout jobs require an explicit human delivery decision because relevant Web
Push settings are already enabled. Production remains untouched and still needs additive permission
migration `2026_08_21_100000` when promoted; never substitute the full `RoleSeeder`.

Order 8 live invalidation is code-complete on Dev but remains disabled. Account/user/role authority
changes and scheduled delegation/emergency-access boundaries now force a bounded current-view
refresh through durable generations and a minute maintenance job. Migration `2026_09_01_100000` ran on Dev in batch 22 and
restores the database writer guards. Do not enable Reverb or the live client until the named
real-provider, worker, scheduler, proxy, socket-loss, and two-user checks in `HR-2026-08-16-008`
have passed.

## Mailbox Ownership And Access

Email accounts are classified as shared, personal, or system mailboxes.

- Shared mailboxes are real provider-backed IMAP/SMTP accounts operated by users with explicit
  mailbox grants.
- Personal mailboxes have exactly one owner and are private to that owner by default.
- System mailboxes are used for documented system or workflow purposes.

Mailbox content access is separate from account configuration. `email.account_manage` allows an
administrator to configure provider settings, health checks, kind, owner, Ticket ingress, and grants,
but it does not by itself allow reading message bodies or attachments.

Shared and system mailboxes use three independent grant flags:

- `View`: list/open mailbox content and download allowed attachments.
- `Organize`: perform Inbox management actions such as spam/archive, delete, and manual polling.
  User-facing organize actions also require View.
- `Send`: marks the account as a permitted sender identity for later outbound slices.

The global permissions remain ceilings. A user needs `email.inbox_view` plus a mailbox View grant to
see content. Mutating Inbox actions require `email.inbox_manage` plus View and Organize grants.

Existing accounts are migrated to shared mailboxes with Ticket ingress enabled and explicit grants
for active users so current support-mail routing remains compatible. New shared/system accounts do
not expose content until Admin grants access.

Personal accounts always have Ticket ingress disabled by the Admin form and by runtime policy. Mail
for a personal account can be stored and shown to the owner, but it does not run the legacy shared
classifier, shared/system Email rule, Sales routing, or default Ticket routing automation. Owner
scoped personal simple rules can still run safe provider organization actions such as archive or
move to folder.

### Personal Mailbox Delegation And Emergency Access

A personal mailbox remains private to its active owner. Legacy direct account grants are not an
access source for personal mailboxes; ordinary access can come only from ownership or an explicit,
time-limited delegation created by the owner. Shared and system mailbox grants continue to use the
normal grant matrix.

The owner manages delegations from **Mailbox access** in Mail. One delegation names one active human
delegate, an exact combination of View, Organize, Send, and optional raw-source access, a reason,
start time, and expiry of no more than 31 days. Only the current active owner may create or revoke
it. Renewal creates a new record rather than silently extending the existing history. A delegation
never grants more authority than the owner currently has.

**Emergency mailbox access** is a separate administrator surface for active human security
operators with `email.break_glass_activate`. Activation requires typed mailbox confirmation, a
reason, exact read/search/attachment operations, and an expiry of no more than 120 minutes. Raw
source also requires `email.raw_source_view`. Emergency access never permits personal unread state,
AI or Smart Inbox, Ticket actions, sending, provider organization, rules, configuration, export, or
deletion. The Mail workspace labels an active emergency session and its expiry prominently. It may
be revoked by the activating operator, the current active mailbox owner, or another active human
operator with the activation permission; `email.break_glass_audit` alone is read-only.

Activation schedules an after-commit internal notification to the active owner and active security
auditors. Delivery retries are idempotent, and the notification links to the metadata-only mailbox
access history. That history records delegation and emergency lifecycle events and explicit
emergency content view, search, attachment download, and raw-source use. It never stores subject,
participants, filenames, snippets, body, raw source, search terms, credentials, attachment bytes,
AI data, or Ticket content. Sensitive content access fails closed when its audit event cannot be
written. Access-event history is append-only and retained even when a source delegation or emergency
record is removed; ordinary rollback refuses to discard non-empty durable access history.

## Email Account Connection Setup

Password-based IMAP and SMTP settings are managed directly on the Email account under **Admin >
Email > Email Accounts**. The account form owns mailbox identity, defaults, owner, Ticket ingress,
access grants, server settings, and connection health. There is no separate provider-selection or
mailbox-migration step in the ordinary workflow.

**Add account** opens one mail-client-style form for the email address, display name, IMAP
server/port/security/username/password, SMTP server/port/security/username/password, mailbox purpose,
and access grants. **Save and test connection** encrypts the write-only passwords and queues one
bounded check that authenticates to incoming and outgoing mail independently. The account stays
inactive while testing and becomes active only when both checks pass and activation was requested.

When a check fails, open and edit the same account. A blank password field preserves the saved
password; a value replaces it. Correct the server, port, security, username, or password and save to
test again. Safe alerts distinguish incoming and outgoing results without displaying raw provider
responses, resolved addresses, certificate internals, credentials, or ciphertext.

Every durable Mail job freezes the account binding version and rechecks it immediately before
IMAP/SMTP. Saving new settings makes an older queued check stale, so an old result cannot activate a
newer configuration. Queue, session, event, audit, and diagnostic payloads contain no runtime
credentials.

Older internal provider rows may remain as inert historical audit evidence. Deployment promotes an
exactly verified binding into the same account and destroys the obsolete duplicate ciphertext; the
mailbox identity, messages, Ticket links, health state and access grants do not move. Ordinary
navigation and runtime do not expose or use the retired provider lifecycle.

No failed or unavailable account falls back to another credential source or Laravel's system
mailer. This also applies to Ticket, Sales, Marketing, Commercial, Customer Portal, Storage,
Booking, notifications, and password-reset email. An ambiguous SMTP acceptance is recorded for
review and is never blindly retried.

The setup change includes a one-way, fail-closed migration and no seeding. Back up the database,
run `php artisan migrate --force`, clear Laravel caches and restart the long-lived `email,default`
queue worker. The external every-minute Laravel scheduler runner remains required for polling,
health checks, reconciliation, and scheduled Mail work.
Controlled production account and receive/send checks are tracked under `HR-2026-09-01-001`.

## Provider Folders And Placements

Email now records provider folders as first-class sync records. Each active IMAP account can discover
folders such as INBOX, Sent, Drafts, Archive, Trash, Junk, and custom folders. The Email account list
shows how many folders are discovered, the INBOX UIDVALIDITY value, and any folder sync issues.

Provider folders are authoritative for message placement and standard mailbox state. Nexum stores a
projection so later Mail views can show real folder placement without pretending the local database
is a separate mailbox truth.

The Folders section in the Mail sidebar shows this projection as an expandable hierarchy for each
mailbox. Parent, child, and deeper folders follow the provider's real path; selecting a child opens
that exact folder rather than combining everything below it. The selected folder's parent branches
open automatically. Provider containers that cannot hold mail may appear as labels when they are
needed to reach a selectable child, but they cannot be selected. A stale deleted container or leaf
does not appear. Folder number badges mean **mailbox unread** for that physical folder only. They are
not Nexum **Unread for me** counts and do not include unread mail from subfolders.

Each stored message can have one or more mailbox placements. A placement records the account, folder,
UIDVALIDITY, UID, provider flags such as Seen/Answered/Flagged/Deleted/Draft, sync status, and last
reconciliation time. Existing `email_messages` rows remain as compatibility message/content records
during this shadow period.

The first discovery of a folder records a forward-only `UIDNEXT - 1` baseline and does not import old
folder history. Later polls fetch only new UIDs after that folder's high-water mark. If a folder's
UIDVALIDITY changes, sync for that folder fails closed until an operator explicitly re-baselines it.

Technicians with mailbox Organize access can manage custom provider folders from the Mail sidebar
when Mail can identify one mailbox, either from the mailbox filter, the selected folder, or the only
organize-authorized mailbox available. The gear button in the Folders header opens the current
mailbox folder manager with an expandable folder tree. Parent folders are collapsed by default,
subfolders are visible after expansion, and rows without available actions show blocker reasons.
Nexum creates custom folders at root or under a selected parent, and renames, moves, or deletes safe
custom leaf folders on the IMAP server first before projecting the acknowledged provider folder
state locally.

Non-INBOX folders are cached as mailbox state only. Sent, Archive, Trash, Drafts, Junk, and custom
folder placements do not run legacy Ticket, Sales, Signal, or Email-rule ingress. Existing Inbox
workflows remain limited to INBOX placements.

Provider write operations are represented by an idempotent remote-operation ledger. The Mail
workspace labels these as mailbox actions and supports explicit mailbox read/unread, flag/unflag,
archive, normal trash, and same-account selectable-folder move actions for users with effective
Organize access. Permanent provider delete and unrestricted generic bulk actions remain separate;
Smart Inbox cleanup supports only an exact reviewed max-50 Archive/Move selection with per-item
authorization and results.

### Provider-Originated Reconciliation

**Mailbox maintenance** includes a separate provider-reconciliation cycle for active external
mailboxes. It reads every enabled selectable provider folder and reconciles provider-created flag,
placement, move, copy, Trash, expunge, reappearance, and folder-lifecycle changes into Nexum. The
provider remains authoritative for those mailbox facts. Nexum's **Unread for me**, opened receipts,
Ticket/Signal links, collaboration state, and audited local workflows remain independent.

Reconciliation is read-only at the provider. It uses bounded LIST, EXAMINE, UID SEARCH, metadata
FETCH, and exact `BODY.PEEK` operations. It never sends, appends, sets flags, moves, copies, deletes,
expunges, changes folders, or retries an unresolved provider mutation. Admin and personal rules may
run only for a genuinely new live Inbox delivery after stable correlation, and their provider-write
authority is disabled for this path.

A run freezes its Integration provider binding, byte-exact folder paths, active UID namespaces,
folder start/end facts, local placement snapshots, and bounded inventory hashes. A missing placement
or folder is projected only after complete stable evidence; a missing folder needs two stable cycles.
A UIDVALIDITY change blocks that folder for explicit re-baseline. A mailbox without usable MODSEQ
must complete two matching post-import UID+FLAGS inventories before flags or absence are projected.
IDLE is only an optional latency hint; scheduled reconciliation remains the correctness path after a
lost, duplicated, reordered, or oversized hint.

Unknown messages are committed as hidden pending occurrences before their private raw/attachment
files are written. Visibility, Draft/Sent projection, conversation aggregates, and later automation
are enabled only after exact artifacts, canonical self-mapping, placement namespace, current run,
and local-version evidence agree. A duplicate Store delivery does not rewrite an unrelated
PREEXISTING active occurrence; it may only resume an exact hidden reconciliation-pending crash row.
Historical content in a newly discovered folder stays hidden until its bounded viewer-read baseline
completes, so importing history does not flood **Unread for me** or replay Inbox workflows.

Live Inbox automation waits until the whole account scope is stable. A confirmed provider move or
copy, a same-run duplicate, weak identity, conflicting local operation, failed import, or drifting
source is suppressed or failed closed. Only a strong delivery with no pre-run or same-run peer can
enter the normal rule and Notification pipeline. A confirmed exact move preserves per-user read and
opened state only when the source/target identity, namespace, UID, local version, and user-state
uniqueness all agree; otherwise the source remains visible as a conflict.

External Email, Web Push, and Nextcloud Talk delivery for the accepted inbound event uses a durable
Notification-owned outbox. The canonical in-app notification and payload-free outbox row commit
together. Before external delivery, Notification rechecks the current recipient, source, event type,
channel preference, mailbox/Ticket access, and frozen Email provider binding. Recipients who are
revoked or no longer eligible are suppressed. An uncertain or abandoned external attempt is retained as
unresolved and is never blindly replayed.

The scheduler evaluates due reconciliation every minute in durable pages of 50 accounts; the normal
interval is five minutes. Operators may run `php artisan email:reconcile-provider` for due accounts,
or add `--account=<id>` for one explicit catch-up and `--async` to queue the dispatcher. Folder,
import, baseline, automation, cancellation, and final-summary work is bounded and resumes from durable
cursors after dispatch or worker loss. The `email`, `default`, and `notifications` workers and the
external Laravel scheduler must be supervised; `email-idle` is required only when optional IDLE is
enabled.

Current Dev intentionally runs only targeted polling plus `email,default`. Do not start the full
scheduler or `notifications` worker until the 136-job notification cohort has been reviewed.

Cancel records an idempotent intent first. A transition job then waits for the same account provider
lease used by in-flight reads, changes the run to `cancelling`, and lets bounded finalization drain
pending work without publishing hidden content. The maintenance page may therefore briefly show an
active run with cancellation requested; repeated Cancel requests are safe.

Order 7 has released focused automated evidence. Its exact 20 migrations `100000` through `118500`
now report Ran one per step in recovered Dev batches 98-117. Browser, controlled-provider, scheduler, worker,
queue/backlog, and rollback smoke remain operator-gated. `HR-2026-08-16-007` preserves Svein's
2026-08-19 review and records the 2026-08-24 reopening for folder-cap/progress/runtime changes and
current checks. Automated SQLite and disposable MariaDB contracts do not replace current runtime
checks.

Controlled account-2 run 2 read all 137 provider folders and finished terminal `stale` in `summary`:
131 folders completed, 6 became stale with `provider_tuple_drift`, 8,427 observations and 7 confirmed
missing items were recorded, and moves/conflicts/errors remained zero. Provider-wins local projection
hid seven placements and soft-deleted caches where no active placement survived. Three pending
observations are confined to the stale folders. The run issued no provider write and delivered no
notification. The clean complete Email Feature directory passes 686 tests / 7,046 assertions.

## Mail Workspace And Personal State

The technician Mail workspace is available at `/tech/mail`. It is the new operational entry point for
reading provider-backed mailbox content. The older `/tech/inbox` screen remains available as a
fallback view for unrouted INBOX triage.

Mail uses provider folders and mailbox placements as the source of truth for where a message exists.
The default Mail view is `Unread for me`, which shows authorized provider Inbox placements that the
current user has not explicitly marked read in Nexum. `Inbox` shows authorized Inbox placements
regardless of personal read state. `All mail` shows authorized non-trash and non-junk placements.

Technicians with mailbox Organize access can trigger manual sync directly from Mail. `Send/receive`
queues the existing IMAP fetch job for every active mailbox the technician may organize. When a
folder is selected, the Folders header can also show a refresh icon for that folder's mailbox.
Folder refresh intentionally queues account sync because provider folder discovery is account-wide;
this lets Nexum notice folders renamed, created, or removed in another IMAP client without running
IMAP work inside the browser request.

The Mail message list is grouped as account-scoped conversations. Nexum derives the group from
conservative RFC thread headers where possible and prefixes it with the mailbox account, so a copy of
the same message in another mailbox remains a separate conversation. The visible row is the newest
matching placement in the current view, and grouped rows show compact message-count and unread
badges. Opening a row still opens one real provider placement. The reading pane renders the visible
conversation as a compact thread: the selected message is expanded, the other messages stay collapsed,
and clicking a collapsed row makes that placement active. Reply, Forward, Ticket, AI, attachment, and
provider actions always apply to the selected/open placement only.

The selected center-list row also expands automatically with a compact indented list of the emails
in that authorized conversation. The child list follows the same newest-first order as the reader;
clicking one child selects that exact mailbox placement and highlights the same email in the reader.
Only one conversation expands at a time, and a one-email conversation is not duplicated. The parent
row remains qualified by the current mailbox, folder, search, and filter. Its expanded children may
include authorized context from another folder such as Sent or Archive, so the interface labels the
count in the current view separately from the full conversation count when they differ.

The left Folders navigation follows the provider's real parent/child hierarchy. Branches start
collapsed. When a technician opens or closes a branch, Mail remembers that choice across sessions
and devices; selecting a nested folder opens and remembers its ancestor path. A passive deep-link or
reload does not override a branch the technician explicitly closed, so that ancestor receives a
visible and screen-reader label that it contains the current folder. These personal rows never grant
mailbox access, and a stored choice is ignored whenever current mailbox View access is missing. A
non-selectable provider container can reveal selectable children but cannot itself become a folder
filter.

Mail also makes encoded subject headers readable in the Inbox list, conversation list, expanded
children, reader, and Reply/Forward composer. Common UTF-8 and legacy Q/Base64 encoded words are
shown as normal Unicode, including a conservative readable prefix when the provider returned a
truncated final encoded word. Subject text is still escaped and is never interpreted as HTML. This
display compatibility does not rewrite the stored provider subject or change conversation matching,
rules, TD/SO Ticket references, API data, or provider evidence. Smart Inbox continues to fingerprint
the raw message content rather than the friendly presentation value.

Readable historical subjects are also searchable. `/tech/mail`, legacy `/tech/inbox`, and the Inbox
API share the same local search fields: stored raw subject, a hidden derived readable-subject
projection, sender name/address, and plain-text body. `%`, `_`, and `!` are treated as literal search
characters rather than SQL wildcards or an escape sequence. Every search remains inside the current
mailbox View, account, folder, Ticket/state, grouping, and pagination boundaries. The projection is
only a rebuildable search aid: stored raw subjects, conversation identity, rules, Ticket correlation,
and provider evidence remain unchanged, and API responses continue to return the raw `subject`
without exposing the derived field.

On recovered Dev, the initial nullable `subject_search` column/backfill migration `121000` ran in
batch 95. The forward-only `121100` rebuild ran in batch 96. Dev review then exposed a historical MariaDB
`received_at` definition with implicit `ON UPDATE CURRENT_TIMESTAMP`; that database clause, not the
projection code, advanced receipt evidence during the rebuild and falsely staled five Smart Inbox
suggestions. Migration `121200` ran after recovery in batch 97, removed the clause, and froze a 490-message audit
scope. Preview/apply restored 471 values supported by deterministic evidence (439 header dates and 32
conversation boundaries), left 19 unresolved candidates untouched, and recovered exactly the five
matching false-stale suggestions. MariaDB now reports no `ON UPDATE` clause.

For another environment, pause ordinary/default and `email` workers, run `121000`, `121100`, and
`121200` in order, preview `php artisan email:repair-received-at`, and apply it only after reviewing
the exact evidence counts. Never guess the unresolved dates. Then clear Laravel caches, rebuild views
with group-write preserved, restart/resume every worker, and push the Email Knowledge sync. Human
review remains Pending under `HR-2026-08-15-004`. Receipt repair plus adjacent Smart regressions pass
36 tests / 408 assertions; an earlier combined repair/reader package passed 47 / 578.

The account-scoped conversation identity is stored in `email_conversations` and linked from mailbox
placements. Inbound storage resolves uniquely matched `References` / `In-Reply-To` messages so a
root, direct reply, and nested reply stay in one durable conversation inside the mailbox account.
Reused or conflicting `Message-ID` evidence never forces unrelated mail together. The same
identifiers in another mailbox account remain a separate conversation, and only unambiguous existing
split projections are forward-reconciled. Unresolved evidence is retained for review without
changing provider or Ticket state. Existing message-level Ticket correlation and `TD-...` routing
stay in place; Ticket conversation links add the durable conversation pointer without granting Mail
access from Ticket access.

Legacy messages that already have exact Ticket capture evidence but lack that durable conversation
pointer are repaired only through an administrator-run preview/apply workflow. Preview is read-only,
bounded, expires after 15 minutes, and records safe per-item status/reason evidence. Apply requires
the same active authorized human and runs through the Email queue. Missing or conflicting Ticket,
account, conversation, placement, capture or audience evidence is left blocked for review; Nexum
never chooses a Ticket from competing claims. The repair does not move or mark provider mail, change
`Unread for me`, recapture content, run rules, send notifications, publish to the portal, or alter
existing `TD-...` behavior. Human review `HR-2026-08-16-013` must pass on a disposable data copy
before shared-data apply.

The grouped center list chooses one leader per durable conversation in the database before normal
pagination, so Mail does not load every matching placement merely to group it in PHP. The reader
always reapplies the selected account boundary. Durable threads load only the exact account and
conversation ID. The old header-based compatibility fallback applies only to same-account placements
that still have no durable conversation; it starts with a bounded set, offers **Load more**, caps at
200, and keeps a directly selected older placement readable without scanning another mailbox. The
center-list expansion reuses this already loaded, authorized thread; it does not load child messages
for every paginated conversation row.

Provider `Seen` is separate from Nexum's `Unread for me`. The compact conversation and expanded-child
lists show only the signed-in technician's personal **Unread** badge. Provider state remains visible
where it is operationally useful: the mailbox-unread filter, provider folder counts, detailed reader,
and explicit mailbox actions continue to label it as `Mailbox read` or `Mailbox unread`.
Opening a message records that the user opened the authorized content, including timestamp, placement,
and open count, but it does not mark the message read and does not update provider `Seen`.

Personal unread uses one mailbox-access epoch and local message-ID baseline per user. Mail that was
already stored when a technician first receives an ordinary shared grant or personal delegation is
read for that new epoch unless the technician explicitly changes it; mail stored after access starts
is unread regardless of provider `Seen`. Editing an uninterrupted grant or temporarily disabling the
account/user keeps the same epoch. A real ordinary-access gap followed by re-grant starts a new epoch
without overwriting the earlier personal state. Overlapping ordinary sources remain one continuous
epoch, while emergency-only access has no personal unread surface and never creates one. Historical
imports insert read-for-me state only for the affected users' current epochs and never acknowledge
provider mail.

An authorized shared-mailbox manager, or the owner of a personal mailbox, can open **Unread
handover** from the relevant account/access page when old work must deliberately become unread for
one current human viewer. Preview requires the exact mailbox, user, selectable synchronized folders,
received-date window, reason, and a maximum of 1–500 messages; it expires after 15 minutes. The page
shows scope, counts, IDs-derived status, and safe error codes only—never subjects, participants,
snippets, bodies, attachment filenames, raw source, or credentials. Apply rechecks the same actor,
target access, epoch, folders, placements, and immutable snapshot before setting only that user's
current-epoch Nexum state to unread. Provider `Seen`, other users, later arrivals, folders, rules,
Tickets, notifications, AI, and remote operations stay unchanged.

The unread baseline/handover implementation passes 13 focused tests / 118 assertions, 30 full
historical-import plus delegation/break-glass tests / 340 assertions, and the broad affected-module
runs of 171 / 1,548 plus 157 / 1,063. No live migration or provider operation was run for this slice;
named Dev browser and migration review remains Pending under `HR-2026-08-16-003`.

### Conversation acknowledgement safety status

Conversation-wide acknowledgement is still unavailable in the Mail interface and remains off by
default. The safety backend now requires a separate preview before apply. A preview freezes only the
currently active placements in the selected account conversation, or exact placements the user
explicitly selected across accounts. It does not add related mail based on subject, Message-ID,
Ticket links or correlation. New mail arriving afterward is outside that preview and stays Unread for
me.

Every selected account needs ordinary View at preview and apply time. Provider **Mailbox read** is a
separate optional effect and additionally needs Organize for that exact account/placement.
Break-glass cannot change personal or provider state. Apply rechecks the same user, access epoch,
conversation, message, folder, placement, UID namespace/UID, sync version and provider connection
binding. Changed or revoked evidence is reported as denied/stale instead of being replaced by another
message or account.

Personal **Unread for me** changes go through the normal current-epoch personal action. Provider
Seen is only recorded as a pending exact remote operation and remains pending until the existing
provider reconciliation proves success. A provider denial, conflict or failure never erases a valid
personal acknowledgement or appears as provider success. Preview/result evidence contains IDs,
fingerprints, statuses and safe reason codes, not subjects, participants, bodies, attachment names,
private paths, credentials or raw provider exceptions.

If one message is visible in several active folders, personal Unread is still changed once for that
message and access epoch. The first frozen placement carries that selected effect and later placement
rows are shown as coalesced; provider Mailbox read remains a separate per-placement result. A failure
on the selected personal effect is reported as a failure, not hidden by the coalesced rows.

Forward migration `2026_08_24_140000_create_email_conversation_acknowledgement_action_ledger.php`
adds the run/item ledger and refuses rollback after evidence exists. It ran in Dev batch 128 and the
ledgers remain empty. Historical `2026_08_19_150000` stays an inert marker and creates no old acknowledgement table.
Keep `EMAIL_MAIL_ACKNOWLEDGEMENT_ENABLED=false` until the accessible preview/confirmation interface,
continuation/retry operations, dependency checks and named review `HR-2026-08-16-012` are complete.

Only the explicit personal read controls change Nexum `Unread for me` state. The main command bar
shows one `Mark read` action when the selected message is unread for the current user; `Mark unread
for me` is available from More actions after it has been read. These controls affect only the current
user. They do not change other users' personal state, provider flags, Ticket unread state, or
Notification read state.

The Mail workspace currently supports read/search/triage orientation: mailbox and folder navigation
in the sidebar, conversation-grouped message-list search and compact list filters, sanitized reading
thread pane, attachment metadata, Ticket link when a related Ticket exists, and active-message
selection inside the reader. On desktop, the list and reader use equal available-height panes and
consume their full remaining height before scrolling independently; denser desktop conversation rows
leave the existing stacked mobile/tablet flow unchanged. The message-list filters can show all messages in the current
mailbox/folder scope, personal unread, mailbox unread, flagged, messages with attachments, or
Ticket-linked messages. The list header includes Compose for users with at least one send-authorized
account. The command bar keeps common triage actions compact: Reply, Reply all, Forward, personal
Mark read, Spam, Ticket, Trash, and More.
Users with Organize access can explicitly mark the selected mailbox placement read/unread,
flag/unflag it, archive it, or move it to another same-account selectable provider folder from More,
and can move it to mailbox Trash through the visible trash icon when the provider has exposed the
target folder. These provider actions do not change another user's personal `Unread for me` state.

The Spam icon updates the Email spam rule and tag through the existing Email action. When the account
has a selectable Archive folder, it also archives the provider placement through the same remote
operation path as the Archive action. The Ticket icon creates or links a Ticket from the selected
email when the user has `ticket.create` and mailbox Organize access; already linked email shows an
Open Ticket icon for users with `ticket.view`. It also records a Mail-owned conversation link so one
Ticket can collect several independent email conversations. Link existing Ticket is available from
More for non-draft messages when the user has `ticket.update` and mailbox Organize access. Add rule
is available from More when there is a safe
target for the selected message. For the owner of a personal mailbox it opens a compact modal with
matched rule history and a simple rule editor for from/domain/subject/to/cc conditions plus move or
archive actions. For shared or system mailboxes, users with `email.rule_manage` are sent to the Admin
rule builder with the selected mailbox and sender prefilled.

When the user has a selected Email agent or global fallback agent and Integration governance allows
that agent/model runtime, the selected message command bar shows an AI summary icon. Mail AI
rechecks mailbox View access, sends only bounded authorized message text and mailbox metadata, and
displays a read-only panel with summary, key points, questions, action items, suggestions, urgency,
reply-needed state, and provenance notes. Raw source, HTML, attachment contents, and attachment
filenames are not included. The panel is advisory only and does not send mail, create drafts, move
messages, change Taxonomy, create Tickets or Tasks, create rules, or run external tools.
Separate write-gated Mail AI buttons may appear only after the selected/default agent is action
enabled, has the required API write scopes, and the user has the normal Ticket and mailbox
permissions. The first such action is AI-summary-assisted Ticket creation; the technician must still
click the button, and Nexum uses the deterministic Mail-to-Ticket flow.

Users with effective mailbox Send access can reply, reply all, forward, or compose new messages from
`/tech/mail`. All modes open the same compact rich HTML composer with editable From when applicable,
To, Cc, Subject, Message, formatting controls, optional HTML source mode, and attachment controls.
Reply defaults To to the source sender and Subject to `Re: ...`; Reply All adds the source sender and
stored To/Cc participants while excluding the selected mailbox itself. Reply All is shown only when
that computed recipient list has more than one recipient. Forward starts with an empty To field,
Subject `Fwd: ...`, and a safe forwarded-message block with original sender, recipients, date,
subject, and readable body.

When the selected/default Email agent is ready under Integration policy, the shared composer shows
AI text controls for eligible modes. Reply and Reply All can draft a reply, improve existing composer
text, shorten it, warm the tone, or rewrite it in Norwegian with optional guidance. Compose can use
the rewrite controls from a send-authorized account even when the technician has no mailbox View
grant for that account; no source message is sent in that case. Forward can use the rewrite controls
for the technician's introduction while Nexum preserves the original forwarded-message block. Mail AI
receives only authorized message text when a selected source exists, bounded conversation text when
allowed, subject, composer plain text, intent, and guidance. Composer HTML, attachments, attachment
names, and raw source are not sent. When AI returns sendable text, the result replaces only the
editable composer body after being escaped into safe HTML. If AI determines that no reply is
recommended for a Reply or Reply All, such as for an automated alert or status notification, the
composer body stays unchanged and the reason is shown as an advisory status. Recipients, subject,
attachments, folders, provider flags, Tickets, Tasks, rules, categories, and tags are not changed.
The user still has to press Send manually. AI apply results, no-reply advice, and composer AI
availability errors are shown inside the open composer instead of as a page-level Mail alert.

The composer keeps private local Nexum drafts for Compose, Reply, Reply All, and Forward. Each draft
belongs to one active human technician, mailbox context, opaque generation, and signed version.
Shared drafts remain unavailable while Mail collaboration is disabled. Autosave runs
while fields change, Save draft persists the current fields explicitly, Close keeps changed local
draft content, Discard draft prevents later restore, and confirmed SMTP acceptance marks the
matching draft sent even when Sent follow-up later warns. An unresolved transport outcome leaves the
composer open but blocked from another call with the same reservation. Drafts restore only for the
same technician and same sender account or selected mailbox
placement. Draft attachments are stored durably with the local draft, restored in the composer, sent
through SMTP, included in manual provider Drafts sync, and cleaned up when the draft is sent or
discarded. Before sending, saved attachment IDs are rechecked against that exact active draft and
the currently authorized mailbox composer context. Manual Save draft also writes a provider Drafts
copy only when Mail can re-infer an exact selectable, sync-enabled Drafts folder from current
SPECIAL-USE/exact-leaf evidence. This prevents a stale stored role on a child folder from becoming
the APPEND target. Before contacting IMAP, Mail stores one tokenized durable reservation. A fresh
reservation blocks concurrent saves; only a pre-write reservation older than five minutes may be
taken over. If the provider response becomes uncertain after APPEND starts, later Save and queue
runs reconcile the same Message-ID and never replay APPEND. After the provider accepts the one
reserved append, Mail queues one bounded refresh for the exact draft, mailbox, and Drafts folder.
The refresh checks the established folder UIDVALIDITY, shares the normal account-fetch lock, reads
only new UIDs after the local high-water mark, and imports
only the matching Message-ID with Inbox automation disabled. This normally makes the saved copy
appear in the Drafts view as soon as the ordinary queue worker runs. Mail does not treat the
pre-APPEND UIDNEXT hint as final message identity; the imported provider UID remains authoritative.
If the copy is not visible yet, its status stays pending and normal account sync remains the fallback.
Autosave stays local-only to avoid creating a new provider draft for every field change. Draft save,
restore, provider Drafts sync, and draft attachment messages appear as compact composer-local status
while the composer remains open; send and discard completion remain page-level feedback because
those actions close the composer.

### Presence and shared-draft safety status

Order 9's backend and accessible composer controls are implemented but unavailable by default.
When all existing gates are ready, Reply/Reply all/Forward can explicitly Share draft, show the
current editor and expiry, remain read-only for other users, release the lease, and allow takeover
only after release or expiry. This does not activate Reverb by itself. Reading presence expires after 45 seconds, typing presence
after 25 seconds, and visible tabs refresh no faster than every 10 seconds. Presence is an expiring
cache hint only: it never changes Unread for me, provider Seen or opened-by history, and it creates no
SQL heartbeat or permanent activity record. Ordinary shared-mailbox View is required for reading;
ordinary View plus Send is required for typing. Cache/transport failure means the indicator is
absent, never that an old user is still shown.

A private Reply, Reply All or Forward draft can be shared only by its creator and only for the exact
ordinary shared/system mailbox conversation/source. Shared read requires current View. Editing,
attachments, rebase, discard and send require current View plus Send and one current 60-second lease.
The lease uses an opaque token, a monotonic fence and exact content/source versions; after explicit
expiry takeover, every old tab receives `423 Locked` and cannot overwrite or send. New inbound or
changed source/provider/audience evidence blocks provider actions until a previewed rebase is
confirmed. Rebase preserves the authored body and eligible draft attachments, while recalculating
source, recipients, subject and threading. A stale draft may instead be discarded under its exact
lease.

Shared send uses the same once-only outbound submission and Sent-reconciliation evidence as private
Mail. Authority, lease, fence, content and source are rechecked immediately before the durable
provider-write marker. Attachment files are removed only after the matching lifecycle/evidence
transaction commits. APIs expose safe lease holder/expiry/version state, never the lease token/hash,
internal generation, source fingerprint, storage path/checksum or provider exception.

Forward migration `2026_08_24_125000_add_email_shared_draft_coordination.php` ran in Dev batch 126;
the existing draft remains private and shared ledgers are empty. Keep `EMAIL_LIVE_ENABLED`,
`EMAIL_MAIL_COLLABORATION_ENABLED` and
`EMAIL_MAIL_COLLABORATION_UI_ENABLED` false until Orders 8 and 9, disposable migration review and
`HR-2026-08-16-009` pass. When either required server flag is false, the collaboration gate returns
unavailable before checking the optional schema. The legacy `MailWorkspace` SQL-lock/presence and
Echo whisper fallback has been removed; the workspace now requires private-live readiness, the
separate collaboration UI flag, and `EmailCollaborationGate`. Its shared composer uses the same
lease/fence/source checks and outbound submission as the API, including Ticket-selected replies. A
fresh default-off asset build selects
`app-DjAfqa_z.js` and contains no legacy presence/whisper activation marker.

Provider Drafts folder messages imported by normal IMAP sync are shown separately as provider draft
placements. `/tech/mail` has a Drafts view, a provider draft filter, and `Provider draft` badges in
the list and reader. These imported provider draft placements hide ordinary Reply, Reply All,
Forward, Spam, Ticket, and rule actions. Send-authorized users can open them with `Edit draft`, send
the edited content through SMTP, and clean up the original provider Drafts UID afterward.

Sending uses the chosen mailbox account's SMTP configuration. Nexum sanitizes outgoing composer
HTML, generates a plain-text fallback, and preserves In-Reply-To and References headers for reply
modes when source message headers are available. Before SMTP, it atomically reserves one immutable
outbound submission for the exact private draft generation/version, prepared signature/body,
threading, attachment manifest, provider binding, caller channel, and client idempotency key. It then
reserves the exact RFC `Message-ID` in the outbound Email log. Concurrent or repeated submission of
that snapshot cannot claim a second send. Forward does not automatically reattach original inbound
attachments; technicians may add new attachments deliberately.

An unexpected failure before provider delivery keeps the composer open and says the message could
not be prepared for sending. It does not expose database paths, credentials, or other internal
exception details and does not describe the message as provider-sent.

If the SMTP transport result cannot be confirmed after delivery starts, Mail keeps that reservation
as unresolved, blocks another send for the same key, and tells the technician **Do not resend it**
until provider Sent mail is reviewed. Ambiguity is never converted into a blind automatic retry. If
normal same-account Sent sync later imports the exact reserved Message-ID, it resolves that send as
accepted without calling SMTP again. Failure to create preliminary Sent tracking is recorded as a
reservation failure, not provider acceptance, and an exact Sent-sync confirmation cannot be
overwritten by a racing ambiguous SMTP result.

SMTP acceptance means the message was sent to the outbound provider. Storing the local raw Sent
snapshot and recording provider Sent reconciliation happen afterward. If either follow-up fails,
Mail still closes the composer, marks the matching local draft sent, and attempts provider-draft
cleanup. The status is a warning that the message was accepted but Sent tracking/storage failed, and
it explicitly says **Do not resend it**. A follow-up filesystem/database error is never shown as
`The message could not be sent`, because that wording could cause a duplicate delivery. Reusing the
same reserved idempotency key returns the accepted send or remains blocked as unresolved instead of
calling SMTP again.
If local draft cleanup itself fails after acceptance, the composer still closes with a sent warning
and the reservation blocks resend. The local draft remains `send_reserved`, not editable/active,
until reviewed.

The same private draft/send boundary is available under `/api/v1/email/mailbox`. Tokens need
`email.drafts.read` to list/show current private drafts, `email.drafts.write` to create/update/discard,
upload/remove attachments, or explicitly sync provider Drafts, and `email.send` to preview/send and
read the exact outbound/Sent-reconciliation status. Abilities are request ceilings: current human
status, normal Email permission, exact mailbox View/Send access, active source placement, private
ownership, opaque version, and provider binding are rechecked at use. Stale versions and bound or
unresolved submissions return conflict; inaccessible IDs return Not Found. Responses expose no disk
path, checksum, generation ID, Bcc, raw MIME, credential, or raw provider/exception evidence. A
timeout or conflict is not permission to resend.

Personal Mail signatures are owned by the Email module but edited from `/tech/profile`. `/tech/mail`
keeps the page AI chat at the top of the right bar, followed by the conditional collapsed Mailbox
operations card, a Mail signature card that starts collapsed, and the separate collapsed Mail AI
runtime status card. Expand Mail signature to reveal its settings trigger. The settings open in a
viewport-bound dialog with an explicit X, Cancel, and Save, so the controls remain above the page
footer on desktop and mobile. Each technician has one default
signature template using safe HTML and tokens for technician and company details, and can choose
whether it is included for Compose, Reply, Reply All, and Forward. The signature is appended after
the composer body is validated and immediately before SMTP, so Mail AI draft/rewrite controls never
rewrite the signature block.

Mail sending does not change provider `Seen`, personal `Unread for me`, provider folders, Tickets,
Signals, or customer-portal visibility. After SMTP acceptance, Mail records a pending provider Sent
reconciliation row. When normal IMAP sync later imports a Sent-folder copy from the same account with
the same `Message-ID`, Mail links it back to the outbound log and shows `Sent reconciled` on that
provider copy. Mail keeps backend support for appending a stored raw outbound snapshot to the
discovered provider Sent folder when no provider copy has arrived yet, but ordinary `/tech/mail`
users do not see that technical reconciliation work as a dashboard; final confirmation still comes
from normal Sent-folder sync. The backend reserves each technical append under lock and never repeats
an already-started, accepted, or ambiguously failed provider write. A new raw snapshot is also
removed if its reconciliation row cannot be stored, rather than leaving an untracked private file.

Custom provider folders can be created from the Mail sidebar when one organize-authorized mailbox is
selected. The new folder can be placed at mailbox root or under an existing parent folder and is
immediately selectable after the provider acknowledges creation. Safe custom leaf folders can also be
moved between root and parent folders from the same manager.
When provider mailbox operations fail, remain pending, run, or have a recent verified result,
organize-authorized users see a compact Mailbox operations card in the `/tech/mail` right bar. It
starts collapsed and keeps pending/running/failed/recent counts visible in its header. Expand it to
see the safe operational reason, provider-attempt count, total evidence records, failure
classification, next automatic retry time, and eligible Retry/Cancel/Undo controls. Running work
cannot be cancelled mid-provider-call.

Every retry rechecks the original requester's current mailbox Organize access, account activation,
and the snapshotted placement version, UID, UIDVALIDITY, and folder before contacting IMAP. Stale or
revoked work is superseded without a provider write. Transient failures retry automatically with
bounded backoff and a five-attempt limit. When a previous provider result is uncertain, Nexum reads
provider state first. It either reconciles an already-applied change, proves a replay is safe, or
leaves the operation blocked for review; uncertainty never causes a blind second mutation. A move
is safe to replay only after an authoritative target-folder inventory proves the target copy absent.
If source and target both exist, or provider folder discovery fails, the operation stays blocked.
An ambiguous Archive/Trash/Move row whose immutable target folder path or target UID is missing also
shows no Retry action, because no exact target exists to prove or replay safely.
Reconciliation reads do not consume the five provider-mutation attempts and can still establish the
outcome after that mutation budget is exhausted.

Before a message mutation or reconciliation fetch, Nexum checks whether the exact UID exists without
requesting message headers. If it no longer exists, the operation stops as stale, performs no
provider write, and does not show or persist the low-level `no headers found` error in user-facing
fields. Connection, authorization, UID-existence, and provider-read preflights remain sanitized audit
records but do not increment the mutation-attempt count. A real provider read failure is kept
separate for controlled manual recovery and is not retried automatically in a loop. Archive
and Trash choose the account's explicit provider SPECIAL-USE folder or the shallowest exact canonical
folder leaf; a custom child is never selected only because `Archive` or `Trash` appears in its parent
path. Normal folder discovery repairs that old descendant-role misclassification.

Provider preflight/mutation/reconciliation attempts retain metadata-only start/finish evidence. They
do not store message bodies, raw MIME, attachments, credentials, or provider secrets.

Recent provider-acknowledged Seen/Unseen, Flag/Unflag, Archive, Trash, and Move rows also show their
current verified Undo reason. Undo is offered for 15 minutes only when the immutable result snapshot
still matches the exact local placement, folder, sync version, UID, UIDVALIDITY, and provider flags.
Clicking Undo rechecks the current technician's Mailbox Organize access, account status, later
operations, and live provider state before any write. Seen/flag inverses stay on the same placement;
move/archive/trash inverses move the exact acknowledged target UID back to the still-selectable
original folder. Missing target identity, a reconciled/ambiguous source, changed provider state,
revoked access, or a later mutation stops without touching the provider. Repeat clicks return the
same linked inverse operation, and any uncertain inverse result uses the normal recovery ledger
instead of being replayed blindly.

The same provider actions are available to API clients through
`POST /api/v1/email/mailbox/placements/{placement}/operations` with one of `mark_seen`,
`mark_unseen`, `flag`, `unflag`, `archive`, `trash`, or `move`. Move requires `target_folder_id`.
The endpoint requires an API token with `email.update` plus the same global and mailbox Organize
authorization as the UI.

Recovery clients may list and inspect authorized operations through
`GET /api/v1/email/mailbox/remote-operations` and
`GET /api/v1/email/mailbox/remote-operations/{operation}` with `email.read`. Safe retry and cancel
use the corresponding `/retry` and `/cancel` POST endpoints with `email.update`; inaccessible
mailboxes return Not Found instead of revealing operation existence.

Verified Undo eligibility is available from
`GET /api/v1/email/mailbox/remote-operations/{operation}/undo` with `email.read`. The matching POST
uses `email.update`, creates or returns the unique inverse operation, and retains the same hidden-404
mailbox scope.

Mail classification is separate from provider flags. A mailbox flag is IMAP/provider state and is
shown as a yellow flag indicator in the list and reading pane. Category and tags are Nexum work
metadata using the existing Taxonomy definitions. One durable account-scoped conversation can have
one Email category and several tags when the user has mailbox Organize access; the editor is opened
from More actions so normal reading stays compact. Every placement in that mailbox conversation
shows the same assignment, while a correlated copy in another mailbox remains independent. Existing
active tags can be assigned from Mail; unknown tag names are created only when the user also has
Taxonomy tag-management access. Provider flags/folders, Ticket classification, and legacy
message-level routing tags are unchanged.

## Smart Inbox Review

The personal Smart Inbox button appears above the conversation reader in `/tech/mail`, while the
controlled results stay after the complete selected conversation. This keeps the button easy to find
without putting Smart content ahead of the email. It starts collapsed each time the message is opened
or revisited. Activate it explicitly to move focus/scroll to the labelled result region; its Close
control returns focus to the button. The button's expanded state and the result region are owned by
the same scoped Livewire component and remain keyboard and screen-reader operable.

**Analyze** is an explicit user action and appears only while the current Mail AI read boundary is
available. It reuses the governed Mail AI boundary and saves only normalized typed
suggestions bound to the requesting user, mailbox, conversation, selected placement, exact source
fingerprint, and AI provenance. Analysis alone does not change Mail, provider state, Ticket, Task,
Taxonomy, rules, or outbound email.

Each useful queue item shows its current status, reason, confidence, provenance, and effect impact.
Advisory review summaries have no Apply action. Pending items can be dismissed or corrected. Stale,
dismissed, revoked, unknown, or currently ineligible pending actions do not clutter the reader, and
the whole Smart surface disappears when nothing usable remains. Applied results stay visible as
history; durable rows/events remain available through their authorized audit/API paths. A read-capable
but write-disabled agent may still offer Analyze and review summaries while unavailable Apply, batch,
correction, and rule-prefill controls are simply absent.

Current presentation checks the recorded agent, exact scope, current user/account/mailbox/target, and
source fingerprint, while every direct action repeats its own server authorization. Fingerprint v2
ignores unrelated `updated_at` and derived-projection maintenance but still changes for real source
membership, subject/body/participant/receipt, attachment-count, or attachment-metadata changes.
Historical rows use their recorded v1 schema. When user/account/mailbox access is lost, the row is
revoked/hidden through ordinary endpoints. Another technician who can view the shared mailbox still
has a separate Smart Inbox queue.

Explicit reviewed Apply supports only:

- an existing active Email category, without replacing a different current human choice;
- an existing active Taxonomy tag, added without creating a new definition; or
- one editable internal Task through Task's normal permission and Work Context rules, without an
  invented assignee or due date.

The exact AI agent recorded on the suggestion must still be active, governed, action-enabled, and
have the exact named `email.update` or `tasks.create` scope. Changing the default Email agent or
using a wildcard scope does not grant an old suggestion new authority. Normal user, mailbox, and
target-domain permissions are always repeated. Applying twice returns the same target reference.

Supervised cleanup is limited to provider Archive or same-account Move to an existing selectable
folder. It preserves provider Seen and every user's Nexum `Unread for me`, records one normal remote
operation, and uses the same recovery and verified Undo controls as manual provider actions. Apply
must still match the exact source placement, folder, UID, UIDVALIDITY, and sync version reviewed at
analysis time; it never follows a message that was moved in the meantime. Batch review snapshots an
exact unique selection of at most 50 cleanup suggestion IDs, reserves each source once, and shows the
real provider result for every item; new mail or suggestions cannot enter that snapshot after
confirmation, and failed/pending work is not shown as successful.

**Always do this** only prefills the existing personal rule modal or Admin rule builder. Opening it
creates no rule. Admin links use a short-lived, one-use opaque prefill token so sender, subject, rule
name, and condition data are not placed in browser history or access logs. Admin prefills are
inactive and require normal explicit save/publication. Provider cleanup rules use separate
Archive/Move-at-provider actions; the existing legacy Archive action keeps its local-only
compatibility meaning.

Permanent provider delete and automatic external replies remain separate. Automatic external
replies are deliberately not implemented because the approved RFC requires another explicit
high-risk approval and ADR; this is not a missing part of the currently approved Smart Inbox slices.

## Outbound Defaults And Templates

Email accounts can be marked as default senders for scoped workflows. Current scopes are `tickets`,
`sales`, `marketing`, and `alerts`. The `marketing` scope is used by the Marketing domain as the
default campaign sender, while each future campaign can still override the sender account.

Email templates are owned by the Email domain and managed from the Templates hub. Templates support
the `marketing` scope so campaign emails can reuse the same renderer while Marketing stores its own
approved content and layout snapshot.

`Body HTML` is edited with a visual toolbar and an explicit HTML source mode. Plaintext remains a
separate field. The complete outer document is shown separately as `Layout HTML`: a normal
`Branding managed` template follows current Company Profile branding, while `Customize layout`
copies that current document into a custom advanced source field. Editing subject, body, plaintext,
or variables does not switch the layout to custom. A custom layout stays frozen across later
branding changes until an admin explicitly chooses `Reset to branding`.

Custom Layout HTML must contain one `{{ email_body }}` slot. Body HTML is a fragment and cannot
contain a complete `html`, `head`, or `body` document. Server validation rejects scripts, embedded
frames/objects, forms and form controls, inline event handlers, unsafe URL schemes, meta refresh,
and unsafe CSS. The rendered preview uses unsaved form values through the outbound renderer and is
read-only in an empty sandbox.

Ticket workflows use the same Email template system. The default
`tickets/ticket_status_update` template is available for customer-facing workflow status changes
and receives Ticket key, subject, contact, previous status, current status, the configured customer
message, and technician name. Workflow administrators choose an active Ticket template on each
transition. Delivery is queued only after a successful transition, and only when the Ticket is
Published. Missing contact/account/template data and SMTP failures are recorded in Email logs
without rolling back the Ticket transition.

The renderer injects company branding variables such as `company_name`, `company_logo_url`,
`brand_primary`, `brand_secondary`, `brand_accent`, `support_email`, and `website`, plus explicit
header, footer, page, content, and action-color variables. Branding-managed layouts use the Company
Profile light-theme logo/colors on every render. Custom layouts use their stored HTML. Plaintext
output remains separate and readable.

The default template seeder creates a `marketing/marketing_campaign_default` template with branded
HTML, plaintext fallback, clear recipient/company placeholders, and an `unsubscribe_url`
placeholder. Campaign-specific marketing copy is edited directly in each campaign email snapshot;
the default template does not use ambiguous campaign heading, intro, body, or call-to-action
variables. New and existing Marketing campaign emails also retain a materialized layout snapshot,
so later reusable-template or Company Profile changes do not silently restyle an email that already
belongs to a campaign.

## Inbox Rules

Inbox only shows INBOX messages that are not linked to a Ticket and belong to a mailbox the current
user may view. Other provider folders are intentionally hidden from `/tech/inbox` until the full Mail
workspace exists.

Messages linked to tickets have `ticket_id` set and are no longer treated as unrouted inbox work.

Before inbound ticket rules run, Email classifies common machine responses and vendor notifications
into the Signal domain. Hard bounces, soft bounces, automatic replies, out-of-office replies,
unsubscribe requests, and recognized vendor update notices such as QNAP firmware/security messages
are recorded as Signal records, archived locally, and skipped by normal ticket routing. Signal rules
can then suppress marketing email, tag contacts or clients, create follow-up tickets, emit derived
signals, or call webhooks.

Email Rules can also use an explicit `emit_signal` action for selected inbound messages. This is
opt-in and should be used only when the email itself is a useful operational event, such as a vendor
notice, monitoring alert, or security notification. Email remains responsible for parsing, tagging,
archiving, linking replies, and deciding whether a normal message becomes a ticket. Signal owns the
cross-module follow-up after the explicit handoff has created a normalized Signal record.

Email rules have an explicit routing phase. `normal` is the default and preserves the existing
order: deterministic/machine/AI classification runs first, followed by Email rules and ordinary
Ticket routing. `preclassification` is opt-in for narrow, deterministic handoffs that must run before
the generic classifier. A matching preclassification rule can stop later classification and Ticket
routing; nonmatching messages continue through the unchanged normal flow.

Email rules also have explicit mailbox scope. Admin selects one or more shared/system mailboxes with
Ticket ingress enabled; personal accounts cannot be selected. Existing legacy rules are migrated to
account-scope rows for existing shared/system accounts. A rule scoped to one mailbox does not run for
another mailbox.

Admin-saved Email rules publish immutable version snapshots. Runtime processing uses the published
version's conditions, actions, routing phase, stop behavior, and account scope instead of mutable form
state. Each matched message/rule-version pair records an idempotent execution attempt with action
results, so repeated processing does not replay successful side effects for the same published
version.

Once an execution attempt is finished, its rule/version, message/placement, snapshots, action
outcomes, status, and timestamps are immutable. A failed action is recorded as `failed`; every later
position in that rule is recorded as `not_run`, and operational surfaces receive stable reason codes
instead of raw exception or provider messages.

Verified rule Undo is intentionally narrower than ordinary rule behavior. It is offered only when
one completed successful attempt contains exactly one successful provider Archive or Move and its
action position, type, target folder, account, placement, acknowledged status, and remote-operation
ledger reference all agree. Nexum then uses the normal 15-minute verified provider Undo path, which
rechecks current Mailbox Organize access, later operations, local/provider identity, and provider
state before writing. Repeating Undo returns the same uniquely linked inverse. Local Archive/tags,
mixed successful effects, stale or ambiguous evidence, and mismatched targets are never locally
compensated or presented as undone.

Admin Email rules support grouped conditions. Each condition row belongs to a named group, each
group can require all or any rows, and the rule can require all groups or any group. The rule preview
API and runtime use the same grouped semantics. The Admin Email rules page also includes a guarded
reprocess form where users with `email.rule_manage` can submit a stored Email message ID to run the
published rule engine immediately or queue it.

Personal simple rules use the same published versions and execution-attempt ledger, but they are
stored with `rule_kind=personal_simple` and an owner. They run only for that owner's personal Inbox
placements and are limited to safe organization actions. They cannot create Tickets, emit Signals,
send mail, call webhooks, permanently delete provider mail, or affect shared/system mailboxes.

After inbound classification and routing completes, Email calls the Notification-owned inbound
alert dispatcher. Notification decides whether the linked Ticket owner or explicit inbox/triage
subscribers receive Customer reply on my Tickets or New inbound Email alerts. That dispatcher is
idempotent by EmailMessage and user, so repeated rule processing does not create duplicate
notifications.

For unrouted Inbox notifications, Notification also checks Email mailbox access. A subscriber without
View access to the message's mailbox receives no Inbox notification, even if the notification type is
enabled.

Email remains responsible for message storage, state, spam/archive behavior, rule execution, and
Ticket routing. Notification owns channel preferences, Web Push payloads, device delivery,
canonical notification read state, and source read synchronization.

## Advanced Automation Trust

A visible `From` address is untrusted input and is never sufficient evidence for automatic supplier
processing. Proxmox Mail Gateway, DNS, SPF, DKIM, and DMARC remain the normal mail-security
boundary. Nexum's trust settings are only for sensitive automation that needs to decide whether
gateway-produced authentication evidence can be used.

Admins must configure both exact trusted `Authentication-Results` authserv IDs and exact trusted
receiving hops in Email Sync & Cache Settings. The first `Received` header must name one of those
hosts after `by`; an authserv ID alone is never trusted. Configure only receiving infrastructure
that removes untrusted inbound `Authentication-Results` headers before adding its own result.

Both lists may be left empty to disable trusted sender authentication. A missing or empty list on
either side fails closed: ordinary messages continue through the existing Email and Ticket flow,
while supplier-order automation receives an empty, unauthenticated trust snapshot.

Email parses only bounded `Authentication-Results` values after that paired trust boundary. It
emits a compact `trusted_auth` snapshot with these canonical keys:

- `authentication_passed`, `aligned`, and `authserv_id`.
- `authenticated_supplier_identity` and `authenticated_supplier_domain`.
- Canonical `spf`, `dkim`, and `dmarc` results.

Raw headers are not copied into Signal. A trusted receiver may still report authentication that is
failed or not aligned; those facts remain useful for review and shadow processing. Active automatic
supplier-order gates must require both `authentication_passed=true` and `aligned=true` and repeat
their hard checks in Storage.

Technicians can mark an inbox message as spam. This:

- Tags the message with `spam`.
- Archives the message locally.
- Creates or updates an inbound email rule so future matching messages are tagged and archived.

## Inbound HTML Safety

Inbound email HTML is untrusted content.

When a message is stored, Nexum sanitizes the HTML body before it is saved in
`body_html_sanitized`. The sanitizer keeps common readable email markup such as paragraphs, links,
tables, emphasis, and images, but removes active content such as scripts, iframes, event handlers,
forms, embedded objects, and unsafe URL schemes.

Inbox views and API responses must use the sanitized body, never raw email HTML.

## Attachment Safety

Inbound attachment persistence is controlled from Email Sync & Cache Settings. Admins set the maximum
attachment count per message, maximum size per attachment, and an allowlist of MIME types. Filenames
are sanitized before paths are built, and each accepted attachment gets an `EmailAttachment` row
with actual size, detected MIME type, SHA-1 checksum, disk/path, inline state, and content ID.

Unsupported, oversized, excess, unreadable, or unwritable attachments are skipped and logged without
failing the stored email. Deterministic position/checksum paths make attachment persistence safe to
retry without creating duplicate rows.

Stored attachment rows are listed beneath the exact selected message in `/tech/mail`, with a friendly
filename and size. Downloads are bound to that active placement and current Mailbox View access,
forced as attachments, marked `nosniff`, and private/no-store. A missing global Email permission
returns 403. Once inside the Mail boundary, a revoked grant, inactive account, hidden or cross-account
placement, message mismatch, missing file, or unsafe path returns a hidden 404. Inline parts may be
downloaded but are not previewed inline by this slice.

Historical recovery is an operator action, never a browser side effect. `email:recover-attachments`
requires explicit message IDs, previews without writes, caps each run, prefers a reparsable local raw
snapshot, and reuses the normal idempotent persister. Before provider fallback, an exact historical
persister directory at `email/attachments/{account_id}/{imap_uid}` is accepted only when its direct
file count matches the preserved positive counter and every file passes containment, non-symlink,
size, and detected-MIME policy checks. Any mismatch rejects the whole local source, while complete
legacy evidence performs no provider search. Optional provider fallback reads only the exact
folder/UID with `ST_UID`, `leaveUnread`, and limit 1, checks UIDVALIDITY and normalized Message-ID, and
has no folder/newest-message fallback. Recovery never changes provider state or replays inbound rules,
Tickets, notifications, or outbound mail.

## Private Email Storage Operations

Raw MIME, inbound attachments, durable draft attachments, and outbound Sent snapshots remain on the
established private local disk under `email/*`. New writes use one Email-owned storage boundary that
verifies the final file, assigns owner-created directories setgid/group-write access and files group
read/write access, and records database paths only after a successful write. Failure logs contain a
sanitized storage scope, stable reason, and exception class rather than message content, addresses,
filenames, paths, or credentials.

This policy is required because PHP-FPM and Laravel queue workers can run as different operating-
system users. It protects newly created paths even when a process has a restrictive umask, but the
application does not pretend it can chmod an existing path owned by the companion runtime. Dev's
legacy `email/raw/2` and `email/attachments` roots have now been normalized to
`www-data:www-data`; all 61 directories are `2770`, have group-rwx access/default ACLs, and contain no
symlinks. Readiness reports `safe=true` and `received_at_schema_safe`. File-mode normalization still
requires a root/operator: the latest 2026-08-24 12:47 CEST read-only inventory sees 1,445 files, of
which 79 remain
`www-data`-owned `0644` that the SSH project user cannot chmod. Change only those 79 modes to `0660`
without content, ownership, move, or deletion, then repeat the exact inventory and PHP-FPM/queue
dual-runtime smoke under `HR-2026-08-15-003`.

Use `email:inventory-private-storage` for the bounded reconciliation. It scans only the canonical
private `email/*` root, compares regular files with raw-message, message-attachment, durable-draft
attachment, and outstanding Sent-snapshot references, and redacts paths by default. `--show-paths`
is an explicit operator diagnostic and still prints no content. Missing references, unsafe/symlink or
unreadable files, incomplete scans, and non-private modes fail the command; unreferenced files and
checksum+size duplicate groups remain evidence only and never authorize deletion.

The verified redacted current run changed no file, permission, database, provider, queue, or
retention state. It inspected 1,445 files: 968 referenced and 477 unreferenced. It reports 28 missing
`message_raw` references, 79 non-private files, 15 duplicate unreferenced checksum+size groups, and
zero unsafe or unreadable files. No result authorizes deletion.
Focused command coverage passes 3 tests / 21 assertions.

Current direct readback finds 32 of 34 expected attachment parts on exact source rows. Provider
reconciliation confirmed message 479 absent from its source placement, hid placement 478 and
soft-deleted that local cache; it still has neither raw nor attachments. Same-identity message 650
remains active in Trash with independent raw plus two attachments and must not be substituted
automatically. Messages 456 and 478 each retain one
attachment row/file but lack raw snapshots. Do not guess, copy, or delete evidence.

Original legacy sources, duplicate account-2 copies, and all 477 unreferenced files remain preserved
and are not proven safe to purge. Focused coverage passes 15 / 110; earlier adjacent provider-read
coverage passed 47 / 321, broad Email module/inbound coverage 155 / 1,308, and the complete Email
directory 347 / 3,030. Browser/access review, the separate 479/650 canonical evidence, and raw
snapshot evidence review for messages 456 and 478 remain Rework Needed under `HR-2026-08-15-006`.

## Controlled Dev Incident Recovery

The 2026-08-15 recovery of the reported Trash/draft/send incident used fresh exact provider evidence
and did not replay delivery or the original move. Operation `23` was cancelled, its stale source
placement `474` was hidden, and verified provider Trash UID `30177` was projected as placement `485`
in canonical Trash folder `141`. The wrongly classified child folder was repaired to `custom`.
Draft `1` was marked sent and its exact provider Drafts UID was deleted only after both normalized
Message-ID and outbound send-log identity matched.

The provider post-check confirmed the source absent, the copy present in canonical Trash, the wrong
child empty, and the draft UID absent. Recovery issued zero SMTP writes and zero IMAP MOVE writes.
No provider Sent copy was invented or appended: its exact outbound Message-ID was not found and the
raw Sent snapshot was unavailable, so a blind APPEND could have duplicated mail. This leaves the
provider Sent projection truthful while the already-delivered outbound log remains the delivery
record.

Automated Dev verification passes 74 tests / 613 assertions for the integrated runtime-focused
package, 141 / 1,227 for `EmailModuleTest.php`, and 14 / 81 for
`InboundAutomationTest.php`. Human review `HR-2026-08-15-003` remains Pending for the broader
runtime, send, right-bar, and cross-user write checks. The two legacy directory trees are normalized,
but a root/operator mode-only repair of the 79 non-private files plus dual web/FPM and queue-runtime
smoke remains open. Current attachment evidence does not waive that storage or human review gate.

## API

Email exposes Inbox routes under `/api/v1/email/inbox`, conversation classification and provider
operations under `/api/v1/email/mailbox`, the user-scoped Smart Inbox review API under
`/api/v1/email/smart-inbox`, and rule read/preview/execution/verified-Undo routes under
`/api/v1/email/rules`.

Implemented scopes:

- `email.read`: list and view authorized unrouted inbox messages.
- `email.update`: mark authorized inbox messages as spam and queue polling for mailboxes the actor
  can organize; replace/clear conversation classification and dismiss/correct/apply authorized Smart
  Inbox suggestions.
- `email.rules.read`: list, view, and preview admin-managed Email rules, and view account-scoped
  execution/Undo eligibility. The authenticated user must also have Email rule-management
  permission; previews and execution attempts require current mailbox View access.
- `email.rules.execute`: apply an eligible exact provider inverse for an Admin rule execution. This
  additionally requires current Mailbox Organize access and never authorizes local-only compensation.

Implemented routes:

- `GET /api/v1/email/inbox/messages`
- `GET /api/v1/email/inbox/messages/{message}`
- `POST /api/v1/email/inbox/messages/{message}/spam`
- `POST /api/v1/email/inbox/poll`
- `POST /api/v1/email/mailbox/placements/{placement}/operations`
- `GET|PUT|DELETE /api/v1/email/mailbox/conversations/{conversation}/classification`
- `GET /api/v1/email/smart-inbox/suggestions`
- `GET /api/v1/email/smart-inbox/suggestions/count`
- `GET /api/v1/email/smart-inbox/suggestions/{suggestion}`
- `POST /api/v1/email/mailbox/conversations/{conversation}/smart-inbox/analyze`
- `POST /api/v1/email/smart-inbox/suggestions/{suggestion}/dismiss`
- `PATCH /api/v1/email/smart-inbox/suggestions/{suggestion}`
- `POST /api/v1/email/smart-inbox/suggestions/{suggestion}/apply`
- `GET /api/v1/email/rules`
- `GET /api/v1/email/rules/{rule}`
- `POST /api/v1/email/rules/{rule}/preview`
- `GET /api/v1/email/rules/executions/{attempt}`
- `GET /api/v1/email/rules/executions/{attempt}/undo`
- `POST /api/v1/email/rules/executions/{attempt}/undo`

`GET /api/v1/email/inbox/messages` supports:

- `q`: search stored raw and readable subject text, from name, from email, and plain-text body.
  Literal `%`, `_`, and `!` do not act as SQL wildcard syntax. Results still serialize the stored
  raw `subject`; the derived search projection is not returned.
- `state`: filter by message state.
- `account_id`: filter by email account.
- `from_email`: exact sender filter.
- `per_page`: page size.

The API does not expose raw storage paths or email account secrets.

Rule execution responses expose only action identity/status, stable reason codes, and opaque remote
operation IDs. They omit message/condition content, folder paths, before/target snapshots, raw
exceptions, and raw provider messages. Attempts outside the token user's current mailbox scope return
Not Found. A specific token needs both `email.rules.read` for inspection and `email.rules.execute`
for application; token abilities remain ceilings and do not replace current user or mailbox grants.

API token abilities are request ceilings, not mailbox grants. `email.read` cannot read every mailbox;
the authenticated token user must also have effective mailbox View access. `email.update` cannot
mutate every mailbox; spam/archive and polling require the token user to have effective Organize
access for the target mailbox. Smart Inbox Task application additionally requires the request
token's `tasks.create` ceiling and Task's normal user/work-context authority. Smart Inbox suggestions
remain owned by their requesting user even inside a shared mailbox, and inaccessible IDs return Not
Found.

`POST /api/v1/email/inbox/poll` queues `FetchImapAccount` jobs for active accounts the actor may
organize. It does not run IMAP polling inside the HTTP request.

Automatic fetching is scheduled through Laravel's scheduler. The scheduled `email.poll` job queues
account fetch jobs and records the `email_last_poll_run` heartbeat when it starts a real poll cycle.
The interval gate treats the heartbeat as absolute elapsed time, so an older heartbeat must never
block the next due poll cycle.
The Email Sync & Cache Settings page shows active account count, sync pause state, latest successful
fetch, account errors, database queue backlog, failed jobs, and scheduler heartbeat so operators can
distinguish account, scheduler, and queue-worker problems.
It also shows a read-only **Retention preview** for the configured local-cache period. The preview
counts expired messages, definitively unplaced eligible orphans, protected messages, and every
applicable protection reason. It does not offer a manual purge action.

The scheduled retention cleanup does not delete normal provider-backed mail merely because its
local copy is old. Any mailbox placement protects the message until provider-deletion reconciliation
has safely removed that placement. Pending/running/failed/ambiguous provider work, unresolved Sent
or conversation review, Ticket links or captured Ticket evidence, recognized legal holds, and
unsupported attachment storage also protect the source. Ticket-owned snapshots and attachments are
never removed by Email cache retention.

For a genuinely expired, unplaced, unprotected orphan, Nexum removes local attachment/raw EML files
before removing the local Email row. Every scheduled run and message attempt keeps a sanitized audit
containing IDs, counts, reason codes, failure codes, and timestamps only. A filesystem failure leaves
the database evidence in place for a later retry and is not reported as success. Legal-hold
authoring/release, DSAR/export/erasure, offboarding, backup expiry, and cross-account lifecycle
policy remain separate workflows and must not be inferred from this preview.

### Provider Deletion Reconciliation

Email Sync & Cache Settings has a separate provider-deletion reconciliation switch. It defaults off,
and the scheduled jobs run only when its stored value is exactly enabled. Keep it off until the
controlled Dev checks in `HR-2026-08-14-015` have been completed. This switch never instructs Nexum
to delete a provider message; it controls detection and eventual cleanup after a message was moved or
deleted outside Nexum.

When enabled, the daily 04:00 dispatcher compares a bounded provider folder inventory with active
placements. A folder is accepted only when its start/end UIDVALIDITY, UIDNEXT, and message count are
stable and the full bounded UID set was obtained. Provider errors, UID resets, scan limits, count
drift, or concurrent projection changes fail closed without hiding a placement.

Confirmed loss creates an immutable finding and hides only the exact placement as a seven-day
tombstone. Exact provider reappearance restores it and cancels its old cleanup path. A provider move
is recognized only when conservative evidence proves an already projected target; ambiguity stays
protected for review.

The daily 05:00 cleanup runs only after grace. It locks and repeats surviving-placement,
remote-operation, retention, and Ticket-evidence checks. Eligible Mail-owned payload, local files,
tags, and source-derived Smart Inbox artifacts are removed idempotently. A partial file failure stays
failed/retryable instead of reporting false success. Ticket-owned snapshots/evidence and a surviving
placement are never removed by this workflow. Inventories and findings contain bounded operational
IDs/fingerprints, not subject, participants, body, headers, attachment names, raw provider payload,
or credentials.

The same page also selects the ordinary Default Email agent. Leaving the field blank uses the global
default fallback agent, such as Datanora. Selecting an agent such as Mail Agent makes that agent the
Email domain default, while the fallback line still shows the global fallback agent. The old Mail AI
structured workload override is not shown or used, and its legacy setting is cleared the next time
Email settings are saved. An action-capable default agent can still draft and summarize here because
these Mail buttons do not call tools, update Tickets, send mail, create Tasks, move messages, or
apply rules/tags. If Integration installation, provider, model, or agent governance denies the
selected/default agent, Mail AI controls stay hidden and direct action attempts show the stable
denial reason such as `model_governance_missing`.
AI Settings includes a compact **Activate AI** path that lets an administrator select the provider
and model, confirm the organization's approval, and create the required policy/provider/model
records without using the advanced governance forms.
Email settings are stored in `common_settings`; its `value` field is long text so the attachment MIME
allowlist and trusted-authentication lists can be saved together with Mail AI settings.

Inbound storage is idempotent by `account_id`, mailbox, and IMAP UID. Placement state is idempotent by
account, provider folder, UIDVALIDITY, and UID. Polling checks soft-deleted messages too, because the
database unique key still reserves those UIDs. `StoreInboundMessage` also recovers duplicate-key races
between workers: active duplicates skip storage and can safely re-run Inbox rules, while soft-deleted
duplicates are ignored so locally hidden messages are not re-imported.

Automatic polling discovers provider folders, baselines each enabled selectable folder, and fetches
the oldest bounded batch after the greater of that folder baseline and the highest stored UID,
regardless of Seen state. This drains bursts larger than one batch without turning historical unread
mail into Nexum tickets. Already-stored and soft-deleted UIDs are removed before selection, fetch jobs
are serialized per account, and the database unique key remains the final race boundary. If INBOX
UIDVALIDITY changes, automatic ingest records an account error. If another folder's UIDVALIDITY
changes, that folder is marked with a sync issue. In both cases Nexum fails closed instead of
guessing that reused UIDs are new mail.

### Historical mail and UID recovery

Historical mail is not fetched by widening ordinary polling or by treating provider unread as a
cursor. An administrator who holds both Email account-management and the separate mailbox-sync
maintenance permission can open **Mailbox maintenance** for one account and preview exact enabled
folders, a UTC date window of at most 31 days and a bounded total (100 by default, 500 maximum).
The preview shows only operational counts, folder/UID namespace and blockers—never subject,
participants, filenames, body, raw headers or credentials—and expires after 15 minutes.

After explicit confirmation, the Email worker fetches exact messages with PEEK in batches no larger
than 50. It rechecks the account, permission, configured cap, folder path, UIDVALIDITY/UIDNEXT,
private-storage readiness and frozen snapshot before every batch. Imported history appears only in
its real authorized folder. It does not run Inbox rules, create Tickets or Signals, notify users,
invoke Smart Inbox/AI, change provider flags or move the live cursor. Existing history is behind each
ordinary viewer's personal unread baseline; an explicit backlog-handover workflow is required to
make selected old messages Unread for that user.

When a folder reports a UIDVALIDITY failure, **UID re-baseline** has its own reason-bearing preview.
A genuine validity change creates a new immutable namespace and preserves the old placements; a
documented same-validity cursor recovery keeps the existing namespace. Neither path imports history
or writes to the provider. Unresolved Draft/Sent/remote work, concurrent maintenance, changed
provider state or a changed local placement count blocks the action. Human review
`HR-2026-08-16-001` remains required before controlled provider use.

### Canonical Message Shadow Report

Email Admin includes **Canonical correlation** as a conservative local review surface for the later
canonical-message migration. It is not a deduplication command. A run never merges, relinks, hides,
deletes, sends, moves, marks read, changes Tickets, invokes AI, or contacts the provider. Current
messages, placements, conversations, attachments, raw snapshots, Ticket links, personal state, and
all existing Mail/API read paths remain authoritative.

The surface is available only to an active human with mailbox-sync maintenance permission and
ordinary current View access to every exact account selected. Account-configuration authority alone
does not reveal mailbox content or even inaccessible personal-mail candidates. The normal report
shows only scoped account/message IDs, statuses, counters, candidate classes, reason codes, hashes,
and review audit. It does not show or store subjects, participants, filenames, snippets, bodies,
headers, raw source, attachment content, credentials, or search terms.

An operator chooses exact accounts and, when needed, a minimum/maximum message-ID window. Processing
is queued, resumable, cancellable, and bound to the frozen local scope. The initial and final scope
snapshots each stop above 64 MiB of conservatively estimated evidence input; the complete run stops
above 256 MiB. Exact group/pair limits are accepted at the boundary and fail closed beyond it.
Normalized Message-ID, exact checksum, or a current explicit Ticket/conversation relationship may
discover a pair; a similar subject alone never does.

Candidates are reported as strong, possible, ambiguous, different, or oversized. Missing evidence
never becomes agreement. Recipient/Bcc, direction, date, body, raw-source, or attachment differences
keep delivery variants separate. When exact and oversized discovery overlap, oversized remains the
deterministic result regardless of discovery order. An oversized representative cannot be confirmed
and must be rerun with a narrower scope.

Opening **Inspect exact evidence** is a separate content action. It rechecks ordinary View for both
recorded accounts, verifies that each exact message still belongs to that account, recomputes the
current evidence hashes, and writes a content-free inspection audit before showing either message.
It does not create an opened receipt or change `Unread for me`. Access revocation, evidence drift, or
a moved-account message makes the candidate unavailable. The same inspecting operator may then make
one immutable `Confirmed candidate` or `Keep separate` decision; `Needs more evidence` remains a
metadata-only outcome. None of these choices performs a merge.

The additive migration `2026_08_16_110000_create_email_canonical_correlation_shadow.php` ran on
recovered Dev in batch 104. Focused verification passes 19
tests / 131 assertions and the final independent audit is GO. Authenticated operator/worker behavior,
responsive review, exact no-mutation checks, and the guarded rollback exercise remain to be
reconciled with the human-review summary for `HR-2026-08-16-004`.

### Canonical Message And Placement Cutover

Email Admin **Canonical cutover** is the separately guarded next step after the shadow report. It
does not replace an email's mailbox identity: the exact source message and provider placement still
control who may view it, its personal Unread state, Ticket/rule behavior, provider actions, links,
and API identity. The canonical layer can supply only common content proven equivalent to that
already authorized source.

An active human needs both canonical-cutover and mailbox-sync maintenance permissions and ordinary
current View for every selected account. Break-glass is not enough. The page lists only currently
viewable active accounts and accessible durable runs. If a requester leaves, another operator with
the complete current authority may inspect, apply, or roll back the run; the original requester is
retained only as audit history. Reports show operation IDs, source occurrence IDs, counts, statuses,
and reason codes—not canonical IDs, participants, bodies, headers, filenames, private paths, or raw
content.

Every action begins as a bounded preview and requires a separate typed confirmation before apply.
Available previews create missing one-source projections, merge one complete exact reviewed clique,
audit pointer/content drift, or change exact accounts among `legacy`, `verify`, and `canonical` read
modes. `legacy` is always the default. `verify` keeps displaying the source. `canonical` displays
equivalent projected common content only while all source, projection, mapping, placement, and file
evidence still agrees; any drift immediately falls back to the authorized source.

Mailboxes above 500 active placements use a resumable whole-account parity attestation rather than
one large request. Each click verifies at most 100 placements and records durable progress; a second
currently authorized operator can continue if the requester is offboarded. When every active
placement has strict actual-file parity, the page records a frozen fingerprint valid for 15 minutes.
The later mode preview binds that fingerprint and apply rechecks it. A changed placement, mapping, or
projection—or an expired fingerprint—blocks the mode change without changing Mail state. The
ordinary reader still performs a live per-message parity check and falls back independently.

Evidence is deliberately strict and reads the real private raw and attachment files under hard
bounds. Missing files, path/symlink issues, mismatched declared size or SHA-1, different actual
SHA-256 bytes, incomplete fields, oversized/deep/wide headers, weak or ambiguous shadow evidence,
an incomplete component, retained **Keep separate**, stale access, or changed evidence all block the
operation. An audit repairs pointer-only drift but dissolves a drifted shared component into complete
independent projections. Attachment download remains tied to the exact clicked source part even when
duplicate filenames or metadata exist.

Canonical projections and durable cutover audit protect their source mail from ordinary retention
purge until a future reviewed deletion policy exists. Cutover never sends, moves, deletes, marks
provider Seen, changes Unread for anyone, runs rules, creates/changes Tickets, invokes AI, or writes
to the provider. Rollback must run newest-first and fails closed after overlapping changes. Additive
migration, permission seeding, controlled browser/API/private-file parity, preview/apply/rollback,
cache/worker, and no-provider-mutation checks remain Pending under `HR-2026-08-16-005`; no live
migration or cutover was run during implementation. Migration rollback is also guarded: any
projection, mapping, placement pointer, mode row, preview/run item, or parity-attestation evidence
must be preserved or carried forward before the additive schema can be removed.

Sent-folder imports never run Inbox ticket/rule automation. They may reconcile pending outbound logs
when the account and normalized `Message-ID` match exactly. If several pending outbound rows match
the same provider Sent copy, Mail marks them ambiguous for later review instead of choosing one
silently.

### Live update rollout status

Private Reverb/Echo Mail updates remain disabled. The default-off publisher now freezes source
fanout evidence with the Mail mutation, pages raw recipients in bounded durable claims, blocks
finite failures without false source sealing, signs browser-applied version receipts, bounds catch-up
and retention, and falls back to visible 15-second polling after a five-second socket failure. Exact
origins and the one exact WebSocket CSP origin are required. Disabled mode performs no projection-
invalidation writes, rejects module channel auth, creates no Echo connection, and keeps ordinary Mail
actions available. Activation additionally requires `EMAIL_LIVE_RUNTIME_APPROVED=true`; this second
gate must remain false with `EMAIL_LIVE_ENABLED` and `VITE_EMAIL_LIVE_ENABLED` until the authority
writer/recompute and dedicated bounded current-page paths are complete and the supervised Reverb,
Apache/Plesk, worker/scheduler and browser checks in `HR-2026-08-16-008` pass. Forward migration
`2026_08_24_120000` ran in Dev batch 125 with every runtime gate false; migration deployment does
not authorize activation.
The historical shared-draft-lock and conversation-acknowledgement
migration filenames from 2026-08-19 are registered as inert deploy markers in Dev batches 119 and 120; they
created no tables. `EMAIL_MAIL_COLLABORATION_ENABLED`, `EMAIL_MAIL_COLLABORATION_UI_ENABLED`, and
`EMAIL_MAIL_ACKNOWLEDGEMENT_ENABLED` also default false. Corrected Order 12 and Order 9 schemas ran in
Dev batches 128 and 126 with empty ledgers, but their user-facing activation, private transport, UI,
and operational review remain gated.

Reply, Reply All and Forward now resolve the selected placement again at send time. This closes the
undefined-placement error, but does not activate shared drafts or presence. Private drafts remain
the only visible workspace boundary until the corrected shared scope/fencing migration, Order 8
transport and human review are completed and explicitly enabled.

Nexum captures header evidence from the original Webklex raw header block. Folded values are
unfolded, but repeated `Received` and `Authentication-Results` fields keep their top-to-bottom order
so the configured first receiving hop can be verified. Missing, malformed, or untrusted header
evidence never establishes sender trust and must lead to review rather than an automatic write.

Mail Reply, Reply All, Forward, provider-Draft editing, and new Compose sending use one private
draft/submission boundary from both the Livewire workspace and the versioned draft, attachment,
preview, send, submission-status, and Sent-reconciliation API. The same ledger also backs the
default-off Order 9 shared API, but shared/team drafts remain unavailable in the workspace until its
separate migration, runtime/UI activation and review are complete.
