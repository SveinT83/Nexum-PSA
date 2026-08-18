# Feature Slice: Email Mail Provider Drafts Visibility

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Make provider Drafts folder messages explicit in the Mail workspace without pretending local Nexum
autosave drafts have already become provider drafts.

## User-Visible Behavior

When normal IMAP folder sync imports new messages from a provider Drafts folder, `/tech/mail` shows
them as provider draft placements. Technicians get a dedicated Drafts view, a provider draft filter,
and compact `Provider draft` badges in the list and reader.

Manual provider draft saves now queue a bounded exact-folder refresh after APPEND, so their provider
copy can enter this same projection promptly without running Inbox Ticket/rule automation. Normal
account polling remains the fallback and final provider-authoritative path.

Provider drafts are readable cached provider placements in this slice. They do not open the existing
Reply, Reply All, or Forward composer actions, because editing or updating provider drafts is a later
provider-write slice.

## Scope

- Treat imported messages in an IMAP Drafts-role folder as `provider_draft` placements even if the
  provider does not expose the `\Draft` flag.
- Add a `/tech/mail` Drafts view and matching sidebar count.
- Add a compact provider draft list filter.
- Show provider draft badges in Mail list and reader.
- Hide ordinary Reply, Reply All, Forward, Spam, Ticket, and rule actions for provider draft
  placements.
- Reuse the same projection for the exact Message-ID imported by the post-APPEND targeted Drafts
  refresh.

## Out Of Scope

- IMAP APPEND, replace, or delete for provider Drafts.
- Linking a local Nexum autosave draft to a provider draft row.
- Durable draft attachment persistence.
- Shared draft locks, responder reservations, or typing presence.
- Creating, renaming, or deleting provider folders from Nexum.
- Sent append/deduplication, reconciliation dashboard, automatic replies, or AI write/send actions.

## Data Touched

- Existing `email_folders` and `email_mailbox_placements`.
- Existing `provider_draft` placement flag.
- Mail workspace Livewire query/view state and sidebar counts.

No new database table is required.

## Permissions

Provider Drafts visibility uses existing mailbox View authorization. Provider draft editing is not
available yet. Provider-state actions still require Organize access, but Drafts-specific editing and
write behavior remain intentionally unavailable in this slice.

## Tests

- StoreInboundMessage/placement projection marks Drafts-folder placements as provider drafts and does
  not run inbound automation.
- Mail workspace shows a Drafts view, provider draft badges, and no ordinary Reply/Forward actions
  for provider draft placements even when the user has mailbox Send access.
- Existing Mail folder, draft, send, and inbound automation tests continue to pass.

## Automated Verification

- `php -l app/Modules/Email/Livewire/Tech/MailWorkspace.php`
- `php -l app/Modules/Email/Services/EmailFolderProjector.php`
- `php -l app/Modules/Email/Tests/Feature/EmailModuleTest.php`
- `umask 0002; HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php --filter='store_inbound_message_marks_drafts_folder_placement_as_provider_draft_without_inbound_automation|mail_workspace_shows_provider_drafts_view_and_hides_reply_actions'`
  passed with 2 tests and 15 assertions.
- `umask 0002; HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php`
  passed with 107 tests and 898 assertions.
- `umask 0002; HOME=/tmp php artisan optimize:clear`
- `umask 0002; HOME=/tmp php artisan view:cache`
- `umask 0002; HOME=/tmp php artisan knowledge:sync-docs --module=Email --push`
- `umask 0002; HOME=/tmp php artisan queue:work --once --queue=default --tries=1`
- `umask 0002; HOME=/tmp php artisan queue:failed`
- `git diff --check` passes with only pre-existing CRLF working-copy warnings in unrelated files.
- The later exact post-APPEND refresh passes 4 dedicated tests / 35 assertions plus 4 existing Draft
  regressions / 40 assertions without live provider calls.

## Documentation

- Email README and Knowledge overview must describe provider Drafts visibility as read-only cache
  projection.
- TODO must keep later provider Drafts write sync and provider folder creation/rename/delete as
  separate follow-up slices.
- Human review must track manual verification.

## Done Criteria

- Provider Drafts imports are visibly distinct from ordinary mail.
- Drafts view and provider draft filter only show authorized mailbox placements.
- Draft placements do not trigger Inbox Ticket/rule automation.
- Draft placements do not expose ordinary Reply/Reply All/Forward controls.
- Focused and affected Mail tests pass on Dev.
