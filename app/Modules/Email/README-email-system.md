# Email system — architecture, files, and extension guide

This document explains how the email subsystem works end‑to‑end: the goals and flows, the data model, which files do what, and how to extend or reuse pieces from other areas of the app.


## Scope and goals

- Support receiving email via IMAP and sending via SMTP.
- Keep mailbox ownership, user grants, and Ticket ingress explicit.
- Store-first ingest: fetch, persist raw + metadata, then process (rules) asynchronously.
- Deduplicate by account + mailbox + IMAP UID while also projecting provider folders and placements.
- Sanitize HTML, extract text, store attachments to disk.
- Simple health testing from the UI and scheduled background checks.
- Configurable local cache retention and explicit provider cleanup behavior.
- Reusable services and jobs that other controllers can call.

Current non-goals for this foundation slice:
- No provider-specific OAuth2 migration yet.
- No Ticket capture or portal-publication behavior for Mail-originated replies yet.
- No shared content editor platform yet.
- No automatic external replies. The approved RFC deliberately requires a separate explicit
  high-risk approval and ADR before that capability can be implemented; this is not an unfinished
  part of the currently approved Mail slices. Mail AI can summarize selected mail, persist typed
  Smart Inbox suggestions, and expose only the documented human-reviewed actions after normal user,
  mailbox, target-domain, agent-action, and exact agent-scope checks pass.


## Key components at a glance

- Models: `EmailAccount`, `EmailAccountUserGrant`, `EmailFolder`, `EmailMailboxPlacement`,
  `EmailConversation`, `EmailConversationClassification`, `EmailSmartInboxSuggestion`,
  `EmailSmartInboxSuggestionEvent`, `EmailMessageClassification`, `EmailRemoteOperation`,
  `EmailRemoteOperationAttempt`,
  `EmailMessage`, `EmailMessageReceivedAtRepair`, `EmailComposerDraft`, `EmailComposerDraftAttachment`,
  `EmailTicketConversationLink`, `EmailSignature`, `EmailAttachment`, `EmailHealthCheck`, `EmailLog`
- Actions: `SendEmailComposerMessage`, `SendEmailReply`, `MarkEmailAsSpam`,
  `UpdateEmailMessageClassification`, `UpdateEmailConversationClassification`,
  `AnalyzeEmailConversationForSmartInbox`,
  `DismissEmailSmartInboxSuggestion`, `CorrectEmailSmartInboxSuggestion`,
  `ApplyEmailSmartInboxSuggestion`, `ApplyEmailSmartInboxSuggestionBatch`,
  `RecoverEmailMessageAttachments`,
  `BuildEmailSmartInboxRulePrefill`, `CreatePersonalEmailRule`, `CreateProviderEmailFolder`,
  `ManageProviderEmailFolder`, `LinkEmailConversationToTicket`, `SummarizeEmailWithAi`,
  `AssistEmailComposerWithAi`, `PerformEmailRemoteOperation`, `RecordEmailRemoteOperation`,
  `RunEmailRemoteOperation`, `RetryEmailRemoteOperation`, `CancelEmailRemoteOperation`,
  `RunDueEmailRemoteOperations`
- Services: `ImapClient`, `EmailFolderProjector`, `EmailTestService`, `SmtpAccountMailer`,
  `EmailComposerDraftService`, `EmailProviderDraftSyncService`, `EmailSignatureRenderer`,
  `EmailPrivateStorage`,
  `EmailConversationFingerprint`, `EmailSmartInboxSuggestionEligibility`,
  `EmailSmartInboxSuggestionNormalizer`,
  `EmailSmartInboxSuggestionStateService`, `EmailProviderInventoryScanner`,
  `EmailProviderDeletionReconciler`, `EmailProviderDeletionCleanupService`,
  `EmailProviderDeletionSettings`, `EmailMessageReceivedAtRepairService`,
  `RecoverEmailSmartInboxSuggestionsAfterReceivedAtRepair`, `EmailRawMessageSnapshot`,
  `EmailAttachmentRecoveryReadiness`, `BodyNormalizer`, `HtmlSanitizer`,
  `InboundAttachmentPersister`, `InboundEmailSignalClassifier`, `InboundEmailRuleEngine`,
  `PersonalEmailRuleEngine`, `TrustedSenderAuthenticationFacts`
- Jobs: `PollActiveEmailAccounts`, `FetchImapAccount`, `StoreInboundMessage`,
  `ProcessInboundRules`, `EmailAccountHealthCheckJob`, `EmailRetentionPurgeJob`,
  `RefreshEmailProviderDraftFolder`,
  `DispatchEmailProviderDeletionReconciliation`, `ReconcileEmailProviderDeletionAccount`,
  `CleanupEmailProviderDeletionCache`
- Controllers (Admin/Settings): `AccountsController`, `ConfigController`, `RulesController`
- Routes: declared in `app/Modules/Email/routes.php` under the `tech.admin.settings.email.*` namespace
- Scheduling: declared in `routes/console.php` (polling, health, retention)
- Operator commands: `email:repair-received-at` and `email:recover-attachments`

Libraries:
- IMAP: `webklex/laravel-imap` (v6) — Facade in tests, ClientManager in ingest wrapper.
- SMTP: Symfony Mailer via Laravel (EsmtpTransport).


## Data model

### EmailAccount — `app/Modules/Email/Models/EmailAccount.php`
Backs the `email_accounts` table (`database/migrations/2025_11_11_000001_create_email_accounts_table.php`).
Email owns mailbox identity, defaults, access, health, provider-work state, and one opaque Integration
provider binding. Integration is the only writer of new provider endpoints and credentials.

Important columns:
- Identity and defaults: `address`, `description`, `from_name`, `account_kind`, `owner_id`,
  `is_active`, `is_global_default`, `defaults_for (json)`, `ticket_ingress_enabled`
- Provider binding: `provider_integration_id`, `provider_credential_source (legacy|integration)`,
  positive `provider_binding_version`, `provider_bound_at`, `provider_bound_by`, pause/drain state.
- Legacy compatibility only: nullable `imap_*` and `smtp_*` endpoint/username/ciphertext columns.
- Health: `last_test_at`, `last_test_result (OK|Warning|Error)`, `last_error_code`, `last_error_message`, `last_successful_fetch_at`, `last_successful_send_at`

Notes:
- A new account can bind only to one active, exactly verified Integration provider. The Email form
  accepts no host, username, password, or transport field and never renders those values.
- Existing accounts remain explicitly `legacy` until the reviewed migration workflow changes only
  their provider reference/source. Legacy ciphertext stays encrypted and intact for rollback; it is
  never a fallback for an `integration` account.
- Binding identity changes increment `provider_binding_version`. Durable jobs and ledgers freeze a
  positive version and fail before provider I/O when it no longer matches. Secret-only rotation on
  the same Integration connection does not change the mailbox binding.
- Endpoint or username changes require a new Integration connection, account rebind, and mailbox
  re-baseline. They are not account-form edits or in-place credential rotations.
- `defaults_for` is a JSON array for per-scope defaults (e.g., tickets, sales, marketing, alerts).
- `account_kind` is `shared`, `personal`, or `system`.
- Personal accounts require one owner and cannot run Ticket ingress.
- Workflow default sender lookup ignores personal accounts.

### EmailAccountUserGrant — `app/Modules/Email/Models/EmailAccountUserGrant.php`
Backs `email_account_user_grants`. Each row grants one user mailbox operations for one shared or
system account.

Primary grants:
- `can_view`: list/open content and download allowed attachments.
- `can_organize`: spam/archive/delete and manual polling. User-facing organize actions also require
  View.
- `can_send`: marks an account as an allowed sender identity for later outbound slices.

Global permissions are still ceilings. `email.inbox_view` plus View is required to see content, and
`email.inbox_manage` plus View and Organize is required for Inbox mutations.

### EmailMessage — `app/Modules/Email/Models/EmailMessage.php`
Backs `email_messages` (`database/migrations/2025_11_11_000002_create_email_messages_table.php`). Represents stored inbound messages.

Key columns:
- Dedup key: `account_id + mailbox + imap_uid` (unique index)
- Metadata: `message_id`, `subject`, `from_name`, `from_email`, `to_json`, `cc_json`, `headers_json`, `in_reply_to`, `references`
- State: `received_at`, `size_bytes`, `is_oversize`, `state (enum)`, `labels_json`, `attachments_count`, `ticket_id`
- Content and files: `body_html_sanitized`, `body_text`, `raw_path`, `checksum_sha1`

`received_at` is message evidence and must not change as a side effect of unrelated updates. Dev's
historical MariaDB definition had an implicit `ON UPDATE CURRENT_TIMESTAMP` clause; forward migration
`121200` removes it and snapshots the bounded repair scope in `email_message_received_at_repairs`.
The provider-free repair command restores only evidence-supported values and leaves unresolved rows
unchanged.

During the Mail full-client shadow period, this table remains the compatibility message/content
record. It is not yet physically deduplicated across accounts or folders.

### EmailMessageReceivedAtRepair — `app/Modules/Email/Models/EmailMessageReceivedAtRepair.php`
Backs `email_message_received_at_repairs`. Migration `121200` freezes the highest existing message ID
and records one row for every message in that 490-row Dev scope before any operator repair. Each row
retains the observed value, evidence source/fingerprint, candidate or repaired value, stable reason,
status, and exact count of recovered false-stale Smart Inbox suggestions. It contains no message body,
subject, address, attachment name, provider payload, or credential.

`email:repair-received-at` is preview-only unless `--apply` is supplied. It accepts only a sane parsed
header date or conflict-free conversation boundary as repair evidence, uses row locks and
compare-and-swap writes, never calls a provider, and leaves unproven candidates unresolved. Because
unresolved rows are a deliberate fail-closed outcome, the command exits non-zero while any remain.

### EmailFolder — `app/Modules/Email/Models/EmailFolder.php`
Backs `email_folders`. Each row is one provider folder for one Email account.

Important columns:
- Identity: `account_id`, `provider`, `path`, `name`, `delimiter`, `parent_path`, `remote_id`
- Classification: `special_use`, `role` (`inbox`, `sent`, `drafts`, `trash`, `archive`, `junk`,
  `custom`)
- Sync policy/state: `is_selectable`, `sync_enabled`, `uid_validity`, `uid_next`,
  `live_start_uid`, `highest_modseq`, `exists_count`, `unseen_count`, `sync_status`,
  `last_discovered_at`, `last_synced_at`, and sync error fields

`live_start_uid` is the forward-only baseline for that specific folder. First discovery records
`UIDNEXT - 1` and does not import historical mail.

Custom provider folders can also be managed from the Mail sidebar when Mail can identify one mailbox
the technician can organize, either from an explicit mailbox filter, the selected folder, or the only
organize-authorized mailbox available. The gear button in the Folders header opens a mailbox-scoped
manager with an expandable folder tree whose parent folders are collapsed by default. The manager can
create folders at root or under a selected parent, inspect subfolders, rename or move empty or
populated custom leaf folders, and delete empty custom leaf folders. Rows without available actions
show the blocker reason. Nexum issues the IMAP operation first and then projects the acknowledged
provider folder change through this table.

### EmailFolderNavigationPreference — `app/Modules/Email/Models/EmailFolderNavigationPreference.php`
Backs `email_folder_navigation_preferences`. Each row remembers whether one technician explicitly
expanded or collapsed one provider folder branch. The unique `(user_id, email_folder_id)` boundary
keeps concurrent browser/device changes independent per branch, and both foreign keys cascade on
delete. Preferences are loaded only through current mailbox View scope; they never grant access and
are not exposed through the generic User preferences API.

### EmailMailboxPlacement — `app/Modules/Email/Models/EmailMailboxPlacement.php`
Backs `email_mailbox_placements`. Each row is one provider occurrence of an `EmailMessage`.

Important columns:
- `email_message_id`, optional `email_conversation_id`, `account_id`, `email_folder_id`,
  `folder_path`
- IMAP identity: `imap_uid_validity`, `imap_uid`, optional remote/modseq fields
- Provider state: seen, answered, flagged, deleted, draft, flags JSON, labels JSON
- Local projection state: `local_state`, `sync_status`, `sync_version`, reconciliation timestamps,
  missing/error fields

IMAP placement uniqueness is scoped to account, folder, UIDVALIDITY, and UID. This prevents a UID
from one folder or old UID namespace from being silently reused as another provider occurrence.

### EmailConversation — `app/Modules/Email/Models/EmailConversation.php`
Backs `email_conversations`. Each row is one durable account-scoped mail conversation projection.

Important columns:
- Scope and key: `account_id`, `conversation_key`, and `status`.
- Representative pointers: first/latest message IDs and latest mailbox placement ID.
- Projected counters: unique message count, active placement count, provider-unread count, and
  attachment signal.
- Projected dates: first and last message timestamps.

Inbound storage assigns placements through conservative account-local RFC thread evidence. A nested
reply resolves uniquely matched `References` / `In-Reply-To` messages before a new conversation is
created, so root, direct reply, and later nested replies stay together. Reused or conflicting
`Message-ID` evidence does not force a merge, and the key is always scoped by `account_id`, so the
same identifiers in another mailbox remain a separate Nexum conversation boundary. The forward
reconciler moves only unambiguous split placements and writes unresolved correlation evidence to an
issue ledger without changing provider or Ticket state.

### EmailConversationClassification — `app/Modules/Email/Models/EmailConversationClassification.php`
Backs `email_conversation_classifications`. Each row stores Nexum work classification for one
durable account-scoped Email conversation.

Important behavior:

- One conversation has at most one active Email category and may have several existing Taxonomy
  tags through `taggables`.
- Every placement in the same account conversation reads the same classification; a correlated copy
  in another account is independent.
- Assignment events are append-only in `email_conversation_classification_events`.
- View plus Organize is required to change assignment. Creating an unknown tag also requires
  `taxonomy.manage_tags`.
- Provider folders/flags, Ticket category/tags, and legacy message-level Email routing tags remain
  separate.

The forward migration preserved `email_message_classifications` and their events as compatibility
history. It promoted only one unambiguous identical source snapshot and records unmapped/conflicting
sources in `email_conversation_classification_migration_issues` instead of guessing.

### EmailMessageClassification — `app/Modules/Email/Models/EmailMessageClassification.php`
Backs `email_message_classifications`. These rows now remain compatibility history for the former
account/message classification boundary; current Mail assignment uses the durable conversation
classification above.

Important columns:
- Scope: `account_id`, `email_message_id`
- Classification: nullable `category_id`
- Assignment metadata: nullable `assigned_by`, nullable `assigned_at`

Tags are attached through the existing Taxonomy `taggables` polymorphic table with `module = email`.
Classification events are recorded in `email_message_classification_events` with compact before/after
JSON snapshots. Provider flags and folders are not changed by category/tag assignment.

### EmailSmartInboxSuggestion — `app/Modules/Email/Models/EmailSmartInboxSuggestion.php`
Backs `email_smart_inbox_suggestions`. One row is a normalized, typed review proposal bound to the
requesting user, mailbox account, durable conversation, selected placement, exact source fingerprint,
and governed AI provenance.

Supported effect types are advisory review summary, existing category, existing tag, editable Task,
provider Archive, and same-account provider Move. Status is `pending`, `dismissed`, `applied`,
`stale`, or `revoked`. Source changes make a pending row stale; user/account/access loss makes it
unreadable/revoked through normal endpoints. `email_smart_inbox_suggestion_events` is the append-only
state and application evidence. Suggestion rows store normalized proposals and bounded trace facts,
never raw prompts/responses, HTML/raw source, body copies, attachment names/content, addresses,
credentials, or secrets.

Manual analysis is the only generation trigger in the current approved slice. It reuses the governed
Mail summary path and does not apply an effect. Reviewed apply is allowlisted to an existing active
Email category, an existing active tag, or an editable internal Task through the target domain's
normal action. Supervised cleanup records one deterministic provider operation for Archive/Move,
preserves provider Seen and personal unread, and inherits normal recovery plus verified Undo.

New suggestions record the current conversation fingerprint schema. Schema v2 hashes active source
membership, message identity/content, receipt evidence, attachment count, and order-independent
attachment metadata, but not mutable Eloquent `updated_at`. Historical rows without a recorded schema
are evaluated as v1 so their original evidence remains meaningful. Migration `121200` added the
nullable schema marker and the exact repair recovered only five v1 suggestions proven to have been
falsely staled by the receipt-timestamp defect.

### EmailTicketConversationLink — `app/Modules/Email/Models/EmailTicketConversationLink.php`
Backs `email_ticket_conversation_links`. It records the Mail-owned relationship between a Ticket and
one email conversation while preserving the existing scalar `email_messages.ticket_id` compatibility
link.

Important columns:
- `ticket_id`, `email_message_id`, optional `email_mailbox_placement_id`, and optional `account_id`.
- Optional `email_conversation_id` when the durable conversation projection is available.
- `conversation_key` derived from `In-Reply-To`, `References`, or the message's own `Message-ID`.
- `relationship_role`, `audience`, `status`, `linked_by`, and link/unlink timestamps.

Ticket message capture still belongs to Ticket through `LinkInboundEmailToTicket`; this table gives
Mail a durable way to show that one Ticket may contain several independent email conversations.

### EmailRemoteOperation — `app/Modules/Email/Models/EmailRemoteOperation.php`
Backs `email_remote_operations`. It is the idempotent ledger for provider writes such as mark
seen/unseen, move, archive, trash, delete, flag changes, and custom folder rename/move/delete.

The current Mail workspace and API execute explicit Seen/Unseen, Flag/Unflag, Archive, Trash, and
same-account selectable-folder Move actions through this ledger. The Mail folder manager executes
custom provider folder rename/move/delete through the same ledger. Permanent provider delete and
unrestricted generic provider bulk actions remain separately gated; Smart Inbox cleanup can submit
only an exact reviewed max-50 Archive/Move snapshot through ordinary per-item operations.
`/tech/mail` shows a Mailbox operations card for active pending/running/failed operations and recent
acknowledged results on accounts the user can organize. The stateful Livewire surface is rendered in
the Mail right bar, starts collapsed, and keeps compact status counts in its header. After expansion
it shows the sanitized reason, mutation/evidence attempt counts, failure classification, next safe
retry time, and recent acknowledged results. Eligible failed/pending rows can be retried or cancelled
through shared row-locked actions. Recent Seen/Unseen, Flag/Unflag, Archive, Trash, and Move results
expose Undo only during a 15-minute verified window.

`EmailRemoteOperationAttempt` is append-only after its one running-to-finished transition. Each
execution starts as `preflight`, is promoted to `mutation` only at the provider-write boundary, or is
recorded separately as `reconciliation`. All kinds retain sanitized start/finish evidence without
mail content, MIME, attachment data, or credentials. Operations snapshot placement version, UID,
UIDVALIDITY, and source folder (or folder update evidence) before execution. Current requester
authority, account activation, and that identity snapshot are rechecked before IMAP is touched;
stale or revoked work is superseded. Transient failures use bounded exponential backoff with a
five-attempt ceiling. Message work first runs an exact UID SEARCH that does not fetch headers or
content. A missing source becomes terminal stale work without exposing Webklex's `no headers found`
exception or issuing another provider mutation. Raw provider exception text never enters the
user-facing reason/error fields. A genuine provider read failure remains distinct and available for
controlled manual recovery instead of entering an automatic read-failure loop.
Ambiguous outcomes reconcile provider state first and are never blindly replayed. Only mutation
attempts consume that ceiling, so repeated connection/read preflights and reconciliation can still
establish an outcome afterward. An ambiguous Move is replayable only when an authoritative
target-folder inventory proves no target copy exists; source-plus-target duplicates or
folder-discovery errors remain blocked. An ambiguous
Archive/Trash/Move row without its immutable target folder path and target UID remains visible but
cannot be retried manually.

Archive and Trash targets are resolved per account from provider SPECIAL-USE or the exact canonical
folder leaf, with explicit SPECIAL-USE and shallow folders preferred deterministically. A custom
child below Archive or Trash is not treated as the special folder merely because its parent path
contains that name. Normal folder discovery repairs old rows whose descendant role was inferred from
an ancestor.

Each new successful supported operation captures an immutable metadata-only pre/post result snapshot
covering placement, folder, sync version, UID, UIDVALIDITY, and provider flags. An Undo creates one
uniquely linked inverse operation and executes it through the same ledger, attempt, authorization,
retry, and ambiguity-reconciliation path. Seen/flag inverses stay on the exact same placement; move
inverses require the acknowledged target UID and move it back to the still-selectable original
folder. Current actor Organize access, account status, local evidence, later operations, and provider
state are checked immediately before every inverse write. Stale, missing, revoked, or ambiguous
evidence stops without provider mutation. Repeat Undo submissions return the existing inverse.

API clients inspect Undo eligibility with
`GET /api/v1/email/mailbox/remote-operations/{operation}/undo` and apply it with the matching `POST`.
The endpoints retain hidden-404 mailbox scoping and require `email.read` / `email.update`
respectively.

`RetryDueEmailRemoteOperations` is scheduled every minute on the `email` queue. The job is unique and
overlap-protected, while each operation is claimed with a database row lock. A scheduler runner and
an Email queue worker are required for automatic recovery.

### Provider deletion reconciliation records
Provider-deletion inventory, findings, and cleanup attempts keep bounded account/folder/UID evidence
for provider-originated move or deletion without storing mail content. A finding hides the exact
placement as a seven-day tombstone only after a complete inventory is stable at both ends. Exact
provider reappearance restores it; a move is recognized only when conservative target evidence is
already projected.

After grace, cleanup repeats active-placement, unresolved-operation, retention, and Ticket-evidence
checks under lock. Only eligible Mail-owned payload, local files, tags, and source-derived Smart
Inbox artifacts are removed. Ticket-owned evidence remains separate. All dispatch, reconciliation,
and cleanup jobs fail closed unless `EmailProviderDeletionSettings` reads the exact Admin opt-in
value `1`; missing, malformed, and default `0` values disable the workflow.

### Provider-originated reconciliation records

`EmailProviderReconciliationRun`, `EmailProviderReconciliationFolder`, and
`EmailProviderReconciliationItem` are the durable Order-7 state machine. They are not a second copy
of the provider mailbox and do not authorize provider writes.

- A run owns one account active slot, immutable provider-binding generations, cancellation intent,
  byte-exact start/end folder scope, bounded local-folder materialization, a monotonic automation-
  unsafe flag, aggregate counters, and a sealed folder/item summary.
- A folder run freezes the active UID namespace and start/end UIDVALIDITY, UIDNEXT, EXISTS and
  mailbox-local MODSEQ capability, plus bounded scan, placement-snapshot, NOMODSEQ verification, and
  item-summary cursors.
- An item stores only a durable import, deviation, absence/move/operation candidate, or required
  observation. It carries exact UID/namespace, provider flags, placement versions, frozen strong
  identity where available, retry evidence, and independent historical-baseline and automation
  substates.

Every folder/item/final-summary page is capped, row-locked in run-first order, and compare-and-swap
guarded by the active slot, run phase, cancellation intent, folder reason/cursor, and item attempt.
Database constraints and SQLite/MariaDB guards reject incoherent, mutable, partially null, or
post-summary evidence. Placement observation stores the last provider run, observed sync version,
strong identity hash, and timestamp. Reconciliation-created occurrences begin hidden with an exact
pending marker; ordinary Mail queries and attachment/raw/rule paths require active, non-missing
placements.

The cross-module `notification_inbound_external_deliveries` table is Notification-owned. It is a
payload-free outbox created atomically with each new canonical inbound notification that requested
Email, Web Push, or Nextcloud Talk. The row freezes requested channels and safe Email-account binding
facts. Delivery reauthorizes the current recipient/source/type/preferences and exact mail binding;
suppressed or unresolved outcomes are terminal and never blindly replayed.

### EmailAttachment — `app/Modules/Email/Models/EmailAttachment.php`
Backs `email_attachments` (`database/migrations/2025_11_11_000003_create_email_attachments_table.php`).
- Links to `message_id`; stores `filename`, `content_type`, `size_bytes`, disk `path`, inline flag `is_inline`, `cid`, `checksum_sha1`.

The Mail reader lists stored attachment rows under their exact selected placement and downloads them
through `tech.mail.attachments.download`. The controller rechecks active placement, account/message
ownership, current Mailbox View access, the private Email disk, a normalized
`email/attachments/*` path, real-file containment, and safe response headers. The global Email route
permission returns 403 when absent; mailbox/context mismatches return a hidden 404. Inline parts may
be downloaded but this slice does not add inline preview.

`email:recover-attachments` is the bounded operator path for historical missing rows/files. It
requires explicit message IDs, defaults to a limit of 50, caps one invocation at 100, and performs a
read-only local preflight unless `--apply` is supplied. Apply first requires the hardened
`received_at` schema and writable attachment storage. It prefers a reparsable local raw snapshot and
reuses `InboundAttachmentPersister` under row locks so repeated runs do not duplicate rows/files.

The optional provider fallback is read-only and exact: it uses the placement's folder plus UID and
UIDVALIDITY, `ST_UID`, `leaveUnread`, and limit 1, with no folder or newest-message fallback. It checks
normalized `Message-ID` and rechecks the folder namespace after the fetch. Uncertainty preserves the
higher existing attachment-count evidence and reports a stable failure instead of fabricating an
empty result. The browser download route never performs this recovery or an IMAP read.

Before provider fallback, recovery also understands the exact historical persister path
`email/attachments/{account_id}/{imap_uid}`. It is accepted only when the message has zero attachment
rows, a positive counter within policy, the direct file count exactly equals that counter, and every
file is a non-symlink regular file contained below the attachment root with allowed size and detected
MIME type. A count, containment, symlink, size, or MIME-policy failure rejects the whole legacy source
without partial persistence. Exact legacy evidence never triggers a provider search.

After the ACL repair, readiness reported `safe=true` and `received_at_schema_safe`. The first
controlled Dev phase produced 28 rows across 16 messages: 24 parts from 13 local snapshots and four
from exact provider reads for messages `456`, `478`, and `479`. Exact provider reads for `4`, `5`, and
`10` returned `provider_message_missing`, but their exact account-and-UID legacy directories later
provided two count-matched files each. First legacy apply recovered 6 / 6 rows/files while each
counter remained two; the second live apply returned `existing_rows_complete` for all three.

The bounded result is now 34 rows/counter 34 across all 19 target messages. Every newly referenced
legacy result passes stored-size and SHA-1 integrity checks, and idempotency reruns remain unchanged.
The original legacy sources, duplicate account-2 legacy copies, and the broader unreferenced-file
inventory are preserved for separate provenance/retention/deletion review. The side-effect window
created no remote operation/attempt, rule attempt, outbound log, Ticket-domain
ticket/message/event/attachment, notification, or queued job. Focused attachment coverage passes 15
tests / 110 assertions; Pint, PHP syntax, and diff checks pass. Earlier adjacent provider-read
coverage passed 47 / 321, broad Email module/inbound coverage 155 / 1,308, and the complete Email
directory 347 / 3,030 before this narrow follow-up.

### EmailSignature — `app/Modules/Email/Models/EmailSignature.php`
Backs `email_signatures`. Each technician has one Mail-owned personal signature, edited from the
Profile workspace and surfaced below the page AI chat as a default-collapsed Mail right-bar card.
Expanding the card reveals the trigger for the responsive settings dialog.

Important columns:
- `user_id`, `name`, `body_html`, and generated `body_text`.
- `use_on_compose`, `use_on_reply`, `use_on_reply_all`, and `use_on_forward`.
- `created_by` and `updated_by` audit references.

The signature body supports safe HTML plus Mail-owned tokens such as `{user.name}`, `{user.email}`,
`{user.phone}`, `{company.name}`, `{company.phone}`, `{company.website}`, and `{company.logo}`. If a
technician has not saved a row yet, Mail renders the default template at send time.

### EmailComposerDraft — `app/Modules/Email/Models/EmailComposerDraft.php`
Backs `email_composer_drafts`. It stores local Nexum composer drafts for one signed-in technician and
one composer context.

Important columns:
- Scope: `user_id`, `email_account_id`, optional `email_message_id`, optional
  `email_mailbox_placement_id`, `mode`, and a stable `draft_key`.
- Content: `to_recipients`, `cc_recipients`, `subject`, sanitized `body_html`, generated
  `body_text`, and the outbound `idempotency_key`.
- Lifecycle: `status` (`active`, `sent`, `discarded`), `last_saved_at`, `sent_at`, and
  `discarded_at`.
- Provider Drafts sync: `provider_draft_status`, provider folder path, UIDVALIDITY, UID,
  Message-ID, sync/delete timestamps, and error code/message.
- Attachments: `email_composer_draft_attachments` stores durable local draft attachment metadata and
  local-disk paths.

New Compose drafts are scoped per technician and sender account. Reply, Reply All, and Forward drafts
are scoped per technician, selected mailbox placement, and account. Autosave remains local-only.
Manual Save draft writes a provider Drafts copy when the mailbox has a discovered selectable Drafts
folder. Candidate folders must also be sync-enabled and are re-inferred from their current
SPECIAL-USE/exact-leaf evidence; a stale stored Drafts role on a descendant is ignored. Explicit
SPECIAL-USE wins, followed by the shallowest deterministic canonical path, and the chosen row's role
projection is repaired. Provider Drafts-folder messages that arrive through normal IMAP sync are
also shown separately as provider draft placements. A provider Drafts placement can be opened with `Edit draft`
when the technician has View and Send access, then sent through the normal SMTP path; the original
provider Drafts UID is cleaned up afterward when safe.

Before connecting to IMAP, manual provider sync stores a tokenized durable `append_reserved` claim.
Only that token can transition to `append_started` immediately before the write. A fresh reservation
blocks concurrent saves; a reservation whose provider write has not started may be taken over only
after five minutes. Autosave and draft-attachment changes preserve reserved, pending, and unresolved
state. Once APPEND may have started, an unconfirmed response remains unresolved and later calls may
reconcile/refresh but never issue a second APPEND.

After a successful manual APPEND, Mail queues one `RefreshEmailProviderDraftFolder` job for the exact
draft, account, and Drafts folder. It shares the account-fetch overlap lock, requires an established
folder baseline, verifies UIDVALIDITY, fetches at most 50 new headers after the local high-water mark,
and imports only the exact normalized Message-ID with Inbox automation disabled. This makes the saved
copy visible promptly without trusting pre-APPEND UIDNEXT as its final identity. If the copy is not
yet visible, the draft stays pending and normal account sync remains the fallback.

### EmailComposerDraftAttachment — `app/Modules/Email/Models/EmailComposerDraftAttachment.php`
Backs `email_composer_draft_attachments`. It stores draft-scoped attachment metadata, local disk
path, size, MIME type, checksum, position, and owner. Saved draft attachments are restored into the
composer, included in SMTP sends, included in provider Drafts append, and deleted when the owning
draft is sent or discarded. Client-supplied attachment IDs are rechecked against the exact active
draft and its currently authorized mailbox composer context before SMTP.

### EmailHealthCheck — `app/Modules/Email/Models/EmailHealthCheck.php`
Backs `email_health_checks` (`database/migrations/2025_11_11_000004_create_email_health_checks_table.php`).
- One row per periodic check: timestamps, IMAP/SMTP status strings, error code/message, and `durations_json` for timings.

### EmailLog — `app/Modules/Email/Models/EmailLog.php`
Backs `email_logs` (`database/migrations/2025_11_11_000005_create_email_logs_table.php`).
- General-purpose structured log for inbound/outbound events with `direction`, `scope`, `level`, optional `account_id` and `email_message_id`.
- Mail replies, reply-all messages, forwards, and new composed messages atomically reserve their
  unique `idempotency_key` and RFC `Message-ID` before SMTP. The row progresses from reserved to
  accepted or unresolved, so concurrent/repeated submission cannot elect a second sender and an
  ambiguous provider outcome cannot be replayed blindly.


## Services

### ImapClient — `app/Modules/Email/Services/ImapClient.php`
A thin wrapper around Webklex to connect to a specific account and interact with provider folders.
- `connect()`: resolves the exact source and expected positive binding version through
  `EmailAccountProviderRuntimeResolver`, then builds a pinned verified-TLS transport through
  Integration. It never selects a legacy or system fallback for an Integration-bound account.
- `fetchUnseen(limit, page)`: opens INBOX and returns a bounded unseen page for explicit diagnostics. Automatic polling deliberately does not interpret unread state as backlog work.
- `fetchRecent(limit)`: opens INBOX and returns the newest messages regardless of Seen state for explicit diagnostics and compatibility.
- `mailboxState()`: returns INBOX `UIDVALIDITY` and `UIDNEXT` so automatic polling can establish and verify a durable live boundary.
- `folders()`: discovers provider folders and their current sync state without mutating the mailbox.
- `createFolder(folder)`: creates one custom provider folder with IMAP CREATE and returns folder
  state for local projection.
- `fetchAfterUid(uid, limit)`: fetches the oldest bounded batch after the stored live boundary regardless of Seen state.
- `fetchAfterUidInFolder(folder, uid, limit)`: fetches the oldest bounded batch after a folder
  baseline.
- `fetchByUid(uid, folder)`: loads a specific message by IMAP UID from the selected folder for full
  body/attachments.

Implementation notes:
- Every provider-I/O caller holds the per-account provider lock through re-resolution, connect/auth,
  operation, and disconnect. Durable work must supply its frozen binding version; immediate work
  captures and rechecks the current version under that lock.
- Integration enforces IMAP 993 implicit TLS or 143 STARTTLS (or a uniquely named installation
  policy), pins the approved resolved address, keeps the original host for SNI/peer-name checks,
  verifies certificates, rejects self-signed certificates, and requires TLS 1.2 or newer.
- Header metadata is parsed from Webklex's supported `getHeader()->raw` source. Folded values are unfolded while repeated `Received` and `Authentication-Results` fields retain top-to-bottom order. Missing or malformed authentication evidence remains empty and therefore fails closed.

### EmailTestService — `app/Modules/Email/Services/EmailTestService.php`
Runs a live connectivity test for both IMAP and SMTP and updates the account’s health.
- Holds the account provider lock, resolves one exact runtime snapshot, and performs authenticated
  IMAP then SMTP probes through the same Integration transport policy.
- One absolute deadline covers resolve, both probes, and cleanup. It composes safely with Laravel
  Queue Worker's outer alarm, blocks pre-I/O when the outer budget is too small, and restores the
  prior alarm/handler after cleanup.
- Classifies and records errors via `imapErrorClassify()` / `smtpErrorClassify()` and populates `EmailTestResult`.
- Persists only stable sanitized result codes/messages. Wrapped provider/deadline exceptions never
  expose endpoint, username, password, pinned address, response, or ciphertext.

### EmailTestResult — `app/Modules/Email/Services/EmailTestResult.php`
Simple DTO for booleans, durations, and optional error codes/messages with an `overall()` status.

### SmtpAccountMailer — `app/Modules/Email/Services/SmtpAccountMailer.php`
Sends already-rendered outbound mail through one Integration-resolved Email account while holding
the account provider lock through transport stop.

- `send()` remains the legacy single-To method used by Ticket, Sales, Marketing, and Notification
  paths.
- `sendMessage()` supports multiple To recipients, Cc recipients, attachments, and optional
  Message-ID/In-Reply-To/References headers for Mail reply flows.
- `generateMessageId()` lets the Mail send action reserve the exact outbound identity before SMTP.
  Account telemetry is best-effort after delivery and cannot turn provider acceptance into failure.
- Every queued/cross-domain caller freezes account ID and positive binding version before dispatch,
  rechecks it immediately before SMTP, and blocks stale/revoked/unready work without a network call.
  Credentials, endpoints, recipients, subject, body, and attachments are marked sensitive at trace
  boundaries and no runtime credential object can be serialized.

### EmailSignatureRenderer — `app/Modules/Email/Services/EmailSignatureRenderer.php`
Renders and updates the signed-in technician's Mail signature. It sanitizes stored HTML, renders user
and company tokens, creates a plain-text fallback, and appends at most one signature block to
outbound Mail composer content. Forward signatures are inserted before the forwarded-message block
so the technician's note and signature stay above quoted original content.

### EmailComposerDraftService — `app/Modules/Email/Services/EmailComposerDraftService.php`
Authorizes and persists local Mail composer drafts. It uses the same mailbox boundary as sending:
Compose requires effective Send access to the sender account, while Reply, Reply All, and Forward
drafts require effective View and Send access to the selected placement's account. The service
sanitizes draft HTML, stores a plain-text fallback, stores durable draft attachments, restores only
active drafts, attempts provider Drafts sync for explicit Save draft, and marks drafts as sent or
discarded after explicit user actions.

### EmailProviderDraftSyncService — `app/Modules/Email/Services/EmailProviderDraftSyncService.php`
Builds a safe RFC 822 `DraftEmail` with `X-Unsent: 1`, appends it to the discovered provider Drafts
folder with the IMAP `\Draft` flag, records best-effort response evidence, queues the exact bounded
Drafts refresh described above, and reconciles the authoritative imported UID by normalized
Message-ID. It re-infers selectable/sync-enabled candidates and uses the durable tokenized
reservation described above to elect one writer and block replay after an unresolved provider
response. Existing provider draft copies are replaced by best-effort delete plus the one reserved
append. Send and Discard best-effort delete an exact recorded provider UID; unresolved no-UID state
stays visible rather than falsely claiming that a provider copy was deleted.

### EmailPrivateStorage — `app/Modules/Email/Services/EmailPrivateStorage.php`
Writes private Email payloads only below normalized `email/*` paths on the established local disk.
For paths created by the current process it explicitly applies setgid/group-writable directories and
group-readable/writable files after creation, then verifies the final file before reporting success.
This protects raw MIME, inbound attachments, durable draft attachments, and Sent snapshots when
PHP-FPM and queue workers use different operating-system users or umasks. Existing paths owned by the
companion runtime are accepted only when usable; legacy restrictive paths still need one-time
owner/root normalization.

### EmailPrivateStorageInventory — `app/Modules/Email/Services/EmailPrivateStorageInventory.php`
Reconciles every current Email private-storage database reference with regular files below the
canonical local `email/*` root. The operator command `email:inventory-private-storage` is bounded and
strictly read-only. It redacts paths to stable IDs by default; `--show-paths` explicitly prints private
relative paths but never content. It reports reference scope, missing references, unreferenced files,
mode/group, size, modification time, SHA-1, and checksum+size duplicate groups. A truncated scan,
unsafe/symlink path, unreadable file, missing reference, or non-private mode fails the command, while
unreferenced status alone never authorizes deletion.

The verified redacted Dev snapshot inspected 939 files without mutation: `sent_pending` 322 (0
referenced / 322 unreferenced), `raw` 547 (465 / 82), and `attachments` 70 (34 / 36), totalling 499
referenced and 440 unreferenced. It reports 28 missing `message_raw` references, 79 non-private
`0644` files, and 12 duplicate unreferenced checksum+size groups. Focused coverage passes 3 tests / 21
assertions. The preceding structural audit found zero symlinks, unsafe paths, or unreadable files.
Neither the command nor duplicate evidence changes files, permissions, database rows, provider,
queue, retention, or deletion state.

### SendEmailComposerMessage — `app/Modules/Email/Actions/SendEmailComposerMessage.php`
Shared server action for `/tech/mail` Reply, Reply All, Forward, and new-message sends.

Selected-message sends require the actor to have global Email view/manage permissions plus effective
mailbox View and Send access for the selected placement's account. New compose requires effective
Send access to the chosen sender account and does not grant mailbox read access. The action parses
To/Cc recipient fields, sanitizes rich HTML composer content, generates the plain-text fallback,
prepares temporary uploaded attachments for SMTP, preserves reply threading headers where source
message headers exist, atomically reserves the existing unique outbound `email_logs` key plus a
stable RFC `Message-ID`, and sends synchronously through `SmtpAccountMailer`. The reservation is
created before any provider write; a simultaneous request reuses or is blocked by that row.
An unexpected failure before provider delivery keeps the composer open and returns a sanitized
`could not be prepared for sending` message; internal exception text is logged only by class/scope
and is not presented as a delivery result.

Reply All is shown only when the computed recipient list has more than one recipient after excluding
the selected mailbox itself. It defaults recipients from the source sender plus stored To/Cc
recipients, excluding the selected mailbox's own address aliases and deduplicating across To and Cc.
Forward sends are linked to the original source message only through the outbound Email log context.
They include a safe forwarded-message block in the composer body but do not automatically reattach
original inbound attachments.

Personal Mail signatures are appended by this action after composer-body validation and immediately
before SMTP. The composer and Mail AI controls edit only the message body; they do not rewrite the
signature. Each technician can edit the signature from `/tech/profile`, while `/tech/mail` shows
a compact default-collapsed right-bar card below the page AI chat and optional Mailbox operations
card. Expanding the card reveals the trigger for viewport-bound Bootstrap signature settings with
an explicit X, Cancel, and Save for Compose, Reply, Reply All, and Forward. The Mail AI runtime status
card remains a separate collapsed card below the Mail-specific signature controls.

After SMTP accepts an outbound message, `EmailSentReconciliationService` records a pending provider
Sent reconciliation row keyed by the outbound log, account, and normalized `Message-ID`. When normal
folder sync later imports a same-account Sent placement with that `Message-ID`,
`StoreInboundMessage` marks the row reconciled, writes `provider_sent` status metadata back to the
outbound `email_logs.context_json`, and `/tech/mail` shows a `Sent reconciled` badge on the Sent
copy. Mail also has backend support for appending a stored raw outbound snapshot to the discovered
provider Sent folder when no provider copy has arrived yet. That technical reconciliation work is
not exposed as a regular `/tech/mail` workspace dashboard, so it does not distract from the inbox.
Nexum checks for an existing same-account Sent placement with the same normalized `Message-ID`
before appending to avoid duplicates. The backend reserves an `append_started` transition under a
row lock; repeated started/appended calls are no-ops, and a failure after the provider write begins
stays blocked for reconciliation instead of risking another IMAP APPEND.

Before SMTP, Mail also attempts initial same-Message-ID reconciliation evidence. If the transport
throws after delivery may have started, Mail marks the outcome unresolved, shows `Do not resend it`,
and leaves the reservation in place for provider Sent review instead of trying again. If normal
same-account Sent sync later imports that exact reserved Message-ID, it resolves the log as accepted
without another SMTP call. A failed preliminary reconciliation write is recorded as
`reservation_failed`, not acceptance, and a racing exact Sent confirmation cannot be overwritten by
later ambiguous SMTP exception handling. Accepted and `accepted_reconciled` reservations are reused
without a provider call.

SMTP acceptance is the user-facing send boundary. If finalizing the accepted log, storing the raw
Sent snapshot, updating account telemetry, or recording its reconciliation fails afterward, the
durable reservation remains authoritative, the local draft is marked sent, and provider-draft
cleanup still runs. The workspace shows a sanitized warning that Sent follow-up failed and
explicitly says `Do not resend it`; it does not claim the accepted message could not be sent. A
repeat using the reserved idempotency key returns the accepted row or stays blocked as unresolved
without a second SMTP call. A newly written raw snapshot is deleted if its reconciliation row cannot
be persisted, so that failure does not leave an untracked private payload.
If marking/cleaning the local draft itself fails after acceptance, the composer still closes with a
sent warning and the durable reservation blocks resend, although that draft can remain locally
active until reviewed.

### SendEmailReply — `app/Modules/Email/Actions/SendEmailReply.php`
Compatibility wrapper around `SendEmailComposerMessage` for reply-only call paths and tests.

The action does not mark provider `Seen`, change personal `Unread for me`, move folders, create
Tickets, emit Signals, append to provider Drafts or Sent, or capture Ticket evidence. Provider Drafts
write sync, provider Sent append/deduplication support, Ticket projection, and API multipart sending
are separate later slices.

### Mail workspace triage actions
The `/tech/mail` command bar keeps common actions compact:

- The Mail page keeps the normal Work sidemenu and adds Mail-specific Views, Mailboxes, and Folders
  below it.
- The normal Folders navigation follows each provider mailbox's projected `parent_path` hierarchy.
  Parent branches start collapsed and can be expanded independently per mailbox. Explicit open and
  close choices persist per technician across sessions and devices; selecting a nested folder opens
  and remembers its ancestor path. A passive URL/reload does not override an explicit close; the
  closed ancestor is marked as containing the current folder. A provider
  `\Noselect`-style container is shown only when it owns a selectable descendant and never becomes
  a filter target; stale non-selectable leaves remain hidden. Selecting a child filters that exact
  provider folder, not its descendants. Folder badges remain folder-local provider **mailbox
  unread** counts and are never summed from child folders or confused with Nexum Unread for me.
- Search and a compact filter selector live in the message-list column. Filters cover all messages
  in the current scope, personal unread, provider mailbox unread, flagged, messages with attachments,
  and Ticket-linked messages. The list header includes Compose for users with at least one
  send-authorized account.
- The message list groups matching placements as account-scoped conversations. The newest matching
  placement is the visible row, and grouped rows show message context plus only the signed-in
  technician's personal **Unread** badge. Provider mailbox unread remains available through the
  filter, folder counts, detailed reader state, and explicit provider actions instead of adding a
  second unread badge to the compact parent or child list rows. Durable leaders are
  selected in the database before pagination instead of loading every match for PHP grouping. The
  selected row expands in place with an indented newest-first list of the authorized placements in
  that conversation. Clicking a child selects that exact provider placement and keeps the center
  list synchronized with the threaded reader. Parent counts remain scoped to the active view while
  the expanded list is explicitly labelled with the full authorized conversation count. The reader
  renders the current account-scoped conversation as a compact thread where only the selected
  placement is expanded; clicking another thread row selects that placement and makes command-bar
  actions apply to it. Durable lookup repeats exact account/conversation scope. The legacy
  header-matching fallback is limited to same-account placements without a durable conversation,
  starts bounded, offers Load more, and caps at 200. Conversation grouping never merges mail from
  different accounts.
- At desktop widths, the conversation list and reading pane share one bounded available-height grid.
  Their toolbars stay in place while the list and reader consume the remaining equal pane height and
  scroll independently only when necessary. Conversation rows use denser desktop spacing. Below the
  1200-pixel breakpoint the existing naturally stacked mobile/tablet flow and touch targets remain
  unchanged.
- Mail presents common RFC 2047 Q/Base64 encoded subjects as readable Unicode in the legacy Inbox,
  conversation list, expanded conversation children, reader, recent-operation labels, and
  Reply/Forward subject presentation. The formatter also conservatively salvages a truncated final
  encoded word such as those exposed by some IMAP libraries, strips header controls, and leaves HTML
  as escaped text. The raw stored subject remains the identity-bearing value used by rules,
  TD/SO Ticket-number extraction, provider evidence/fingerprints, Smart Inbox fingerprints,
  conversation identity, and the API's returned `subject` field.
- Decoded historical subject search uses a separate hidden, non-fillable, nullable 512-character
  `email_messages.subject_search` projection. `EmailMessage::searchText()` keeps raw subject,
  decoded subject, sender name/address, and plain-text body branches inside one parenthesized clause,
  and is shared by `/tech/mail`, legacy `/tech/inbox`, and
  `GET /api/v1/email/inbox/messages?q=...`. `%`, `_`, and `!` in the user's term are escaped as
  literal text, so they cannot broaden the query as SQL wildcard syntax. Existing outer mailbox
  View, account, folder, Ticket/state, database conversation grouping, and pagination constraints
  remain authoritative. The API still serializes only the raw `subject`, not the derived projection.
- Migration `2026_08_15_121000_add_email_message_subject_search.php` adds and initially backfills the
  projection in bounded ID chunks without changing `updated_at`. The forward-only
  `2026_08_15_121100_harden_email_message_subject_search_backfill.php` idempotently rebuilds missing
  or stale values with a compare-and-swap check against the originally read raw subject and
  projection, so a concurrent fresher subject writer wins while unrelated state writes do not prevent
  repair. Eloquent writes derive the current value. These migrations intentionally write no provider,
  rule, Ticket, or conversation state, but Dev's old MariaDB `received_at` definition implicitly
  changed receipt evidence during the backfills. Migration `121200` removes that clause; its bounded
  repair restored 471 evidence-supported timestamps, left 19 unresolved candidates untouched, and
  recovered exactly five false-stale Smart Inbox suggestions.
- One visible `Mark read` action changes only the current user's Nexum `Unread for me` state.
- Provider read/unread, flag/unflag, archive, and Move to folder are available from More actions.
- Trash is a visible icon-only provider action when a selectable Trash folder exists.
- Spam is a visible icon-only action for users with mailbox Organize access. It reuses
  `MarkEmailAsSpam` to tag the message and update the account-scoped spam rule, then archives the
  provider placement when a selectable Archive folder exists.
- Ticket is a visible icon-only action for users with mailbox Organize access and `ticket.create`.
  It reuses Ticket's `CreateTicketFromInboundEmail` action, records a Mail-owned conversation link,
  and linked messages show an Open Ticket icon for users with `ticket.view`.
- Link existing Ticket is available from More actions for non-draft messages when the user has
  mailbox Organize access and `ticket.update`. It records another Mail-owned conversation link while
  reusing Ticket's inbound email capture action.
- Provider flagging is shown with a yellow flag indicator in the list and reading pane. It remains
  provider mailbox state and does not create or remove categories or tags.
- Mail category and tags are separate Nexum classification metadata. Users with View plus Organize
  open **Category and tags** from More actions, then assign one Taxonomy category and several
  Taxonomy tags to the durable account conversation. Every placement in that conversation shows the
  same assignment; another mailbox account stays independent. Unknown tag names are created only
  when the user also has `taxonomy.manage_tags`.

Add rule is available from More when a safe rule path exists. For the signed-in owner's personal
mailbox, it opens a compact personal rule modal that shows matched rule execution history for the
selected message and creates one active safe rule: match sender, sender domain, subject, To, or Cc,
then move matching future Inbox mail to a same-account selectable folder or Archive. For shared and
system mailboxes, users with `email.rule_manage` are redirected to the Admin Email rules builder with
the selected mailbox and sender condition prefilled where that account is eligible for Admin rules.

Mail AI is available as a visible icon when the signed-in user has an active Email agent and
Integration's installation, provider, model, and optional agent governance allow that agent/model
runtime. Email settings lets admins choose the ordinary **Default Email agent** directly. Leaving
that field blank means `AiAgentResolver` falls back to the global default agent, such as Datanora.
Even when the selected/default Email agent is action-capable, these Mail AI actions do not call tools
or write APIs. The legacy `mail_ai_workload_profile_id` setting is cleared on the next Email
settings save and no longer controls Mail AI runtime selection.
Write-gated Mail AI actions are separate explicit buttons. The first one is AI-summary-assisted
Ticket creation, and it is visible only when the agent has action execution enabled, the required
Ticket API write scopes, and the user has the normal Ticket and mailbox permissions.
If the selected/default agent is blocked by Integration policy, the Email settings Mail AI card shows
a compact readiness reason and links to AI Settings. AI Settings has a standard **Activate AI** path
that records the installation, provider, and model approvals needed for normal user-triggered Mail AI
without exposing the full advanced governance form.

`SummarizeEmailWithAi` rechecks mailbox View access and sends selected message text, bounded
conversation text, mailbox metadata, and policy flags only. It asks the selected/default Email agent
through `AiChatResponder` with an explicit non-writing JSON prompt. Raw source, HTML, attachment
contents, and attachment filenames are excluded. The result panel is advisory and read-only:
summary, key points, questions, action items, suggested labels, urgency, reply-needed state, and
provenance notes. It does not send mail, draft replies, move messages, create Tickets or Tasks,
change Taxonomy, create rules, or run tools.

`AssistEmailComposerWithAi` uses the same selected/default Email agent runtime for shared composer
assistance. Reply and Reply All require effective mailbox View and Send access and can draft a reply
or rewrite current composer text. Compose requires only a send-authorized account and can use the
rewrite controls without sending source message context. Forward uses the selected placement with
View and Send access, sends only the technician-authored introduction as current composer text, and
Nexum preserves the forwarded-message block when the AI result is applied. The shared rewrite
controls can improve current text, shorten it, make the tone warmer, or rewrite it in Norwegian with
optional technician guidance. The request includes composer plain text only, not composer HTML or
attachments. Sendable responses are escaped into composer HTML and replace only the editable message
body. If AI recommends that no reply is needed for Reply or Reply All, for example for an automated
alert, the composer body is left unchanged and the reason is shown as advisory status. To, Cc,
Subject, attachments, idempotency key, provider state, Tickets, Tasks, rules, and Taxonomy are not
changed. AI apply results, no-reply advice, and composer AI availability errors are rendered through
the composer-local status surface instead of the workspace-level Mail alert.

### Smart Inbox review queue

The user-scoped Smart Inbox button appears above the conversation reader in `/tech/mail`, while its
controlled result region stays after the complete selected conversation so the email remains
primary. The button starts collapsed on initial mount, selection change, and a fresh return to the
message. It exposes synchronized `aria-expanded`/`aria-controls` state; opening moves focus and the
reader scroll position to the labelled result region, and the result close control returns focus to
the button. Both locations remain owned by the same scoped Livewire component and do not introduce a
second Alpine runtime or duplicate suggestion queries.

Analyze is always an explicit click and is offered only while the current user/account/agent retains
governed Mail AI read availability. The queue shows normalized status, reason, confidence, provenance,
and whether the proposed effect changes only Nexum metadata, creates a Task, or changes the provider
mailbox. Advisory summary suggestions have no Apply action.

Pending suggestions can be dismissed or corrected. The ordinary reader hides stale, dismissed,
revoked, unknown, or currently ineligible pending effects; it also hides the entire Smart surface
when there is no usable Analyze action, review summary, applied history, or executable pending action.
Applied results remain visible as history. A read-capable but write-disabled agent can still offer
Analyze and review summaries, while Apply, batch, correction, and rule-prefill controls that cannot
run are absent instead of producing a generic unavailable alert.

Current source fingerprint, active user/account, mailbox access, placement, exact recorded AI agent,
named scope, and target eligibility are checked for presentation and again by every write action. A
real conversation content or membership change becomes stale; schema-v2 fingerprints do not treat an
unrelated `updated_at` or derived projection write as a content change. Lost access or inactive/
replaced recorded-agent state becomes revoked/hidden rather than leaking an account-specific error.
Analysis repeats active-user/account and mailbox authorization after the AI provider returns and
before writing any suggestion; provider failures expose only a fixed safe message. Suggestions belong
to the user who requested analysis even when another technician can view the same shared mailbox.

Explicit reviewed application supports only:

- compare-and-set of one existing active Email category,
- additive assignment of one existing active Taxonomy tag, and
- one editable internal Task through Task's normal `StoreTask` and Work Context rules.

Application requires the exact named `email.update` or `tasks.create` agent scope plus the normal
user, mailbox, and target-domain authority. Wildcard scope or changing the default Email agent does
not grant an old suggestion new authority. Category apply never overwrites a different current
classification, tag apply creates no definition, and Task apply invents no assignee or due date.
Repeat clicks return the stored target reference.

Cleanup suggestions support only provider Archive or same-account Move to an existing selectable
folder. They record one deterministic `EmailRemoteOperation`, start provider I/O after the suggestion
transaction commits, preserve provider Seen and every user's personal unread state, and use the
normal recovery/verified Undo contract. Apply locks and matches the exact reviewed source placement,
folder, UID, UIDVALIDITY, and sync version instead of following a message moved in the meantime.
Bulk review snapshots an exact unique list of at most 50 cleanup suggestion IDs, reserves each source
once, and reports each item with its real provider status after fresh authorization.

`Always do this` creates no hidden learned rule. It returns only a prefilled existing personal modal
or Admin rule builder. The Admin link contains only a short-lived one-use opaque token; sender,
subject, name, and condition values are rebuilt server-side after current authorization. The Admin
prefill is inactive with `stop_processing=1`; normal explicit save/publication is required. Provider
cleanup rules use distinct `provider_archive` and `provider_move` actions. The legacy `archive`
action remains local-only.

### BodyNormalizer — `app/Modules/Email/Services/BodyNormalizer.php`
Converts HTML to plain text: strips scripts/styles/tags, decodes entities, collapses whitespace.

### HtmlSanitizer — `app/Modules/Email/Services/HtmlSanitizer.php`
Basic sanitizer that removes risky tags/handlers. Intended to be replaced with HTMLPurifier integration later.


## Jobs and flows

### High-level ingest flow
1) Polling picks active accounts and dispatches fetch jobs.
2) `FetchImapAccount` connects, discovers provider folders, records folder sync state, initializes a
   forward-only `UIDNEXT - 1` baseline per enabled selectable folder on first discovery, and then
   drains the oldest new UIDs after the greater of that folder baseline and the highest stored UID.
   Historical unread state is never automatic work, while bursts larger than one batch drain over
   later polls without UID gaps.
3) For each message:
	 - Oversize messages are flagged; normal-sized messages are handed to `StoreInboundMessage`.
4) `StoreInboundMessage` re-fetches full content by UID from the selected provider folder, stores raw
   EML and attachments, sanitizes/normalizes bodies, upserts `EmailMessage`, and writes the
   corresponding `EmailMailboxPlacement`. `EmailRawMessageSnapshot` preserves one reparsable RFC822
   header/body snapshot rather than a body-only fragment so a later bounded local attachment recovery
   has complete evidence.
5) Delete-on-success remains limited to Inbox/Ticket-ingress imports. Non-Inbox folder cache does not
   trigger provider deletion.
6) Personal mailboxes skip legacy shared/Ticket ingress. Their personal simple rules may still run
   owner-scoped safe provider actions before notification dispatch.
7) Explicit `preclassification` Email rules run first for Ticket-ingress mailboxes. They are opt-in and can stop later classification for narrow trusted handoffs.
8) `InboundEmailSignalClassifier` detects machine replies, delivery failures, and recognized vendor notifications. Matching messages become Signal records and are archived before normal ticket routing.
9) Remaining Ticket-ingress messages continue through `normal` Email rules and existing Ticket routing.
10) After routing completes, Email calls the Notification-owned inbound alert dispatcher. Notification
   creates at most one canonical notification per EmailMessage/user and, when an external channel is
   requested, atomically creates its payload-free delivery outbox. A delivery worker reauthorizes the
   current recipient and source before the requested Email/Web Push/Nextcloud Talk intersection runs.
   Notification owns source read synchronization without changing Email state or Ticket operational
   unread state.

Selected Email Rules can explicitly emit a Signal with the `emit_signal` action. This is for
admin-approved handoff cases such as vendor notices, monitoring messages, or security alerts. Email
still owns message parsing, tagging, archiving, thread linking, and ticket ingress. Signal owns
cross-module automation after the explicit handoff creates the normalized Signal.

### Job catalog (paths referenced in codebase)
- `app/Modules/Email/Jobs/PollActiveEmailAccounts.php` — iterates active accounts; schedule every minute. (Dispatcher/entry job.)
- `app/Modules/Email/Jobs/FetchImapAccount.php` — serialize fetches per account, discover provider
  folders, establish/verify each folder's forward-only UID namespace, select the oldest bounded
  new-UID batch, remove stored/soft-deleted UIDs, and dispatch `StoreInboundMessage` with a payload
  (marks oversize if > size limit). A changed INBOX `UIDVALIDITY` records an account error. A changed
  non-Inbox folder `UIDVALIDITY` marks that folder failed until explicit re-baseline.
- `app/Modules/Email/Jobs/StoreInboundMessage.php` - refetch full message by UID/folder, write raw
  EML, sanitize body HTML, upsert `EmailMessage`, write `EmailMailboxPlacement`, and persist
  policy-accepted attachment metadata/checksums before queuing rules.
- `app/Modules/Email/Jobs/ProcessInboundRules.php` - for Inbox/Ticket-ingress messages, run opt-in
  preclassification rules, machine/vendor classification, normal Email/Ticket routing, and the
  Notification-owned post-routing inbound alert dispatcher in that order. Personal no-Ticket-ingress
  mailboxes run only owner-scoped personal simple rules before notification dispatch. Non-Inbox
  placements are stored but do not run legacy Ticket/Sales/Signal ingress.
- `app/Modules/Email/Jobs/EmailAccountHealthCheckJob.php` — runs connectivity checks and writes `EmailHealthCheck` rows.
- `app/Modules/Email/Jobs/EmailRetentionPurgeJob.php` — purges only expired, definitively unplaced
  and unprotected local cache payloads, with a durable sanitized run/attempt ledger.
- `app/Modules/Email/Jobs/DispatchEmailProviderDeletionReconciliation.php` — daily bounded dispatcher
  for active accounts; it exits unless the exact provider-deletion opt-in is enabled.
- `app/Modules/Email/Jobs/ReconcileEmailProviderDeletionAccount.php` — scans one account with stable
  folder inventory evidence and records only confirmed move/loss/reappearance outcomes.
- `app/Modules/Email/Jobs/CleanupEmailProviderDeletionCache.php` — daily grace/retention cleanup of
  eligible terminal tombstones and Mail-derived data, with idempotent per-item evidence.
- `app/Modules/Email/Jobs/DispatchEmailProviderReconciliation.php` — every-minute due-account
  dispatcher. It processes one ordered page of at most 50 account IDs and serializes its cursor into
  the successor job; `--account` catch-up uses the same path.
- `app/Modules/Email/Jobs/ReconcileEmailProviderAccount.php` — bounded provider-scope/local-folder
  initialization under the account provider lease.
- `app/Modules/Email/Jobs/ReconcileEmailProviderFolderBatch.php` — one bounded UID metadata or
  mailbox-local NOMODSEQ verification page for one frozen folder/namespace.
- `app/Modules/Email/Jobs/ImportEmailProviderReconciliationItem.php` — exact bounded PEEK import with
  hidden Store persistence, current binding/claim reauthorization, and no provider-mutation authority.
- `app/Modules/Email/Jobs/ProjectEmailProviderHistoricalReadBaseline.php` — DB-only, max-100 viewer
  baseline page for hidden history in a newly discovered folder.
- `app/Modules/Email/Jobs/ProcessEmailProviderReconciliationAutomation.php` — runs the ordinary local
  inbound pipeline only after account-wide correlation has promoted a strong genuinely new Inbox
  occurrence; it claims only pending automation and cannot mutate the provider.
- `app/Modules/Email/Jobs/FinalizeEmailProviderReconciliation.php` — one bounded recovery,
  projection, correlation, cancellation-drain, folder-summary, or run-summary step.
- `app/Modules/Email/Jobs/TransitionEmailProviderReconciliationCancellation.php` — acquires the same
  account provider lease as provider work, then linearizes a committed cancellation intent and wakes
  finalization.
- `app/Modules/Email/Jobs/DispatchEmailProviderIdleListeners.php` and
  `ListenForEmailProviderChanges.php` — optional bounded all-account IDLE dispatch and short-lived
  hint listener. IDLE never replaces scheduled reconciliation.
- `app/Modules/Notification/Jobs/DispatchPendingInboundEmailExternalNotifications.php` and
  `DeliverInboundEmailExternalNotification.php` — bounded pending/outbox-loss recovery plus one
  current-recipient-authorized external delivery attempt. Ambiguous or abandoned attempts become
  unresolved instead of being replayed.

Scheduling: see `routes/console.php` for cron frequency; defaults are poll: 1m, health: 5m, retention:
monthly, provider reconciliation: 1m due check, inbound external-outbox recovery: 1m,
provider-deletion reconciliation: daily at 04:00, and provider-deletion cleanup: daily at 05:00.
Both provider-deletion jobs remain inert while their default-off setting is not exactly `1`.


## Controllers and views (Admin/Settings)

### AccountsController — `app/Modules/Email/Controllers/Admin/AccountsController.php`
- `index()`: list accounts — view `resources/views/Tech/admin/settings/email/accounts/index.blade.php`.
- `create() / store()`: create a mailbox-domain account bound to one active, exactly verified
  Integration provider — shared form view `.../create.blade.php`.
- `edit(EmailAccount) / update(EmailAccount)`: update account — same shared form.
- `toggleActive(EmailAccount)`: quick activation toggle.
- `test(EmailAccount)`: runs `EmailTestService::run()` and flashes `email_test` data back to the form.

Validation enforces required fields, ownership rules, grant payloads, and uniqueness (`address`).
Host, port, transport, username, secret, and auth fields are prohibited. New bindings are
re-authorized and revalidated against the exact active credential while the shared provider lifecycle
and database row locks are held. Private providers are absent and forbidden unless the actor also
has private-endpoint authority. An existing binding cannot be changed through the ordinary edit form.

Views:
- Index: lists account kind, owner, Ticket ingress, discovered folder count, INBOX UIDVALIDITY, sync
  issue count, safe provider label/source/readiness, grant counts, default badges, health icon, and
  actions; routes prefixed with `tech.`. Endpoint and username values are never shown.
- Create/Edit: unified form for a safe provider selection, kind/owner/Ticket-ingress policy, mailbox
  access grants, and a hidden POST form to trigger “Run Full Test” to the `test` action.

### ConfigController — `app/Modules/Email/Controllers/Admin/ConfigController.php`
- Persists provider sync, local cache, attachment policy, legacy cleanup, and advanced automation
  trust settings in `common_settings`.
- Updates the default Email agent by setting the selected active agent's `default_domains` to include
  `email`. Clearing the field removes the Email domain default so Mail AI uses the global default
  agent instead.
- Clears the legacy `mail_ai_workload_profile_id` setting on save. Mail AI runtime selection now uses
  only the selected Email agent or the global fallback agent.
- Mail AI buttons use `MailAiAgentRuntime` to check Integration policy readiness before they are
  shown or executed, so missing model governance hides the buttons and direct calls return the stable
  denial reason instead of a provider failure.
- When Mail AI has an agent but governance is not ready, the settings card shows the denial reason
  and links to AI Settings standard activation.
- The shared `common_settings.value` column is `TEXT` because Email attachment MIME allowlists,
  trusted-authentication lists, and workload settings can exceed 255 characters when saved from the
  full settings form.
- The destructive local-cache consequence of provider-side disappearance has a separate
  `provider_deletion_reconciliation_enabled` switch. It defaults to `0`; only exact `1` enables the
  scheduled inventory/cleanup jobs. Keep it off until the controlled checks in
  `HR-2026-08-14-015` pass.

### RulesController — `app/Modules/Email/Controllers/Admin/RulesController.php`
- Manages ordered inbound rules, including the explicit `normal` and `preclassification` routing phases.
- Rules are scoped through `email_rule_accounts` to selected shared/system mailboxes with Ticket
  ingress enabled. Personal accounts cannot inherit or run legacy shared rules.
- Every Admin save/toggle publishes an immutable `EmailRuleVersion` snapshot. Runtime execution uses
  the published snapshot and records idempotent `EmailRuleExecutionAttempt` rows per message,
  placement, rule, and version.
- Admin views and the `/api/v1/email/rules` read/preview API list admin-managed rules only.
  Personal simple rules remain owner-scoped Mail behavior and are not exposed through the Admin rule
  list.
- Admin rules distinguish the compatibility `archive` action, which remains local-only, from
  `provider_archive` and `provider_move`, which use the normal provider ledger. Provider actions
  reauthorize the active published-by actor and mailbox Organize at execution. Failure records later
  actions in that rule as skipped/not-run while other eligible rules may continue.

### Personal simple rules
- `CreatePersonalEmailRule` creates owner-only `personal_simple` rules from `/tech/mail`.
- `PersonalEmailRuleEngine` runs those rules for the owner's personal Inbox placements when legacy
  Ticket ingress is disabled.
- Allowed conditions are sender, sender domain, subject, To, and Cc. Allowed actions are move to a
  same-account selectable folder or Archive through `PerformEmailRemoteOperation`.
- Personal simple rules publish immutable versions and record `EmailRuleExecutionAttempt` rows, but
  they cannot create Tickets, emit Signals, send mail, call webhooks, stop processing shared policy,
  or permanently delete provider mail.


## Routes and naming

Declared in `app/Modules/Email/routes.php` with the `tech.` name prefix. Key routes include:
- `tech.admin.settings.email.accounts` — index
- `tech.admin.settings.email.accounts.create` — form
- `tech.admin.settings.email.accounts.store` — POST create
- `tech.admin.settings.email.accounts.edit` — edit form
- `tech.admin.settings.email.accounts.update` — PUT/PATCH update
- `tech.admin.settings.email.accounts.toggle` — toggle active
- `tech.admin.settings.email.accounts.test` — POST run connection test
- Additional: `tech.admin.settings.email.config`, `tech.admin.settings.email.rules`
- API: `/api/v1/email/rules` exposes read/preview endpoints through `email.rules.read`, intersected
  with `email.rule_manage` and mailbox View checks for previews.
- API: `/api/v1/email/mailbox/remote-operations` exposes account-scoped list/show through
  `email.read`, and guarded retry/cancel endpoints through `email.update` plus mailbox Organize.
- API: `/api/v1/email/mailbox/conversations/{conversation}/classification` exposes scoped
  read/replace/clear through `email.read` / `email.update` plus current mailbox View/Organize.
- API: `/api/v1/email/smart-inbox/suggestions` exposes user/account-scoped queue, count, show,
  dismiss, correct, and apply. Manual analyze is
  `/api/v1/email/mailbox/conversations/{conversation}/smart-inbox/analyze`. Read/analyze uses
  `email.read`; mutation uses `email.update`; Task apply additionally requires the request token's
  `tasks.create` ceiling. Every endpoint repeats mailbox and suggestion ownership checks and hides
  inaccessible IDs with Not Found.

Note: The UI relies on these exact names; ensure the `tech.` prefix is present in views and redirects.


## IMAP and SMTP behavior

All IMAP and SMTP consumers use Integration's source-strict runtime boundary. A `legacy` account can
use only its exact encrypted legacy fields under the same endpoint/DNS/pinning/TLS policy. An
`integration` account can use only its exact active and verified Integration configuration and
credential version. Missing readiness never falls back across sources or to Laravel's default
mailer. Queue payloads carry opaque account/binding facts, not endpoints, usernames, or secrets.

IMAP:
- Library: Webklex IMAP.
- Transport: IMAP 993 implicit TLS or 143 required STARTTLS unless one uniquely named installation
  policy allows another port. Every resolved answer is authorized, one address is pinned, and the
  original host remains the certificate peer name.
- Fetch strategy: discover enabled selectable folders, baseline each folder with `UIDNEXT - 1`, then
  fetch the oldest batch strictly after the folder high-water mark regardless of Seen state.
- Legacy Inbox/Ticket behavior remains Inbox-scoped. Sent, Archive, Trash, Drafts, and custom folder
  placements are cached as provider state and do not run inbound Ticket automation.
- Dedup: compatibility messages remain keyed by `account_id + mailbox + imap_uid`, including
  soft-deleted rows. Placement identity is keyed by account, folder, UIDVALIDITY, and UID.
  `UIDVALIDITY` changes fail closed instead of reusing an old UID namespace.

SMTP:
- Library: Symfony Mailer EsmtpTransport.
- Transport: SMTP 465 implicit TLS or 587 required STARTTLS unless one uniquely named installation
  policy allows another port. Authentication happens only after TLS; certificate/hostname
  verification and the TLS 1.2 minimum cannot be disabled.
- `/tech/mail` Reply, Reply All, Forward, and new compose use `SendEmailComposerMessage` and
  `SmtpAccountMailer::sendMessage()` to send through the selected mailbox account with validated
  To/Cc recipients, sanitized rich HTML, plain-text fallback, and temporary uploaded attachments.
  Reply and Reply All include standards-based threading headers; Forward is a new outbound message
  with the original source tracked in Email log context. An outbound `email_logs` row atomically
  reserves the unique idempotency key and exact RFC `Message-ID` before SMTP. Personal Mail
  signatures are rendered and appended immediately before that reservation/send boundary when
  enabled for the selected send mode.
- `/tech/mail` stores local Nexum drafts for Compose, Reply, Reply All, and Forward. Autosave runs
  while fields change, Save draft persists explicitly, Close keeps changed local draft content,
  Discard draft prevents later restore, and confirmed SMTP acceptance marks the matching draft sent
  even when Sent follow-up later warns. An unresolved transport outcome leaves the composer open but
  blocks another call with the same reservation. Draft attachments are stored durably with the local
  draft, restored in the composer, sent through SMTP, included in manual provider Drafts sync, and
  cleaned up when the draft is sent or discarded.
  Manual Save draft syncs only to an exact re-inferred selectable/sync-enabled provider Drafts folder
  and queues the exact bounded Drafts refresh. One tokenized reservation owns APPEND; a fresh
  concurrent claim is blocked, a pre-write claim is stale only after five minutes, and an unresolved
  provider response can be reconciled but not appended again. Autosave stays local-only and preserves
  that protective state.
- `/tech/mail` also shows provider Drafts-folder placements imported by normal IMAP sync. They have a
  dedicated Drafts view/filter and `Provider draft` badges. Ordinary Reply, Reply All, Forward,
  Spam, Ticket, and rule actions remain hidden for imported provider draft placements. Send-authorized
  users can open them with `Edit draft`, edit the content in the composer, send through SMTP, and
  clean up the original provider Drafts UID afterward.
- After SMTP acceptance, `/tech/mail` records a pending provider Sent reconciliation row. Normal IMAP
  sync later reconciles that row when it imports a same-account Sent placement with the matching
  `Message-ID`, and the workspace marks the provider copy with `Sent reconciled`. Stored raw
  outbound snapshots can be appended to provider Sent by Mail-owned backend code when a copy has not
  arrived, while keeping normal Sent-folder sync as final reconciliation. If the local snapshot or
  reconciliation record fails after acceptance, Mail reports the message as sent with a `Do not
  resend it` follow-up warning and marks its local draft sent instead of surfacing a false delivery
  failure.
- If SMTP starts but the provider outcome cannot be confirmed, the pre-existing reservation becomes
  unresolved and blocks another SMTP call for the same composer key. The technician must review
  provider Sent mail; Mail does not turn ambiguity into an automatic resend.
- `/tech/mail` lets organize-authorized technicians manage custom provider folders from the Folders
  header gear when one mailbox is selected or otherwise unambiguous. Create, nested create, rename,
  folder move, delete, and move-mail-before-delete run against the IMAP server first; deleting a
  custom folder requires moving any active mail out of the folder before the delete action becomes
  available.


## Storage layout and sanitation

- Raw `.eml` files, attachments, durable draft attachments, and outbound Sent snapshots stay on the
  established local disk. New Email writes go through `EmailPrivateStorage`, which accepts only
  normalized `email/*` paths, explicitly normalizes owner-created directories to setgid/group
  writable and files to group read/write, and verifies the final write before it is referenced.
  Paths are persisted in the DB (`raw_path` for messages; `path` per attachment), and `raw_path` is
  assigned only after verified storage succeeds.
- New raw and attachment paths include mailbox/folder identity so the same UID in different provider
  folders cannot collide.
- Dev's legacy `storage/app/private/email/raw/2` and `storage/app/private/email/attachments` roots are
  `www-data:www-data`; all 61 directories are `2770`, have group-rwx access/default ACLs, and contain
  no symlinks. The read-only command sees 939 total files and 79 `www-data`-owned non-private `0644`
  files that cannot be chmodded by the SSH project user. Root/operator must change only those 79 modes
  to `0660` without content, ownership, move, or deletion, then rerun the inventory and PHP-FPM/queue
  dual-runtime smoke. Recovery has 34 integrity-checked referenced rows/files across all 19 bounded
  targets. The 440 unreferenced files, including 322 Sent-pending, 82 raw, and 36 attachment files,
  remain preserved pending separate reconciliation, evidence, retention, and deletion review.
- `HtmlSanitizer` removes risky tags/handlers; replace with HTMLPurifier later for full safety.
- `BodyNormalizer::toText()` produces a readable plaintext version for search and previews.

## Local cache retention safety

Local cache age is necessary but never sufficient for deletion. The monthly
`EmailRetentionPurgeJob` and the read-only Admin preview both use
`EmailRetentionEligibilityService` as their single eligibility contract.

An expired message remains protected when it has any provider placement, unresolved provider
operation, unresolved Sent/correlation/classification reconciliation, Email/Ticket conversation
link, scalar Ticket link, captured Ticket message/event evidence, recognized legal-hold marker, or
an attachment on a storage disk this cleanup slice does not support. A hidden or provider-deleted
placement is still protected until a separate provider-deletion reconciliation flow has proved and
removed that placement safely.

Only an expired message with no placement and no protection reason is an eligible orphan. The job
deletes its local attachment files and raw EML first and then force-deletes the local Email database
row. Missing files are retry-safe. A failed file delete leaves the message and attachment rows in
place and records a failed attempt with a future retry date; it never records a false success.

`email_retention_purge_runs` and `email_retention_purge_attempts` contain only message/account IDs,
counts, stable reason/failure codes, and timestamps. They do not store subject, participant, body,
attachment filename, raw path, or provider secrets. Ticket-owned evidence is never deleted by this
job. Full legal-hold authoring/release, DSAR/export/erasure, account offboarding, backup expiry, and
cross-account lifecycle policy remain separate work.

Provider-deletion reconciliation now supplies that separate confirmation boundary. It compares only
complete stable bounded provider UID inventories, fails closed on UIDVALIDITY/cursor/count drift or
scan limits, and retains a hidden placement tombstone for seven days. Exact reappearance cancels
cleanup; a surviving placement, unresolved operation, retention protection, or Ticket evidence
continues to protect the source. Eligible terminal cleanup removes Mail-owned local data and
source-derived Smart Inbox artifacts idempotently while leaving Ticket-owned evidence intact. The
workflow is scheduled but disabled by default through the exact Admin opt-in described below.


## Health testing and monitoring

- From the account form, “Run Full Test” POSTs to `AccountsController@test` which calls `EmailTestService`.
- Results are flashed to the session and rendered in the form view.
- Periodic health checks should populate `email_health_checks` via `EmailAccountHealthCheckJob`.
- Error classification uses short codes (e.g., `IMAP_AUTH`, `IMAP_TLS`, `SMTP_AUTH`, `SMTP_CONNECT`).


## Configuration knobs

- See `ConfigController@index()` for provider sync defaults, local cache retention, attachment
  count/size/MIME policy, and advanced automation trust configuration.
- The Local Cache section includes a read-only retention preview with the current cutoff,
  eligible-orphan count, protected count, and protection-reason breakdown. It deliberately has no
  manual purge button.
- Normal Mail client sync keeps provider mail on the server. The legacy global cleanup switch is off
  by default and applies only to accounts explicitly set to `legacy_default`; per-account
  `auto_delete` remains the explicit server-cleanup policy.
- Settings are persisted as Email-owned `common_settings` values.

## Ordered Mail workstream migrations

The reviewed Dev rollout was deliberately staged with exact migration paths in this behavioral
order:

1. `2026_08_14_105000_harden_email_conversation_identity.php` — batch 86.
2. `2026_08_14_110000_create_email_conversation_classifications.php` — batch 87.
3. `2026_08_14_111000_add_email_retention_purge_audit.php` — batch 88.
4. `2026_08_14_112000_harden_email_remote_operation_recovery.php` — batch 89.
5. `2026_08_14_112500_complete_email_remote_operation_recovery.php` — batch 90.
6. `2026_08_15_113000_add_verified_email_remote_operation_undo.php` — batch 91.
7. `2026_08_14_114000_create_email_smart_inbox_suggestions.php` — batch 92.
8. `2026_08_14_115000_add_email_provider_deletion_reconciliation.php` — batch 93.
9. `2026_08_15_120000_create_email_folder_navigation_preferences.php` — batch 94.
10. `2026_08_15_121000_add_email_message_subject_search.php` — batch 96.
11. `2026_08_15_121100_harden_email_message_subject_search_backfill.php` — batch 97.
12. `2026_08_15_121200_harden_email_message_received_at.php` — batch 98.
13. `2026_08_16_100000_add_email_uidvalidity_namespaces.php` — pending review/deploy.
14. `2026_08_16_101000_create_email_historical_import_runs_and_items.php` — pending review/deploy.
15. `2026_08_16_102000_create_email_cursor_rebaseline_runs.php` — pending review/deploy.

The Undo filename is dated one day later than the Smart Inbox/provider-deletion filenames. A fresh
combined `php artisan migrate` therefore sorts `114000` and `115000` before `113000`. Those schemas
have no foreign-key dependency and that framework order is currently schema-safe, but it is not the
same staged behavioral rollout. Use sequential `--path` migration commands in the order above when
reproducing the reviewed rollout; never rewrite an already applied migration to change order.
The folder-navigation preference migration is independent of remote-operation ordering and must run
before the persisted folder tree is served. The subject-search migrations must precede `121200`; the
last migration removes the historical MariaDB receipt-timestamp update clause, freezes its repair
ledger, and adds the Smart Inbox fingerprint-schema marker.

After every Order 1–6 prerequisite, Order 7 adds these pending migrations in exact order:

1. `2026_08_16_118000_add_email_provider_reconciliation.php`.
2. `2026_08_16_118100_expand_email_message_mailbox_for_reconciliation.php`.
3. `2026_08_16_118200_add_inbound_notification_external_outbox.php`.
4. `2026_08_16_118300_add_authoritative_target_identity_to_email_remote_operations.php`.
5. `2026_08_16_118400_make_email_provider_paths_byte_exact.php`.

They add only schema, constraints, indexes, and rollback refusal; they do not read a provider, start
reconciliation, run rules, or deliver a notification. Apply them only after a database backup and
the pending `HR-2026-08-16-007` deployment checks. Their down paths intentionally refuse retained
reconciliation, outbox, target-identity, long-path, or observed-placement evidence.


## Scheduler and cron (server setup)

- Email polling runs via Laravel Scheduler (see `routes/console.php`).
- Ensure a system cron runs the scheduler every minute:

```cron
* * * * * cd /var/Projects/tdPSA && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

- Queue processing:
	- Development: set `QUEUE_CONNECTION=sync` (no worker required).
	- Production: start a worker, for example:

```bash
php artisan queue:work --queue=default,economy,email,notifications --sleep=3 --tries=3 --timeout=120
```

Notes:
- The scheduler dispatches the `PollActiveEmailAccounts` job every minute, which in turn enqueues `FetchImapAccount` per active account.
- The scheduler also dispatches one bounded due-account reconciliation page and one bounded inbound
  external-notification outbox recovery page every minute. Reconciliation uses the `email` queue;
  outbox delivery uses `notifications`.
- Optional IMAP IDLE uses the separate `email-idle` queue and a separately supervised worker. It is a
  latency hint only; scheduled reconciliation remains mandatory for correctness.
- `PollActiveEmailAccounts` updates the `email_last_poll_run` cache heartbeat when a real poll cycle starts.
- The poll-interval guard compares absolute elapsed time since that heartbeat; a stale heartbeat
  must not suppress future automatic fetch jobs.
- The Email Sync & Cache Settings `System Health` card reads active account count, sync pause status, latest successful fetch timestamps, account errors, queue table backlog, failed jobs, and the poll heartbeat. If the queue driver is not `database` or the queue tables are unavailable, the card reports monitoring as unavailable instead of guessing.
- Health checks, retention purges, default-off provider-deletion inventory at 04:00, and default-off
  provider-deletion cleanup at 05:00 are also scheduled in `routes/console.php`.

## Manual mail polling (on-demand)

The Tech Inbox view exposes a "Check now" button for on-demand ingestion without waiting for the next cron tick.
The Mail workspace message-list header also exposes "Send/receive" for organize-authorized
mailboxes, while the Folders header exposes a selected-folder refresh icon when the technician can
organize the selected folder's mailbox.

Implementation summary:
- Route: `POST /tech/inbox/poll` named `tech.inbox.poll` (defined in `app/Modules/Email/routes.php`).
- Controller: `InboxController@poll` (`app/Modules/Email/Controllers/Tech/InboxController.php`) loads active `EmailAccount` rows the actor can organize and queues `FetchImapAccount` per account.
- Livewire: `MailWorkspace::sendAndReceiveMail()` queues `FetchImapAccount` for every active
  organize-authorized mailbox, while `MailWorkspace::refreshSelectedFolder()` queues the same job for
  the selected folder's account.
- CLI: `php artisan email:poll` runs `FetchImapAccount` immediately for active accounts unless `--async` is supplied.
- View: `resources/views/Tech/Inbox/index.blade.php` includes a CSRF-protected form that posts to the route.
- Feedback: Flash message indicates how many accounts were queued for checking.

Use cases:
- Queue a fetch after adding an account or fixing credentials; results appear after the queue processes the jobs.
- From `/tech/mail`, queue a provider folder discovery and bounded fetch after another IMAP client
  renamed or changed folders.
- Development convenience through `php artisan email:poll` when a queue worker is not running.

Operational notes:
- UI polling depends on queue processing; for direct troubleshooting use the CLI without `--async`.
- Mail folder refresh intentionally queues account sync, not a folder-only shortcut, because provider
  folder discovery is account-wide.
- Safe to click multiple times; duplicate messages are deduped by `account_id + mailbox + imap_uid`
  and by provider placement identity.
- Existing unread mail is intentionally left untouched. More than one batch of genuinely new UIDs drains oldest-first over later polls; historical import requires a separate, explicit controlled workflow.
- If a trusted-source workflow reports missing authentication, verify that newly stored `headers_json` contains ordered `received` and `authentication-results` arrays. Nexum intentionally treats missing header evidence as untrusted.
- In production, prefer the scheduler + worker for steady-state ingestion; keep the manual button for ad-hoc checks.
- If automatic fetching stalls, check `/tech/admin/settings/email/config` first. `No heartbeat` means either `schedule:run` is not dispatching the poll job or the default queue worker is not processing it. Stale ready jobs or failed jobs in the health card point to queue-worker issues.

### Historical import and UID re-baseline

`/tech/admin/settings/email/accounts/{account}/mailbox-maintenance` is the separate advanced
maintenance surface. It requires both `email.account_manage` and `email.mailbox_sync_manage`; the
ordinary account form and normal mailbox View/Organize/Send grants do not imply this authority.

- Historical preview selects exact active/selectable folders, a UTC window of at most 31 days and a
  total cap (default 100, hard 500, with a lower installation policy winning). Provider discovery is
  metadata-only, scans at most 50,000 numeric UIDs globally in chunks of 1,000, retains at most
  `cap + 1`, and never changes the live forward cursor.
- Confirmation is bound to the 15-minute preview, UIDVALIDITY/UIDNEXT snapshot, current folder path,
  account policy and storage readiness. The Email queue processes at most 50 exact UIDs per batch,
  reauthorizes each batch and uses PEEK. Progress and errors store IDs/counts/reason codes only.
- Imported history uses the normal private raw/attachment projection but explicitly disables Inbox
  rules, Ticket/Signal/Notification/Smart/AI work and provider writes. The current unread epoch
  projector inserts `is_unread=false` for ordinary viewers without changing provider Seen or
  overwriting existing personal state.
- UIDVALIDITY re-baseline is a separate reason-bearing preview/apply path. A changed positive
  validity supersedes the old namespace and preserves its placements; a documented same-validity
  cursor recovery reuses the immutable namespace. Both set only the forward high-water and import
  nothing. Active/ambiguous provider work, changed placement counts, stale provider state or a busy
  shared account lock fail closed.

The migrations are `2026_08_16_100000` through `102000`. Deployment must seed permissions, restart
long-lived workers and verify the real scheduler/Email worker and private-storage runtimes. Migration
or deployment never launches an import or re-baseline; the first provider exercise remains a small
explicit non-production preview under `HR-2026-08-16-001`.

### Provider-originated reconciliation

The mailbox-maintenance surface also owns Order 7 provider-originated reconciliation. Start and
Cancel require an active human with both `email.account_manage` and `email.mailbox_sync_manage`,
current access to the exact active account, and a non-enumerating account/run binding. Operators may
use the same bounded entry point from the command line:

```bash
php artisan email:reconcile-provider --account=<account-id> --async
```

Omit `--account` only for the normal due-account path. The all-account dispatcher persists its cursor
and handles at most 50 accounts per job; each remote folder page handles at most 500 UIDs, local
folder and summary pages handle at most 100 rows, and each provider operation has a deadline. Durable
run, folder, item, historical-baseline, automation, cancellation, and summary state resumes after
worker or queue-dispatch loss without restarting an unbounded account scan.

Reconciliation is provider-read-only. It may LIST, EXAMINE, UID SEARCH/FETCH, and fetch an exact
bounded `BODY.PEEK`, but it cannot send, APPEND, STORE flags, MOVE, COPY, EXPUNGE, delete, or change a
folder. Every provider binding and active UID namespace is frozen and reauthorized at execution.
Stable complete provider evidence owns folder existence, placement, and provider flags; provider
Seen never changes **Unread for me** or opened receipts. Missing placement/folder projection waits
for stable negative evidence. Mailboxes without usable mailbox-local MODSEQ complete two matching
post-import UID+FLAGS inventories before projection.

An unknown provider UID is born as a hidden, Store-pending occurrence. Private raw/attachment
artifacts, canonical self-mapping, namespace, local version, Draft/Sent projection, and conversation
aggregates must all be accepted atomically before it becomes visible. Historical contents of a
newly discovered folder remain hidden until their bounded viewer read baseline completes. A retry
never generically rewrites an unrelated PREEXISTING active placement; it may resume only the exact
hidden reconciliation-pending crash row.

Provider moves and copies correlate only through frozen strong same-account evidence and exact
active target namespace/UID facts. An authoritative COPYUID target tuple is required for a confirmed
local operation result. A confirmed move transfers personal read/opened state only when every locked
source, target, version, and per-user epoch is collision-free; ambiguity leaves the source visible.

A live Inbox import enters `awaiting_correlation` until every remote folder and bounded local scope is
stable. Confirmed moves/copies, same-run duplicates, weak identity, drift, failed imports, or
ambiguous evidence never run rules. Only a strong zero-peer new delivery becomes pending for the
normal local inbound pipeline, always with provider-mutation authority disabled. Notification then
commits the canonical in-app alert with a payload-free external-delivery outbox row; the external
worker reauthorizes the current recipient, event, preferences, mailbox/Ticket access, and Email
provider binding before one Email/Web Push/Nextcloud Talk attempt.

Cancel is intentionally two-stage. The HTTP action records an idempotent cancellation intent, then
`TransitionEmailProviderReconciliationCancellation` waits for the same account provider lease used by
in-flight work before setting `cancelling` and waking bounded finalization. This prevents publication
after cancellation without interrupting a committed hidden file-reference/write sequence. Repeated
Cancel requests and a lost first transition dispatch are recovered idempotently.

Order 7 has released focused evidence of 122 SQLite tests / 1,383 assertions and 3 disposable
MariaDB 10.11 migration/guard tests / 434 assertions. Active durable-notification-fanout and remote-
operation access-path rework means this is not a claim that the current Order-7 candidate, broader
affected-module matrix, shared Dev database, scheduler, workers, provider, browser, or rollback
smoke is clean. Connectivity to Dev/Plesk MySQL is restored; read-only status currently reports 20
Pending migrations, the 19 released candidates plus unreleased `118500`, and none was applied.
Those checks and every manual review item remain Pending under `HR-2026-08-16-007`.

### Canonical message shadow correlation

`/tech/admin/settings/email/correlation` is the local, additive evidence surface used before any
canonical-message cutover can be considered. It requires an active human with
`email.mailbox_sync_manage` and ordinary current mailbox View for every exact account in the run.
Configuration authority is not content authority, inaccessible and nonexistent account/candidate
identifiers use the same hidden response, and the report itself contains metadata only.

`StartEmailCanonicalCorrelationRun` freezes one to 25 account IDs plus an optional inclusive
message-ID window. The default/hard limits are 2,000/5,000 messages, 250/500 discovery groups,
2,500/5,000 pairs, and 20/50 members per precise group. An exact boundary is accepted; the next
group or pair fails closed. The initial and final frozen snapshots each have a 64 MiB evidence-input
ceiling, and the complete run has a durable 256 MiB evidence-read ceiling. The lightweight
SQL/filesystem-size preflight must pass before raw snapshots are hashed.

Discovery is local and conservative. It starts only from a normalized Message-ID, exact stored
checksum, or a current explicit Ticket/conversation relationship; subject similarity never creates
a pair. `EmailCanonicalCorrelationEvidence` compares the complete available delivery variant,
including sender, To/Cc/Bcc, direction, delivery time, sanitized body, raw-source hash, and attachment
metadata/content hashes. Missing evidence remains ambiguous. Conflicting delivery evidence remains
different. If a precise discovery path overlaps an oversized path, deterministic oversized status
dominates so processing order cannot make the candidate look safer.

The additive `2026_08_16_110000_create_email_canonical_correlation_shadow.php` migration creates
run, candidate, and inspection-audit tables. `ProcessEmailCanonicalCorrelationRun` uses the `email`
queue with unique/overlap protection, rechecks the frozen fingerprint, reserves the aggregate byte
budget transactionally, and supports bounded resume or cancellation. Only versioned hashes, reason
codes, direction/completeness facts, opaque IDs, counters, and actor/timestamp audit are retained.
No subject, address, filename, body, header, raw source, or attachment content is copied to a shadow
row.

The metadata report can mark `needs_more_evidence` without opening content. Confirming a candidate or
keeping it separate first requires the same active reviewing actor to inspect the exact current
evidence. Inspection and review independently reauthorize ordinary View for both candidate account
IDs, require each message still to belong to its recorded account, and bind the audit to the exact
left/right evidence hashes. Evidence drift, account movement, access revocation, or an oversized
representative confirmation fails closed. A completed review decision is immutable. Shadow review
never merges messages, changes placements/read paths, widens authorization, or mutates Ticket,
conversation, provider, user-state, rule, Smart Inbox, search, attachment, or raw-source records.

Migration `110000` is still pending and was not run on shared Dev. Deployment must back up and record
authoritative counts, apply the additive migration, clear caches, rebuild group-writable views, and
restart long-lived Email workers; it must not start a run automatically. Ordinary rollback is
allowed only while there is no reviewed candidate or inspection audit. Reviewed/inspected shadow
evidence must first be explicitly exported or carried forward. Focused verification passes 19 tests
/ 131 assertions, and named migration/browser/worker/rollback review remains Pending under
`HR-2026-08-16-004`.

### Canonical message and placement cutover

Migration `2026_08_16_111000_add_email_canonical_message_placement_cutover.php` is the additive
expansion after shadow correlation. It creates common-content projections, ordered attachment
projections, one unique source-to-canonical mapping, durable cutover runs/items, account read modes,
durable whole-account parity attestations/items, and a nullable canonical pointer on each mailbox
placement. It does not backfill, change a read mode, call a provider, or rewrite/delete an
`email_messages` source occurrence. Source message and exact placement identity remain authoritative
for authorization, personal unread/opened state, Ticket and rule behavior, provider operations,
search results, routes, and API IDs.

Email Admin **Canonical cutover** requires both `email.canonical_cutover_manage` and
`email.mailbox_sync_manage`, plus ordinary current View for every account in the exact scope.
Break-glass and system actors never qualify. A POST creates only a bounded durable preview for a
self-map backfill, a reviewed merge, parity/drift audit, or mode change. Applying and rolling back
require a second typed confirmation. Any currently authorized operator may act on an accessible run
after its requester is disabled; `requested_by`, `applied_by`, and `rolled_back_by` remain separate
audit facts. Inaccessible and nonexistent run scopes are hidden alike.

`canonical-cutover-v1` recomputes all projected fields and hashes the actual private raw and
attachment files at preview and again before/under apply locks. It validates stored attachment
size/SHA-1 against the file and compares actual SHA-256. Each body/structured field is bounded;
structured input also stops at depth 24, 10,000 visited nodes, or 5,000 entries. Raw, attachment,
per-message file, item/component, and 256 MiB run budgets fail closed. A shared projection requires a
completed shadow run whose complete selected component is a strong, confirmed, exact-evidence
inspected clique with no retained keep-separate pair. Weak, ambiguous, oversized, incomplete, stale,
or partial components cannot merge.

Accounts default to `legacy`. `verify` keeps returning the source while exercising stored parity;
`canonical` overlays only common content onto a clone that retains the source ID/account/workflow
identity. Missing/partial deployment schema and any mapping, projection, source-state, actual-file,
or placement-pointer drift return the authorized source. A parity audit expands the complete mapped
component: pointer-only drift is repaired, while content drift dissolves every member into an
independent projection in one transaction. Raw and attachment routes always authorize the exact
active source placement. Attachment downloads perform the full file parity check but serve the
route-bound source part, never a metadata-selected canonical sibling.

An account with more than 500 active placements is not forced into one unbounded request and is not
permanently blocked from canonical mode. Email Admin records a resumable whole-account parity
attestation, verifies at most 100 placements per request, and materializes only one maximum-sized
source/projection at a time. A second currently authorized operator may continue after requester
offboarding. Completion binds the exact frozen scope, durable per-placement evidence, actual-file
hashes, byte count, and rolling fingerprint into the later mode preview. Preview and apply recheck
that complete fingerprint; placement/mapping/projection drift or an age above 15 minutes fails
closed. `canonical` requires strict file-backed pages, and ordinary reads still perform their own
live source/projection fallback check.

`StoreInboundMessage` attempts an idempotent self-map only after the complete source placement and
attachment projection is stored, and also repairs an existing duplicate occurrence. Failure is
sanitized and leaves authoritative inbound mail successful for later bounded backfill; it never
causes a blind provider retry. A one-source component may be refreshed, but the dual-write path never
rewrites or splits a shared component. The retention service reports
`canonical_projection_or_cutover_audit` before deleting files for any source retained by a mapping,
projection root, durable cutover item, or non-legacy account mode. Physical canonical-aware deletion
and removal of legacy columns remain separately reviewed future lifecycle work.

Deploy with every account effectively in `legacy`: back up and capture authoritative counts, run the
additive migration, seed permissions, clear caches, rebuild group-writable views with `umask 0002`,
and restart long-lived Email workers. No preview/apply is automatic. Use a small non-production
self-map preview, apply it, preview/apply `verify`, compare Mail/API/raw/attachment behavior, and only
then consider a separately previewed `canonical` mode. Return modes to `legacy` and roll applied runs
back newest-first; drift or a later overlapping run blocks unsafe rollback. A large account must
complete all bounded parity pages immediately before its mode preview. Migration down fails closed
while any projection, attachment projection, mapping, placement pointer, read-mode row, preview/run
item, parity attestation, or parity item exists; durable audit is never silently discarded. Human
verification is tracked under `HR-2026-08-16-005`; migration `111000` and live cutover/provider work
were not run during implementation.


## Extending and reusing components

Common extension points:
- Rules engine: implement rule definitions and runners in `ProcessInboundRules`. Keep them idempotent and fast; operate on stored `EmailMessage` records.
- Inbound notifications: keep Email's responsibility to one post-routing call into
  `DispatchInboundEmailNotification`. Notification owns recipient resolution, channel preferences,
  Web Push payloads, canonical notification identity, and read synchronization.
- Signal handoff: use Email Rule `emit_signal` only for selected messages that should become
  cross-module operational events. Keep broad email routing local to Email and Ticket.
- Signal classification: extend `InboundEmailSignalClassifier` when new inbound e-post signal types should be detected before ticket routing. Keep matching conservative so real customer requests are not archived accidentally.
- Sanitizer: replace `HtmlSanitizer` with a robust library (HTMLPurifier) and add CID image rewriting to signed URLs for inline display.
- Provider cleanup: normal accounts keep provider mail on the server. `auto_delete` removes newly
  imported Ticket-ingress mail after successful local storage; `legacy_default` preserves the old
  global cleanup switch for migrated accounts.
- OAuth2: add new `imap_auth_type` / `smtp_auth_type` handlers and token storage/refresh flow.

How other controllers/services can reuse this module:
- Use `EmailAccount` to select an account (global/subsystem default) and dispatch jobs:
	- Dispatch `FetchImapAccount` manually for on-demand ingest.
	- Use `EmailTestService` to validate connectivity before enabling an account.
- For Mail Reply, Reply All, Forward, and new compose, use `SendEmailComposerMessage` so mailbox
  authorization, To/Cc parsing, rich HTML sanitization, attachment handling, reply threading
  headers, personal signatures, idempotency, and `EmailLog` records stay consistent.
  `SendEmailReply` remains available as a reply-only compatibility wrapper.
- For subsystem outbound mail such as Ticket, Sales, Marketing, and alerts, keep using
  `SmtpAccountMailer` through the owning domain's guarded action/job until the broader outbound
  lifecycle slice replaces those call paths.
- For triage UIs, query `EmailMessage` with `state` and `labels_json`, eager-load `attachments`, and display `body_html_sanitized`.

Coding guidelines:
- Keep services stateless where possible; pass in `EmailAccount` explicitly.
- Prefer small, composable jobs with clear contracts and retry-safe behavior.
- Log failures to `EmailLog` with context for observability.


## Testing and troubleshooting

Decoded-subject search verification currently records:

- Search surfaces: **4 tests / 58 assertions**. With adjacent durable-conversation query and Mail
  navigation/readability coverage: **13 tests / 231 assertions**. This includes 30 durable
  conversations backed by 60 matching placements and database pagination of 25 plus 5 latest
  leaders.
- Projection coverage passes **9 tests / 56 assertions**. Projection plus all three search surfaces
  pass **13 / 114**, and the full focused package with adjacent regressions passes **22 / 287**.
  The complete Email test directory passes **349 / 3,066** after the desktop workspace polish.
- Dev migrations `121000`, `121100`, and `121200` have run in batches 96, 97, and 98. MariaDB reports
  no `ON UPDATE` clause on `received_at`; the ledger froze 490 messages. Preview/apply repaired 471
  evidence-supported values (439 header dates and 32 conversation boundaries), left 19 unresolved
  candidates untouched, and recovered exactly five false-stale Smart Inbox suggestions. Receipt repair
  plus adjacent Smart regressions pass **36 / 408**; an earlier combined reader/repair package passed
  **47 / 578**. Pause ordinary/default and `email` workers before another-environment rollout, then
  clear caches, rebuild views, restart/resume workers, and push Email Knowledge afterward.

Manual browser/API verification remains Pending under `HR-2026-08-15-004`.

Current integrated Dev evidence after runtime reliability hardening:

- Integrated runtime-focused package: **74 tests / 613 assertions** across private storage,
  pre-/post-SMTP safety, provider Sent APPEND, provider Draft APPEND/targeted refresh, composer
  lifecycle, remote recovery/preflight accounting, verified Undo, and supervised cleanup.
- Full `EmailModuleTest.php`: **141 tests / 1,227 assertions**.
- Full `InboundAutomationTest.php`: **14 tests / 81 assertions** against isolated fake Email storage.
- PHP syntax, Pint, Blade cache compilation, and diff checks pass. Named human reviews remain Pending
  and are not replaced by these checks.

Current Smart Inbox reader-first and attachment evidence:

- Smart Inbox reader/capability coverage passes **21 tests / 306 assertions**.
- Focused placement-bound attachment download/recovery coverage passes **15 / 110**, including exact
  count-matched legacy-directory recovery without provider access. The earlier adjacent exact provider
  `ST_UID`/`leaveUnread`/limit-1 package passed **47 / 321**.
- Focused read-only private-storage inventory coverage passes **3 / 21**. The live redacted command
  inspected 939 files, reported 499 referenced / 440 unreferenced, 28 missing raw references, 79
  non-private modes, and 12 duplicate unreferenced groups without mutation.
- The combined `EmailModuleTest.php` and `InboundAutomationTest.php` package passes **155 / 1,308**.
- Desktop workspace/Smart/navigation coverage passes **20 / 337**; `EmailModuleTest` plus supervised
  cleanup passes **153 / 1,408**.
- The complete Email test directory passes **349 / 3,066**.
- Attachment readiness is safe and the bounded 19-message operation is complete at **34 rows/counter
  34**. The final six rows/files came from the exact legacy
  `email/attachments/{account_id}/{imap_uid}` directories for messages `4`, `5`, and `10`, with no
  provider search; all six size/SHA-1 checks and the `existing_rows_complete` rerun pass. Legacy
  sources and duplicate account-2 copies remain preserved for separate unreferenced-file review.
  Manual browser/access review remains Pending under `HR-2026-08-15-006`.

Controlled Dev recovery for the reported incident used fresh exact provider evidence before changing
local state. Operation `23` was cancelled, stale source placement `474` was hidden, verified Trash UID
`30177` was projected as placement `485` in canonical folder `141`, the wrongly classified child was
repaired to `custom`, and draft `1` was marked sent/provider-deleted after exact Message-ID and
send-log identity matched. The provider post-check found the source absent, correct Trash copy
present, wrong child empty, and draft UID absent. The repair performed zero SMTP writes and zero IMAP
MOVE writes. No provider Sent copy was fabricated/appended because its exact Message-ID was absent
and no raw snapshot remained.

The code hardening and directory/ACL contract are verified, while broader private-storage human review
remains In Progress. A root/operator must first normalize the exact 79 remaining `www-data`-owned
`0644` legacy files to `0660` without changing content, ownership, location, or existence. Then rerun
`email:inventory-private-storage`, reconcile the expected 61-directory/939-file snapshot, complete
PHP-FPM and queue-worker write/read smoke, and perform the remaining runtime checks in
`HR-2026-08-15-003`. No deletion is authorized by the inventory.

Functional checks:
- Use the Accounts Create/Edit view to run “Full Test” and confirm IMAP/SMTP connectivity.
- Verify that polling schedules are running (scheduler + queues) and that `email_messages` grows when new mail arrives.

Common issues:
- “Target class [imap] does not exist” — ensure Webklex package is installed and configured; tests use the Facade.
- IMAP TLS/SSL negotiation failed — verify port/encryption pair and certificates.
- SMTP auth or TLS errors — check `smtp_encryption` mapping: 465/ssl vs 587/tls (STARTTLS).
- Route name errors — confirm `tech.` prefix in route names and views.


## Roadmap (next steps)

- Introduce HTMLPurifier and inline image rewriting.
- Add index health badges and per-row “Run test”.
- Restricted automatic external replies remain outside the implemented workstream. The RFC requires
  a separate explicit high-risk approval and ADR before implementation even though verified Undo and
  reviewed Smart Inbox actions now exist.


## File index (quick reference)

- Models:
	- `app/Modules/Email/Models/EmailAccount.php`
	- `app/Modules/Email/Models/EmailConversation.php`
	- `app/Modules/Email/Models/EmailConversationClassification.php`
	- `app/Modules/Email/Models/EmailMessage.php`
	- `app/Modules/Email/Models/EmailSmartInboxSuggestion.php`
	- `app/Modules/Email/Models/EmailSmartInboxSuggestionEvent.php`
	- `app/Modules/Email/Models/EmailSignature.php`
	- `app/Modules/Email/Models/EmailAttachment.php`
	- `app/Modules/Email/Models/EmailHealthCheck.php`
	- `app/Modules/Email/Models/EmailLog.php`
- Actions:
	- `app/Modules/Email/Actions/SendEmailComposerMessage.php`
	- `app/Modules/Email/Actions/SendEmailReply.php`
	- `app/Modules/Email/Actions/MarkEmailAsSpam.php`
	- `app/Modules/Email/Actions/UpdateEmailConversationClassification.php`
	- `app/Modules/Email/Actions/AnalyzeEmailConversationForSmartInbox.php`
	- `app/Modules/Email/Actions/ApplyEmailSmartInboxSuggestion.php`
	- `app/Modules/Email/Actions/ApplyEmailSmartInboxSuggestionBatch.php`
	- `app/Modules/Email/Actions/BuildEmailSmartInboxRulePrefill.php`
	- `app/Modules/Email/Actions/PerformEmailRemoteOperation.php`
- Services:
	- `app/Modules/Email/Services/ImapClient.php`
	- `app/Modules/Email/Services/SmtpAccountMailer.php`
	- `app/Modules/Email/Services/EmailSignatureRenderer.php`
	- `app/Modules/Email/Services/EmailTestService.php`
	- `app/Modules/Email/Services/EmailTestResult.php`
	- `app/Modules/Email/Services/EmailProviderDeletionReconciler.php`
	- `app/Modules/Email/Services/EmailProviderDeletionCleanupService.php`
	- `app/Modules/Email/Services/BodyNormalizer.php`
	- `app/Modules/Email/Services/HtmlSanitizer.php`
- Jobs:
	- `app/Modules/Email/Jobs/PollActiveEmailAccounts.php`
	- `app/Modules/Email/Jobs/FetchImapAccount.php`
	- `app/Modules/Email/Jobs/StoreInboundMessage.php`
	- `app/Modules/Email/Jobs/ProcessInboundRules.php`
	- `app/Modules/Email/Jobs/EmailAccountHealthCheckJob.php`
	- `app/Modules/Email/Jobs/EmailRetentionPurgeJob.php`
	- `app/Modules/Email/Jobs/DispatchEmailProviderDeletionReconciliation.php`
	- `app/Modules/Email/Jobs/ReconcileEmailProviderDeletionAccount.php`
	- `app/Modules/Email/Jobs/CleanupEmailProviderDeletionCache.php`
- Controllers (Admin/Settings):
	- `app/Modules/Email/Controllers/Admin/AccountsController.php`
	- `app/Modules/Email/Controllers/Admin/ConfigController.php`
	- `app/Modules/Email/Controllers/Admin/RulesController.php`
- Migrations: `database/migrations/2025_11_11_000001..000005_*.php`
- Routes: `app/Modules/Email/routes.php`, `routes/console.php`
- Views: `resources/views/Tech/admin/settings/email/accounts/*.blade.php`


---

If you ask for a change later, refer to the component above; this map shows where to edit behavior and how changes flow through the system.
