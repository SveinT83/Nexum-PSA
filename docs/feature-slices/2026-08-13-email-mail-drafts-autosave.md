# Feature Slice: Email Mail Drafts And Autosave

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Add local Mail composer drafts so technicians do not lose Compose, Reply, Reply All, or Forward
work when closing the composer or switching context.

## User-Visible Behavior

The Mail composer can save a local draft from `/tech/mail`. Drafts are restored when the technician
opens the same composer context again: a new message for the same sender account, or a reply/reply
all/forward for the same mailbox placement.

Autosave runs from the composer while the technician edits, and an explicit Save draft button is
available. Closing the composer keeps the saved draft. Discard draft marks the local draft discarded
and closes the composer. Provider-accepted sending marks the related draft sent even when later Sent
snapshot or reconciliation follow-up needs a warning. An unresolved SMTP transport outcome leaves
the composer open and blocks another call for the same reserved send key.

The 2026-08-15 composer lifecycle hardening keeps both the Visual and HTML editor modes synchronized
with Livewire before autosave, toolbar actions, and Send. The Visual `contenteditable` surface is an
explicit Livewire-ignore boundary so an autosave response cannot replace text that the technician is
actively editing.

This slice is a Nexum local-draft slice only. It does not reconcile provider Drafts folders yet.

## Scope

- Add a Mail-owned local draft table and model.
- Add a draft service that authorizes draft save/restore/discard for the signed-in user.
- Restore active drafts when a matching composer opens.
- Save draft content from composer fields and AI-updated body content.
- Mark drafts as sent after confirmed SMTP acceptance, independently of later Sent
  storage/reconciliation follow-up.
- Add compact composer UI for draft status, Save draft, Close, and Discard draft.

## Out Of Scope

- Provider Drafts folder synchronization.
- Shared draft locks, responder reservations, or typing presence.
- Multiple new-message drafts per sender account.
- Draft attachment persistence. Attachments remain supported for immediate send, but this slice does
  not store uploaded files as durable draft attachments.
- Offline drafts or PWA offline write queues.

## Data Touched

- New `email_composer_drafts` table.
- New `EmailComposerDraft` model.
- New `EmailComposerDraftService`.
- Mail Livewire composer state and UI.

## Permissions

Draft save/restore/discard uses the same account boundaries as send. New Compose drafts require
effective Send access to the sender account. Reply, Reply All, and Forward drafts require effective
View and Send access to the selected mailbox placement's account.

## Tests

- New Compose draft save/restore regression.
- Reply draft save/restore and sent-state regression.
- Discarded draft does not restore regression.
- Untouched Forward close does not create noisy local draft regression.
- Existing Compose/Reply/Reply All/Forward send tests continue to pass.
- Accepted post-SMTP follow-up failures do not restore/reopen the draft, while an unresolved SMTP
  outcome remains open and cannot be resent with the same reservation.

Automated Dev verification completed 2026-08-13:

- Draft-filtered Email regressions pass with 6 tests and 60 assertions, including the four local
  draft/autosave regressions.
- Full `EmailModuleTest.php` passes with 103 tests and 865 assertions.
- PHP syntax checks pass for the Mail workspace Livewire component, draft service, draft model, and
  draft migration.
- `php artisan migrate` applied `2026_08_13_100000_create_email_composer_drafts_table` in batch 80.
- `php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge sync, one default queue
  worker pass, and `php artisan queue:failed` pass.
- `git diff --check` passes with only pre-existing CRLF working-copy warnings in unrelated files.

Composer lifecycle regression verification completed 2026-08-15:

- Removed the unsupported Livewire 2 `@entangle(...).defer` modifier; Livewire 3 deferred
  entanglement plus an explicit local `$wire.$set(..., false)` now synchronizes both editor modes
  before the following autosave or send request.
- Added isolated coverage proving the frontend synchronization contract, body preservation through
  autosave and failed send validation, and semantic rejection of truly empty HTML: 3 tests and 20
  assertions pass.
- The lifecycle regression plus full `EmailModuleTest.php` pass with 144 tests and 1,226 assertions.
- Blade cache compilation, PHP syntax, Pint, and `git diff --check` pass. A browser lifecycle check
  remains in human review `HR-2026-08-13-020`.
- The expanded composer lifecycle regression passes 4 tests / 26 assertions, including the later
  exact-active-draft attachment isolation regression at 1 / 6.

## Documentation

- Email README and Knowledge overview updated.
- TODO active Mail workstream updated.
- Human review tracked in `docs/human-review.md`.

## Done Criteria

- A technician can save and restore a new Compose draft.
- A technician can save and restore Reply, Reply All, and Forward drafts for the same message
  placement.
- Closing a composer does not discard a saved draft.
- Discard draft prevents later restore.
- Sending marks the draft sent and prevents later restore.
- Focused and affected Email tests pass on Dev.
