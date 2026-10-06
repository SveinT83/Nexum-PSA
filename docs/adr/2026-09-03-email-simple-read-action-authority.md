# ADR: Simple Selected-Message Read Action Authority

Status: Accepted
Date: 2026-09-03
Decision Makers: Svein

## Context

The accepted mailbox-access ADR separated each user's Nexum `unread for me` state from provider
Seen state. The ordinary Mail reader later exposed that technical separation as several controls,
including a conversation preview and separate server-read choices. That was confusing for normal
mail use and made marking one message read feel like a migration workflow.

The product needs one familiar action while preserving correct authority for shared mailboxes.

## Decision

The ordinary selected-message reader exposes one direct `Mark as read` or `Mark as unread` action.

The action always updates only the signed-in user's Nexum personal state. In shared and system
mailboxes it does not change provider Seen, so another technician remains unread until that person
acts. When the account is personal and the signed-in user is its owner, the same action also mirrors
Seen/Unseen to the provider through the existing idempotent remote-operation ledger. A delegate's
action remains personal and local.

Opening mail remains non-mutating. The ordinary reader does not expose conversation acknowledgement
preview/apply, migration wording, or a separate provider Seen choice. Advanced conversation-wide and
multi-account acknowledgement remains default-off API/administrative infrastructure.

This ADR supersedes only the ordinary selected-message read-action presentation and automatic
provider-mirroring portion of `2026-08-11-email-mailbox-access-and-rule-authority.md`. Its access,
permission, privacy, rule, and provider-authority decisions remain accepted.

## Rationale

One button matches a normal email client. The system can choose the correct authority from mailbox
ownership without asking users to understand internal state models. Shared mail still needs
per-technician awareness, while a personal mailbox owner reasonably expects server state to follow.

## Consequences

- Shared/system read actions affect only the actor; other users and provider Seen are unchanged.
- Personal owners get Nexum and provider state from the same visible action.
- Provider failures do not roll back a successful personal Nexum change and are reported honestly.
- Advanced bulk/multi-account acknowledgement remains available only behind its separate gate.
- Existing provider operation audit, reconciliation, retry, and permission boundaries are reused.

## Alternatives Considered

- Keep separate `for me` and `mail server` controls: rejected because it exposes internal mechanics.
- Always change provider Seen: rejected because one shared flag cannot represent several users.
- Never change provider Seen: rejected because personal mailbox owners expect normal client behavior.
- Remove the advanced backend immediately: rejected because its guarded API and evidence are useful
  for future administrative bulk work and remain safely default-off.

## Follow-Up

- Keep feature regression coverage for shared two-user isolation and personal-owner provider mirroring.
- Complete the simple reader review under `HR-2026-09-03-002`; the advanced API keeps its separate `HR-2026-08-16-012` gate.
- Do not restore preview or provider Seen controls to the ordinary reader without a new accepted decision.
