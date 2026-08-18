# Feature Slice: Email Mail Remote Operation Retry Dashboard

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Expose failed and pending provider mailbox operations to organize-authorized technicians so they can
retry or cancel them without database access.

## User-Visible Behavior

When the signed-in technician has Organize access to mailboxes with pending, running, failed, or
recently acknowledged remote operations, `/tech/mail` shows a compact `Mailbox operations` card in
the right bar. The card starts collapsed, keeps status counts visible in its header, and expands to
the existing detailed operation, retry, cancel, evidence, and verified Undo controls. Failed and
pending rows can be retried through the existing provider operation runner or cancelled to remove
them from the active work queue.

Succeeded, cancelled, and superseded operations are not offered for retry.
Ambiguous Archive/Trash/Move rows also omit Retry when their immutable target folder path or target
UID is missing, because repeating their read-only reconciliation cannot prove a safe provider write.

## Scope

- Add a Mail workspace dashboard for active remote operation work and recent verified results.
- Render that stateful Livewire surface in the right bar and default it to collapsed without moving
  operation authorization or action logic into the page shell.
- Add retry action using `RunEmailRemoteOperation`.
- Add cancel/dismiss action for pending or failed operations.
- Keep account authorization scoped through mailbox Organize access.

## Out Of Scope

- Automatic retry scheduler.
- Bulk retry/cancel.
- Provider folder create retry ledger.
- Remote folder rename/delete.

## Data Touched

- Existing `email_remote_operations`.
- Existing source and target `email_mailbox_placements` when retry succeeds.

## Permissions

Remote operation dashboards and actions require effective mailbox Organize access for the operation
account.

## Tests

- A failed move operation appears in the Mailbox operations dashboard.
- Retry runs the same provider move path, marks the source placement hidden, projects the target
  placement, and marks the operation succeeded.
- The dashboard is absent when there is no active/recent work and otherwise renders in the right bar
  with a collapsed disclosure, compact counts, correct ARIA state, and the same working controls after
  expansion.
- An ambiguous move-like row without immutable target path/UID evidence remains visible for review
  but does not expose manual Retry.

## Automated Verification

- Focused Mail regressions including this slice passed on Dev with 6 tests and 50 assertions.
- Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Dev migration, cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, no failed jobs, route registration, and git diff checks were also completed.
- The 2026-08-15 right-bar refinement passes 2 focused tests / 64 assertions and one adjacent verified
  Undo regression / 7 assertions. It adds no migration, permission, scheduler, or frontend-build
  change.

## Done Criteria

- Failed/pending provider operations are visible to authorized users.
- The card appears in the right bar, starts collapsed, and exposes understandable status counts before
  expansion.
- Retry and cancel are denied outside the authorized mailbox account.
- Retry reuses the existing provider mutation service instead of duplicating IMAP logic.
