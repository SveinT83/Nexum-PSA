# Feature Slice: Workday And Tripletex Functional Pilot

Status: Done On Dev for the bounded own-employee pilot; broader rollout checks remain open.
Parent: [approved RFC](../rfc/2026-10-05-workday-tripletex-automatic-time-sync.md).
Evidence: [verification](../plans/2026-10-05-tripletex-time-sync-verification.md).
Human review: HR-2026-10-05-WORKDAY-TRIPLETEX, In Review; blocks Main/production promotion.

This slice completes the functional vertical path across the previously planned slices:
explicit mapping, effective Save, duration-only edits, durable synchronization in both directions,
deletion, settings switch and a scheduled runtime. It does not claim the entire four-slice
rollout plan is finished.

Workday owns effective time and immutable history. Integration owns credentials, provider
transport, mapping setup and the operator switch. DataExchange owns baselines, durable write
intent, reconciliation and recovery. Report/Notification use effective recorded time.
The migration is additive and leaves legacy drafts private. No billing or payroll approval
operations are introduced.
