# Feature Slice: Email Mail Verified Remote Operation Undo

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Depends on: `2026-08-14-email-mail-remote-operation-recovery.md`
Owner: Svein / Codex

## Goal

Let a user explicitly reverse a recently acknowledged provider mailbox action only when the exact
inverse is supported and current provider/local evidence still matches the original result.

## User-Visible Behavior

Recent successful Seen/Unseen, Flag/Unflag, Archive/Trash/Move operations show Undo only while the
operation is verifiably reversible. If the message moved or changed again, access was revoked, the
target placement is ambiguous, or the provider state cannot be confirmed, Undo is unavailable with
an honest reason. Permanent delete and folder mutation never offer Undo.

## Scope

- Add inverse-operation linkage and immutable source/target placement/version/folder/flag snapshots.
- Define exact inverse pairs for Seen, Unseen, Flag, Unflag, and acknowledged moves.
- Recheck user status, Mailbox Organize, current placement identity/version, and provider state.
- Record the inverse through the normal remote-operation ledger and attempt/recovery contract.
- Make double submission idempotent and link original/inverse operations both ways.
- Add recent-success UI plus hidden-404 scoped API eligibility/apply endpoints.

## Implemented Contract

- A provider-acknowledged result gets one immutable metadata-only pre/post snapshot. Existing
  historical successes are not backfilled because their exact result identity cannot be proven.
- The verified Undo window is 15 minutes from result capture. Once an inverse is created inside the
  window, normal bounded recovery may finish it later without creating another inverse.
- `inverse_of_email_remote_operation_id` is unique and immutable. The source exposes its inverse via
  the reciprocal model relation, and repeat submissions return that same ledger row.
- Seen/Unseen and Flag/Unflag inverses require the same active placement, sync version, folder, UID,
  UIDVALIDITY, and complete provider flag state recorded by the result.
- Archive, Trash, and Move invert to Move only when the provider acknowledged an exact target UID,
  that target placement is still active and unchanged, the original source placement remains hidden,
  and the original folder remains selectable in the same UID namespace.
- Current account activation and actor Mailbox Organize access, source outcome, immutable local
  evidence, later operations, and provider state are checked again before every inverse write.
- Provider verification is part of the ordinary remote-operation attempt. Explicit mismatch
  supersedes the inverse without a provider write; connection uncertainty enters ordinary retry or
  ambiguity reconciliation, which never blindly replays a write.
- `/tech/mail` includes recent acknowledged operations alongside active recovery work, showing Undo
  only when locally eligible and always showing the current reason. Provider state is verified again
  after the user clicks.
- API clients use hidden-404 account scope through
  `GET /api/v1/email/mailbox/remote-operations/{operation}/undo` (`email.read`) and the matching
  `POST` (`email.update`).

## Out Of Scope

- Permanent message delete, folder create/rename/move/delete, ambiguous moves, bulk undo, automatic
  undo, or restoring data already removed by retention.

## Tests

- Exact inverse success for flags and moves.
- Double submit returns the existing inverse.
- Later mutation, stale version, missing target, ambiguity, or revoked access blocks provider calls.
- Non-undoable operation types never expose an action.
- Account isolation, attempt linkage, aggregate refresh, and partial-local-failure recovery.

## Done Criteria

- [x] Undo is offered only when the inverse is exact and current state is unchanged.
- [x] Every inverse uses the normal ledger, attempt audit, authorization, and reconciliation path.
- [x] Original/inverse linkage and idempotency survive retries.
- [x] Focused remote-operation UI/API tests pass on Dev.

Human review remains open under `HR-2026-08-14-011`.

Dev migration `2026_08_15_113000_add_verified_email_remote_operation_undo.php` ran in batch 91,
before the 114000 Smart Inbox migration in batch 92 and 115000 provider-deletion migration in batch
93.
