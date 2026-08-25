# Feature Slice: Email Conversation Acknowledgement And Explicit Multi-Account Actions

Status: Safety Rework Implemented / Human Review Pending; Activation Gated
Date: 2026-08-19
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 12)
Approved contract: `2026-08-16-email-mail-conversation-acknowledgement-multi-account-actions.md`
Review ID: `HR-2026-08-16-012`

## Audit And Repair Outcome

The 2026-08-21 audit found that the historical `150000` scaffold did not freeze or reauthorize exact
mailbox placements, mixed personal acknowledgement with provider state, called a relationship that
does not exist, and had no focused completion test. That migration remains an inert deploy marker
and still creates no `email_mail_user_conversation_acknowledgements` table.

The approved 2026-08-24 safety rework adds the new forward migration
`2026_08_24_140000_create_email_conversation_acknowledgement_action_ledger.php`. It creates the
general `email_conversation_action_runs` and `email_conversation_action_items` ledgers from the
accepted slice without altering the inert historical migration. It later ran in Dev batch 128; both
ledgers remain empty and `EMAIL_MAIL_ACKNOWLEDGEMENT_ENABLED=false`.

## Implemented Safety Boundary

- `PreviewEmailConversationAcknowledgement` accepts either one exact active account/conversation or
  an explicit list of selected placement IDs. It never expands scope through subject, Message-ID,
  Ticket linkage, canonical correlation or another account.
- Preview checks current ordinary View for every account and Organize separately when provider Seen
  is requested. Break-glass and system actors cannot create personal acknowledgement authority.
- Each item freezes account, conversation, message, folder and placement IDs; UID namespace,
  UIDVALIDITY and UID; current access epoch; provider-binding version; placement sync version;
  personal/provider before values; targets; and immutable source/item fingerprints. Preview changes
  no personal state and creates no provider operation.
- Active-account previews fetch the configured cap plus one in placement-ID order. Explicit
  multi-account previews accept at most 20 accounts. Defaults are 100 items, a hard maximum of 500,
  and a 15-minute expiry.
- `ApplyEmailConversationAcknowledgement` requires the exact previewing actor, verifies the run and
  item fingerprints, claims at most 25 items per invocation and rechecks each current account,
  conversation, message, folder, placement, epoch, UID identity, sync version and provider binding.
  Revoked, changed or missing evidence fails closed without substituting another member.
- Personal state is applied only through `SetEmailUnreadForMe` for the actor's frozen current epoch.
  Other users, access baselines, opened receipts, future arrivals and unselected placements remain
  unchanged.
- If the same EmailMessage has several selected active placements, preview freezes exactly one
  personal effect per account/message/access-epoch/target. The lowest placement-ID item is selected;
  later items are immutable non-selected `coalesced` evidence. Selection is part of the item
  fingerprint, while optional provider Seen remains selected and reserved for every exact placement.
- Optional provider Seen is a separate Organize-authorized result. Apply only reserves one exact,
  idempotent `mark_seen` row through `RecordEmailRemoteOperation`; it never resolves an IMAP client
  or reports provider success before the existing remote-operation ledger acknowledges it.
- Personal success remains committed if provider reservation is denied, stale, conflicted or fails.
  Item/run evidence reports the two statuses independently and retains only safe reason codes.
  Redelivery reuses the linked remote operation and cannot repeat personal state or create another
  provider operation.
- The historical `AcknowledgeEmailConversation` implicit mutation now rejects calls and points
  callers to preview/apply. The currently callable Livewire method never applies a conversation;
  even after schema installation it reports that explicit preview/confirmation is required.

## Dependency Boundary

This narrow repair does not require unsafe Order 8 or Order 9 runtime activation. With Order 8 off,
the existing `EmailLiveInvalidator` is an explicit no-op after the personal-state transaction. The
provider half reserves only the established remote-operation ledger and performs no provider I/O.
Broad conversation Archive/Move/Trash actions, shared-draft retarget protection, public Livewire/API
preview/confirmation, queue continuation, retry/cancel UI and private invalidation delivery remain
under the full approved slice and its Order 8/9 dependencies.

No acknowledgement control should be exposed and the environment flag must remain false until those
surfaces, deployment operations and `HR-2026-08-16-012` pass. The selected-message read/provider
actions remain the existing fast default and were not widened.

## Verification

The focused test `EmailConversationAcknowledgementSafetyTest` uses isolated SQLite `:memory:` and
covers:

- default-off/schema and strict non-empty-ledger rollback behavior;
- preview read-only behavior, exact active-account membership and a future arrival remaining unread;
- exact two-account selection, unselected members and inaccessible-account rejection;
- same-actor, exact placement/sync fingerprint and provider/personal action-time reauthorization;
- personal success with sanitized provider reservation failure;
- Organize revocation, separate pending/succeeded provider evidence, no IMAP resolution and
  redelivery without duplicate operations; and
- break-glass denial plus fail-closed rejection of the historical implicit action.
- canonical multi-placement coalescing, one personal-state write, provider reservation per
  placement, terminal success without false stale/partial, and unmasked selected-personal failure.

The focused test passes 10 tests / 110 assertions. The combined focused plus historical-quarantine,
unread-epoch and remote-operation recovery package passes 60 / 522; the isolated authorized Mail
workspace render smoke adds 1 / 11 (61 / 533 total recorded handoff coverage). The opt-in migration
contract passes 1 / 52 on an actual socket-only MariaDB 10.11.14 instance and random disposable
schema. It proves `140000` up, named indexes and foreign keys, default-off configuration and schema
defaults, empty down, non-empty evidence refusal, and exact `pending` / `coalesced` status plus
selected/non-selected round-trip. The shared Order 12/13 contract cleanup reported zero matching
schemas before stopping the daemon and removing its datadir. Automated tests do not complete the
named human review.

## Deployment And Review

Do not edit or rerun inert `2026_08_19_150000`. Review `140000` on a disposable current-schema copy,
including an empty rollback and a refusal after preview evidence exists. Normal deployment then uses
the ordinary migration process with `umask 0002`, cache clear and group-writable view rebuild. The
migration creates no preview, personal state, queue job, provider operation or external call by
itself.

`HR-2026-08-16-012` remains open for a named human to verify the frozen placement list, future and
unselected mail, revoked access/epoch/provider evidence, truthful partial results, evidence privacy,
worker/provider reconciliation and the eventual accessible preview/confirmation interface before
activation.
