# Feature Slice: Email Mail Signatures

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Add Mail-owned personal technician signatures that are managed from the profile workspace and applied
by the Mail send pipeline without letting AI or the composer mutate the signature block.

## User-Visible Behavior

Each technician has one default Mail signature. The signature can be edited from `/tech/profile`, and
the Mail right bar keeps the page AI chat first, then the conditional Mailbox operations card, then a
compact Mail signature card that starts collapsed. Expanding it reveals the settings trigger, which
opens a viewport-bound Bootstrap dialog with an explicit X, Cancel, and Save for the per-mode
toggles. The Mail AI runtime status remains a separate collapsed card below the Mail-specific
signature controls. The technician can choose whether the signature is included for new Compose,
Reply, Reply All, and Forward messages.

When a covered message is sent from `/tech/mail`, Mail appends the rendered signature immediately
before SMTP. The composer remains focused on message content, and Mail AI draft/rewrite controls do
not receive or rewrite the signature. The outgoing HTML and plain-text message include the signature
once.

## Scope

- Add an Email-owned personal signature table and model.
- Add a signature renderer/updater that supports safe HTML and default tokens.
- Add Mail-owned profile and default-collapsed right-bar signature editing/status partials. Expanding
  the card reveals a proper responsive dialog trigger so the modal does not fall behind or below the
  page footer.
- Add an authenticated Email route for updating the signed-in user's Mail signature.
- Append the signature in `SendEmailComposerMessage` for the selected send mode before SMTP.
- Preserve idempotency and prevent duplicate signature blocks on retry or pre-marked HTML.

## Out Of Scope

- Multiple named signatures per user.
- Per-mailbox/account signature overrides.
- Shared mailbox/team signatures.
- Marketing, Ticket workflow, Sales, invite, or notification email signatures outside the Mail
  composer path.
- Signature image upload. The default `{company.logo}` token uses the configured company branding
  logo.
- Automatic Sent-folder reconciliation.

## Data Touched

- New `email_signatures` table.
- New `EmailSignature` model.
- Mail composer outbound HTML/text before SMTP.
- Profile and Mail Blade views.

## Permissions

Only the authenticated signed-in user can edit their own Mail signature through the profile/Mail
route. Sending still requires the existing mailbox Send and, for replies/forwards, View access.

## Tests

- Profile UI test for viewing and saving the Mail-owned signature settings.
- Mail composer send regression for appending the rendered signature to outbound HTML/text.
- Mail composer regression that mode toggles can disable signature insertion for Forward.
- Existing Reply/Reply All/Forward/Compose send tests must still pass with signature-aware output.

Automated Dev verification completed 2026-08-13:

- Signature-focused Email regressions pass with 2 tests and 33 assertions.
- Affected Reply/Reply All/new Compose/Forward/idempotency regressions pass with 6 tests and 115
  assertions.
- Full `EmailModuleTest.php` passes with 103 tests and 865 assertions after the right-bar order
  regression was added.
- `UserPreferencesTest.php` passes with 6 tests and 42 assertions.
- PHP syntax checks pass for the signature model, renderer, controller, migration, send action,
  Email feature test, and route-permission middleware.
- `php artisan migrate` applied `2026_08_13_090000_create_email_signatures_table` in batch 79.
- `php artisan optimize:clear`, `php artisan view:cache`, Email Knowledge sync, one default queue
  worker pass, and `php artisan queue:failed` pass.
- `git diff --check` passes with only pre-existing CRLF working-copy warnings in unrelated files.
- The later Mail rightbar ordering correction passes the targeted Mail signature rightbar regression
  with 1 test and 31 assertions, the targeted Integration rightbar AI chat regression with 1 test and
  9 assertions, `php artisan view:cache`, Email Knowledge sync, one default queue worker pass, and
  `php artisan queue:failed`.
- The 2026-08-15 dialog polish passes the targeted signature/profile/rightbar regression with 1 test
  and 44 assertions plus Pint, PHP syntax, Blade cache, diff, and compiled-view permission checks.
- The later 2026-08-15 right-bar disclosure refinement keeps the signature card collapsed by default
  and preserves the existing modal X/Cancel/Save and footer-layering regression contract.
- The final targeted Compose/Reply/Reply All/Forward/idempotency run passes 7 tests / 131 assertions.

## Documentation

- Email README and Knowledge overview updated.
- TODO active Mail workstream updated.
- Human review tracked in `docs/human-review.md`.

## Done Criteria

- A technician can edit a personal Mail signature from `/tech/profile`.
- `/tech/mail` right bar shows the page AI chat first, an optional collapsed Mailbox operations card,
  then a default-collapsed signature card whose expanded trigger opens a responsive dialog with
  X/Cancel above the footer, then the collapsed Mail AI runtime status card.
- Compose/Reply/Reply All/Forward settings determine whether signature is appended.
- Signatures are appended in the send pipeline, not inside AI drafting.
- The outgoing body includes at most one signature block.
- Focused and affected Email/User profile tests pass on Dev.
