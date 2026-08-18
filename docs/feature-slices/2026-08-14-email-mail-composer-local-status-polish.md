# Feature Slice: Mail Composer Local Status Polish

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Keep composer-specific feedback inside the open Mail composer so AI and draft messages do not pull
attention to the workspace-level alert area.

## User-Visible Behavior

`/tech/mail` now shows AI apply results, no-reply advice, Mail AI availability warnings, manual
draft save/restore/provider Drafts sync status, and draft attachment removal status as a compact
inline message inside the shared composer. The global Mail alert remains for non-composer actions
and for send/discard completion after the composer closes.

## Scope

- Add a composer-local Livewire status surface for `MailWorkspace`.
- Render that status in the shared rich HTML composer partial with Bootstrap alert styling and
  `aria-live`.
- Route composer AI success/info/warning/error states to the local composer status.
- Route open-composer draft save, restore, provider Drafts sync, and draft attachment status to the
  local composer status.
- Keep send success/failure, discard completion, provider folder actions, mailbox operations, Ticket
  actions, and other non-composer actions on the existing page-level Mail status.
- Extend Email feature tests so composer-local status is asserted and page-level status stays clear
  for AI/draft composer actions.

## Out Of Scope

- Changing AI prompt behavior, provider/model governance, agent selection, or data-egress policy.
- Changing SMTP send, IMAP polling, provider Drafts semantics, provider Sent reconciliation, or
  mailbox operation execution.
- Adding automatic send, AI write tools, shared draft locking, or a reusable cross-module editor.
- Replacing the existing composer layout or global Mail alert for unrelated actions.

## Data Touched

No database schema, settings, provider accounts, queues, or external systems are changed. The slice
touches `MailWorkspace`, the Mail workspace Blade/CSS, the shared composer Blade partial, Email
feature tests, Email Knowledge docs, module README docs, TODO, and human-review tracking.

## Permissions

No permissions, grants, middleware, policies, routes, or API abilities change. The same Mail AI,
mailbox View/Send/Organize, and draft authorization checks continue to decide whether an action may
run.

## Tests

- PHP syntax checks for the Livewire component, Mail workspace Blade view, shared composer partial,
  and Email feature test file.
- Focused Livewire regressions for local draft save/restore/provider sync, durable draft attachment
  save, composer AI draft/no-reply/rewrite/compose/forward, and AI governance unavailable status.
- Full `EmailModuleTest.php`.
- Cache clear, Blade cache, Email Knowledge sync, one default queue worker pass, failed-job check,
  and `git diff --check`.

## Documentation

- Update Email Knowledge to describe composer-local AI/draft status.
- Update the Email module README to describe the composer-local AI status boundary.
- Update `docs/TODO.md`.
- Add `HR-2026-08-14-005` in `docs/human-review.md`.

## Done Criteria

- AI draft/rewrite success appears inside the composer and leaves `mailActionStatus` empty while the
  composer stays open.
- AI no-reply advice appears inside the composer without replacing the body.
- AI unavailable/governance messages triggered from an open composer appear inside the composer.
- Manual draft save/restore/provider Drafts sync messages appear inside the composer.
- Send/discard completion remains page-level feedback after the composer closes.
- Automated Email tests pass and the manual review entry is pending for browser QA.

## Automated Verification

- `php -l app/Modules/Email/Livewire/Tech/MailWorkspace.php`
- `php -l app/Modules/Email/Views/Livewire/Tech/mail-workspace.blade.php`
- `php -l app/Modules/Email/Views/Livewire/Tech/partials/mail-composer-form.blade.php`
- `php -l app/Modules/Email/Tests/Feature/EmailModuleTest.php`
- `HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php --filter='mail_workspace_(saves_and_restores_new_compose_draft|provider_drafts_manual_save_syncs_to_provider_drafts|persists_restores_sends_and_cleans_durable_draft_attachment|ai_can_draft_reply_without_sending_or_changing_recipients|ai_no_reply_advice_does_not_replace_composer_body|ai_can_draft_reply_with_action_capable_default_email_agent|ai_can_rewrite_existing_reply_with_user_instruction|ai_can_improve_new_compose_without_selected_message|ai_can_rewrite_forward_intro_without_losing_forwarded_message)'` passed with 8 tests and 113 assertions.
- `HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php --filter='mail_ai_controls_are_hidden_when_selected_agent_model_governance_is_missing'` passed with 1 test and 6 assertions.
- `HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php` passed with 138 tests and 1163 assertions.
- `umask 0002; HOME=/tmp php artisan optimize:clear` passed.
- `umask 0002; HOME=/tmp php artisan view:cache` passed.
- `umask 0002; HOME=/tmp php artisan knowledge:sync-docs --module=Email --push` updated 1 chapter, updated 1 article, and queued the BookStack push.
- `HOME=/tmp php artisan queue:work --once --queue=default --tries=1` passed.
- `HOME=/tmp php artisan queue:failed` reported no failed jobs.
- `git diff --check` reported only pre-existing CRLF working-copy warnings in unrelated files.
