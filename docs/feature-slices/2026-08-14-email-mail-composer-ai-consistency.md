# Feature Slice: Email Mail Composer AI Consistency

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Make the shared `/tech/mail` composer expose Mail AI text assistance consistently across Compose,
Reply, Reply All, and Forward without broadening send, view, or write authority.

## User-Visible Behavior

When the selected/default Email agent is ready under Integration policy, the shared composer shows
the same rewrite tools in every eligible mode: improve text, shorten, warmer tone, and Norwegian
rewrite. Reply and Reply All also show Draft reply. Compose does not show Draft reply because it has
no selected source message. Forward does not show Draft reply and preserves the original forwarded
message block when AI rewrites the technician's introduction.

## Scope

- Extend `AssistEmailComposerWithAi` so Compose can use account-scoped AI rewrite with Send access
  only.
- Extend selected-placement AI composer assist to support Forward rewrite in addition to Reply and
  Reply All.
- Keep Draft reply limited to Reply and Reply All.
- Preserve the forwarded-message marker and original forwarded block when AI rewrites a Forward
  introduction.
- Keep To, Cc, Subject, attachments, idempotency key, provider state, Tickets, Tasks, rules,
  categories, and tags unchanged after AI applies text.
- Keep one shared composer Blade partial for Compose, Reply, Reply All, Forward, and provider draft
  editing.

## Out Of Scope

- Automatic sending.
- AI-generated recipients, subject changes, attachments, Ticket updates, Task creation, rules, or
  provider mutations.
- AI assistance for imported provider draft placements.
- A new shared content-editor platform.
- Replacing the current HTML editor implementation.

## Data Touched

- Existing Email account, mailbox placement, message, conversation context, and composer draft data
  can be read according to the active composer mode.
- Composer draft rows may be updated by the existing autosave path after AI applies text.
- No migrations, settings, provider operations, outbound Email logs, Tickets, Tasks, rules, or
  Taxonomy rows are created by this slice.

## Permissions

Compose AI rewrite requires the selected sender account to be send-authorized for the user and does
not require mailbox View. Reply, Reply All, and Forward AI require the selected placement to be
viewable and send-authorized. All AI requests still pass through Integration-governed Email agent
runtime availability. Draft reply is denied outside Reply and Reply All.

## Tests

- Compose can use AI improve from a send-authorized account without a selected source message,
  without showing Draft reply, without changing recipients/subject, and without sending mail.
- Forward can use AI tone rewrite without showing Draft reply, preserves the forwarded-message
  marker and original forwarded content, leaves To empty, and does not send mail.
- Existing Reply AI drafting and rewrite regressions still pass.

## Automated Verification

- `php -l app/Modules/Email/Actions/AssistEmailComposerWithAi.php`
- `php -l app/Modules/Email/Livewire/Tech/MailWorkspace.php`
- `php -l app/Modules/Email/Views/Livewire/Tech/partials/mail-composer-form.blade.php`
- `php -l app/Modules/Email/Tests/Feature/EmailModuleTest.php`
- Focused Compose/Forward AI regressions passed with 2 tests and 22 assertions.
- Existing Reply AI regressions passed with 4 tests and 37 assertions.
- Full `EmailModuleTest.php` passed with 138 tests and 1142 assertions.
- `php artisan optimize:clear` passed.
- `php artisan view:cache` passed.
- `php artisan knowledge:sync-docs --module=Email --push` processed 1 chapter and 1 article, and
  queued the BookStack push.
- One `php artisan queue:work --once --queue=default --tries=1` pass completed without CLI errors.
- `php artisan queue:failed` reported no failed jobs.
- `git diff --check` reported only pre-existing CRLF working-copy warnings in unrelated files.

## Documentation

- Email Knowledge describes shared composer AI controls and Forward preservation behavior.
- Email README documents the updated `AssistEmailComposerWithAi` contract.
- TODO records this slice as implemented.
- Human review tracks manual verification under `HR-2026-08-14-004`.

## Done Criteria

- Compose, Reply, Reply All, and Forward use the same composer partial and consistent AI rewrite
  controls when policy allows.
- Draft reply appears only for Reply and Reply All.
- Compose AI rewrite works with Send access and no selected message.
- Forward AI rewrite does not remove or rewrite the original forwarded-message block.
- Focused and full Mail tests pass on Dev.
