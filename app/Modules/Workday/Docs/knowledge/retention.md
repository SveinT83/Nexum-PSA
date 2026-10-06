Workday and simple absence records have a three-year retention period. Their history,
saved previews and retry receipts share that deadline. Corrections and snoozing do not restart it.

## Deadline

The deadline is midnight after the third anniversary of the work date, or the last included local
date of the original absence period, in its original timezone. Leap-day anniversaries use
28 February. Expired records stop appearing in the application at the deadline.

## Inspect cleanup

With workday.manage_settings, open **Workday settings > Preview expired records**.
The preview shows counts, policy and whether cleanup is enabled. It reveals no employee
identities, dates, work descriptions or absence categories and does not delete anything.

The API provides the same preview at POST /api/v1/workday-settings/retention-preview,
with workdays.settings scope and the explicit settings permission. It accepts no input.
There is no remote purge action.

## What remains

Cleanup removes Workday-owned history, all reminder generations and linked notification copies.
Calendar removes only the neutral event owned by an expired absence. Original Tasks,
Ticket time, independently created Calendar events, current work plans and profile preferences
remain under their own domain policies. Tripletex synchronization is future work.

## Operations

Cleanup has a separate server activation switch and continues independently of employee
workflow enablement after approval. It is default-off during development.
Operators use bounded command batches and a closed restore check before reopening recovered
data. See the Workday retention and restore runbook for copy inventory and backup requirements.

A preview with zero counts does not certify external backups, old log archives or delivered
email/device copies. Those remain part of the installation's release and restore checks.
