# Feature Slice: Email Provider Mailbox Actions And API

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-12-007`

## Goal

Finish the first safe provider-mutation slice for the `/tech/mail` workspace: explicit provider
read/unread, flag/unflag, archive, and normal trash actions with mailbox authorization, IMAP
acknowledgement, remote-operation ledger records, and API parity.

## User-Visible Behavior

Technicians with `email.inbox_view`, `email.inbox_manage`, and an account Organize grant can run
provider mailbox actions from the selected message in `/tech/mail`.

The reading pane now exposes:

- `Mailbox read` / `Mailbox unread` status for the selected provider placement;
- `Mark read in mailbox` / `Mark unread in mailbox` actions;
- `Flag` / `Unflag`;
- `Archive` when the account has a selectable provider Archive folder;
- `Move to Trash` when the account has a selectable mailbox Trash folder.

Personal `Mark read for me` / `Mark unread for me` remains separate and does not mutate IMAP. The
mailbox controls mutate the shared provider mailbox state and leave other users' personal
`Unread for me` state unchanged.

Archive and trash use provider `MOVE`. When the provider returns target UID evidence, Nexum creates
the target mailbox placement immediately. When the target UID is unavailable, Nexum hides the source
placement after provider acknowledgement and waits for the next provider reconciliation to project
the target placement.

View-only users do not see provider mutation controls. The API returns forbidden for users without
an effective Organize grant.

## Scope

- Add provider `Seen`, `Flagged`, and `MOVE` methods to the IMAP client.
- Add a shared `PerformEmailRemoteOperation` action for authorization, idempotency, target-folder
  validation, ledger creation, and synchronous provider acknowledgement.
- Add a shared `RunEmailRemoteOperation` action that executes supported provider operations and
  updates mailbox placement projection state after acknowledgement.
- Add `/api/v1/email/mailbox/placements/{placement}/operations` for the same provider operations.
- Update `/tech/mail` to expose only supported provider controls for the selected placement.

## Out Of Scope

- Permanent provider delete.
- Arbitrary move/copy to a user-selected custom folder.
- Bulk actions and whole-conversation provider actions.
- Retry/cancel/reconcile UI for failed remote operations.
- Composer, drafts, sent reconciliation, attachment downloads, and automatic replies.

## Data Touched

- `email_remote_operations`
- `email_mailbox_placements`
- `email_folders` read-only target-folder lookup

No new migration is required.

## Tests

- Mailbox read/flag actions call IMAP, update the placement projection, and write succeeded remote
  operation ledger records.
- Archive calls provider move, hides the source placement, and creates a target placement when the
  provider returns a target UID.
- API provider operation runs with an authorized mailbox grant and returns the remote-operation
  result.
- API provider operation is forbidden for a view-only mailbox grant.
- Existing Email Inbox, Mail workspace, polling, folder placement, rule, Ticket routing, and inbound
  automation tests still pass.

## Done Criteria

- [x] Mailbox controls are visible only for users with effective mailbox Organize access.
- [x] Archive/Trash controls are visible only when a provider-discovered target folder exists.
- [x] UI does not report success before provider acknowledgement.
- [x] Remote operations are idempotently recorded and retain provider response/failure evidence.
- [x] Provider `Seen` remains separate from `Unread for me`.
- [x] API and UI use the same server-side authorization and operation action.
- [x] No permanent delete or unsupported custom-folder controls are exposed.
