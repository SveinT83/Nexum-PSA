# Feature Slice: Email Compose, Draft, Send, and Sent API Parity

Status: Rework Needed
Date: 2026-08-19
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 11)
Review ID: `HR-2026-08-16-011`

2026-08-21 audit: the Reply/Reply All/Forward undefined-placement regression is repaired with a
focused test that removes the quarantined lock table and proves all three ordinary private composer
flows still send through their selected mailbox placement. Collaboration remains independently
disabled. Shared lookup currently erases the private-draft ownership boundary; durable fencing,
explicit shared scope and complete API/Sent parity remain unverified.

## Purpose

This slice ensures that the Email composition, drafting, and sending process is reliable, idempotent, and correctly reconciles with provider-side Sent folders. It also implements shared drafts across the team.

## Scope

- **Idempotency:** Preventing duplicate sends via unique `Message-ID` or idempotency keys.
- **Ambiguous-Send Reconciliation:** Handling cases where a message is sent on the provider but not yet recorded locally (or vice versa).
- **Shared Drafts:** Synchronization of drafts between users working on the same conversation.
- **Sent API Parity:** Ensuring that Sent messages are correctly projected into the `Sent` folder and correlated with conversations.
- **Attachment & Signature Parity:** Reliable handling of attachments and user signatures during send.
- **Blind Retry Prevention:** No automatic retries for failed sends unless success can be verified.

## Technical Design

### Idempotency
- Generate a client-side `Message-ID` or custom header during the draft phase.
- Verify against the provider's Sent folder before retrying a "failed" send.

### Shared Drafts
- Drafts are stored in `email_composer_drafts` and linked to `conversation_id`.
- The `EmailPresenceService` (from Slice 9) protects against concurrent edits, but this slice ensures the *content* is synced.

### Sent Reconciliation
- The `provider_sent_message_id` is recorded after a successful send.
- Reconciliation logic matches local Sent records with provider-originated Sent messages.

## Implementation Plan

1. **Update `SendEmailComposerMessage` Action:** Add idempotency and Sent reconciliation.
2. **Update `MailComposer` Livewire:** Support shared draft loading and content sync.
3. **Sent Folder Projection:** Ensure outbound messages hit the Sent projection immediately.
4. **Verification:** Test concurrent draft editing and duplicate send protection.

## Boundary & Risks

- **Boundary:** Focused on the *act* of sending and drafting; Ticket correlation is Slice 13-16.
- **Risk:** Providers that don't allow setting `Message-ID` or don't return the ID after send.
- **Mitigation:** Use secondary metadata (Subject, Recipients, Timestamp) for fuzzy matching in reconciliation.
