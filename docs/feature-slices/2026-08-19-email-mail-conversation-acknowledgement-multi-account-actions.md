# Feature Slice: Email Conversation Acknowledgement and Explicit Multi-Account Actions

Status: In Progress
Date: 2026-08-19
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 12)
Review ID: `HR-2026-08-16-012`

## Purpose

This slice implements conversation-level acknowledgement and ensures that actions taken in a multi-account environment are explicit and authorized. It prevents accidental actions on unselected accounts and provides a snapshot-based reauthorization for placements.

## Scope

- **Conversation Acknowledgement:** Users can "acknowledge" an entire conversation, marking all current placements as seen/processed for their baseline.
- **Snapshot Reauthorization:** When an action is taken on a conversation, a snapshot of the current placements is used. Future arrivals do not inherit the action automatically.
- **Multi-Account Clarity:** When a conversation spans multiple accounts, the "active" account remains the default for replies, but other accounts remain independent.
- **Explicit Scoping:** Actions like "Archive" or "Delete" must be clear about which accounts/placements are affected.

## Technical Design

### Acknowledgement
- Track `acknowledged_at` or a specific version in `email_mail_user_baselines` (or similar per-user state).

### Snapshot-based Actions
- The `PerformEmailRemoteOperation` will be updated to accept a list of placement IDs or a version hash to ensure it only acts on what the user saw.

## Implementation Plan

1. **Update `MailWorkspace`:** Add "Acknowledge Conversation" button and logic.
2. **Backend Action:** Create `AcknowledgeEmailConversation` action.
3. **Multi-Account UI:** Improve the placement selector/indicator in the reader view to clarify which accounts are involved.
4. **Verification:** Test that acknowledging a conversation doesn't affect future incoming messages in the same thread.

## Boundary & Risks

- **Boundary:** Focused on user-level processing state; provider-level `Seen` flag reconciliation is separate.
- **Risk:** Confusion if a user acknowledges a conversation while new mail arrives.
- **Mitigation:** Use the version hash/snapshot pattern to bound the acknowledgement.
