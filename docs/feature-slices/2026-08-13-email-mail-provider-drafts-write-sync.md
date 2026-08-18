# Feature Slice: Email Mail Provider Drafts Write Sync

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Let technicians save ordinary Mail composer drafts into the real provider Drafts folder while keeping
local Nexum draft autosave fast and safe.

## User-Visible Behavior

Manual `Save draft` still saves a local Nexum draft first. If the composer has no temporary
attachments and the selected mailbox has a discovered selectable provider Drafts folder, Nexum also
appends a `X-Unsent: 1` draft message to that provider folder with the IMAP `\Draft` flag. The
composer shows whether the provider copy is synced, pending provider confirmation, local-only, or
has a provider issue.

After the provider acknowledges APPEND, Mail queues one bounded refresh of that exact Drafts folder
and draft Message-ID. This makes the provider copy available in the Drafts view as soon as the queue
processes it, without waiting for an unrelated full mailbox poll. A UIDNEXT value observed before
APPEND is only best-effort evidence; the imported placement supplies the authoritative UID.

Autosave remains local-only so field changes do not create a new remote draft on every keystroke.
When a synced local draft is sent or discarded, Nexum best-effort deletes the recorded provider draft
copy by folder, UIDVALIDITY, and UID. If provider cleanup fails, the send/discard action remains
completed locally and the user gets a warning.

## Scope

- Add provider Drafts status, folder path, UIDVALIDITY, UID, Message-ID, timestamps, and error fields
  to `email_composer_drafts`.
- Add IMAP APPEND support for provider Drafts using the discovered real Drafts folder.
- Add a Mail-owned provider draft sync service for append/replace, cleanup, and import
  reconciliation.
- Manual Save draft attempts provider sync when the current composer has no temporary attachments.
- Autosave remains local-only.
- Send and Discard best-effort delete the recorded provider draft copy.
- Normal Drafts folder import reconciles provider draft UID/status back to the local draft by
  normalized Message-ID.
- Dispatch one unique, account-overlap-protected targeted Drafts refresh after successful APPEND.
- Require an already initialized selectable/sync-enabled Drafts folder, fail closed on UIDVALIDITY
  change, read only a bounded batch after the local high-water mark, and import only the exact
  normalized Message-ID with Inbox automation disabled.

## Out Of Scope

- Durable draft attachment persistence.
- Uploading temporary composer attachments into provider Drafts.
- Editing a provider Drafts placement directly from the read-only Drafts view.
- Shared draft locks, responder reservations, typing presence, or merge/conflict UI.
- Creating, renaming, or deleting provider folders from Nexum.
- Direct provider Sent append/deduplication, automatic replies, and AI write/send actions.

## Data Touched

- Migration `2026_08_13_120000_add_provider_sync_fields_to_email_composer_drafts`.
- Existing `email_composer_drafts`.
- Existing `email_folders` for real provider Drafts folder discovery.
- Existing `email_mailbox_placements` for later Drafts import reconciliation.
- Default Laravel queue work through `RefreshEmailProviderDraftFolder`; no scheduler registration is
  added.

## Permissions

Provider Drafts write sync uses the same mailbox Send access required to save a local Compose draft.
Reply, Reply All, and Forward drafts still require both View and Send access to the selected
placement's mailbox. Provider Drafts placement editing remains unavailable in this slice.

## Tests

- Manual Save draft syncs a compose draft to provider Drafts and stores remote evidence.
- Autosave keeps compose drafts local-only and does not append to provider Drafts.
- Discard deletes a synced provider draft copy and marks the local draft discarded.
- Successful SMTP send deletes a synced provider draft copy and marks the local draft sent.
- Existing Mail draft, send, folder, provider Drafts visibility, and inbound automation tests
  continue to pass.
- Targeted refresh is uniquely dispatched, shares the account fetch lock, remains bounded to at most
  50 new UIDs, rejects changed UIDVALIDITY, imports only the matching Message-ID without inbound
  rules, and leaves the draft pending when the provider copy is not visible yet.

## Automated Verification

- `php -l database/migrations/2026_08_13_120000_add_provider_sync_fields_to_email_composer_drafts.php`
- `php -l app/Modules/Email/Services/EmailProviderDraftSyncService.php`
- `php -l app/Modules/Email/Services/EmailComposerDraftService.php`
- `php -l app/Modules/Email/Services/ImapClient.php`
- `php -l app/Modules/Email/Livewire/Tech/MailWorkspace.php`
- `php -l app/Modules/Email/Jobs/StoreInboundMessage.php`
- `php -l app/Modules/Email/Tests/Feature/EmailModuleTest.php`
- `umask 0002; HOME=/tmp php artisan migrate`
- `umask 0002; HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php --filter='mail_workspace_manual_save_syncs_compose_draft_to_provider_drafts|mail_workspace_autosave_keeps_compose_draft_local_without_provider_append|mail_workspace_discard_deletes_synced_provider_draft_copy|mail_workspace_send_deletes_synced_provider_draft_copy_after_smtp_success'`
  passed with 4 tests and 38 assertions.
- `umask 0002; HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php`
  passed with 111 tests and 936 assertions.
- The 2026-08-15 targeted-refresh regression file passes with 4 tests / 35 assertions. Four existing
  provider Draft regressions pass with 40 assertions; targeted Pint, PHP syntax, and diff checks pass.
  No live provider call was made by that automated verification.

## Documentation

- Email README and Knowledge overview describe provider Drafts write sync and its attachment/editing
  boundaries.
- TODO keeps durable draft attachments and provider folder create/rename/delete as separate later
  slices.
- Human review tracks manual verification.

## Done Criteria

- Manual Save draft writes an RFC 822 provider draft when a real Drafts folder is available.
- Autosave does not perform provider writes.
- Send and Discard clean up recorded provider draft copies without blocking successful local
  lifecycle completion.
- Provider Drafts import can reconcile actual provider UID/status to the local draft by Message-ID.
- A successful manual APPEND queues an authoritative placement refresh instead of treating the
  pre-APPEND UIDNEXT hint as final identity.
- Focused and full Mail tests pass on Dev.
