# Feature Slice 07: Explicit Internal Task Conversion Without Duplicate Time

Status: Done On Dev; employee pilot active, human review Pending (2026-10-03).
Date: 2026-10-01
Owner: Codex; product/reviewer: Svein Tore
Parent: [Workday RFC](../rfc/2026-10-01-daily-workday-confirmation.md)
Delivery contract: [Implementation plan](../plans/2026-10-01-workday-implementation-plan.md)
Human review: [HR-2026-10-01-WORKDAY](../human-review.md) - Pending; complete pilot not yet ready.
Dependencies: Slices 02 and 04; retain the ordered execution checkpoint after Slice 06.

## Goal

Let an employee turn an explicit work block into an internal Task without double entry or billing side effects.

## User-Visible Behavior

The employee previews a block, creates an internal Task with the existing description/time, and sees one linked source allocation after read-back.

## Scope

- Delegate creation to existing Task actions and source-domain guards. Preserve internal Work Context and use the supported non-billable standalone/internal Task path.
- Preview the exact description, target and minutes. Creating a Task requires an explicit command; day confirmation or optional AI suggestions never creates it automatically.
- Use a transaction and idempotency receipt to link an existing eligible source or replace the manual allocation with the new source reference. Reconcile source minutes once.
- For already confirmed days, prepare a changed revision and preserve the prior confirmation; a source change does not silently reconfirm it.
- Expose the same preview/create/read-back through the employee API with Task grants in addition to Workday grants.
- Ticket-linked sources may be linked/read under existing guards. Do not route new Workday time through the Ticket-owned RegisterTaskTimeEntry path that creates billing projections.

## Out Of Scope

Automatic Task completion, customer billing/timebank consumption, creating billable Ticket time from Workday, unsupported cross-database distributed writes and autonomous task creation.

## Data Touched

Existing Task/TaskTimeEntry through their actions, Workday allocation/revision/receipt records. No new Task engine.
Names for new storage/actions/routes are implementation proposals, not claims of existing tables.
Confirm exact migrations and existing state on authoritative Dev before runtime changes.

## Permissions

workday.manage_own and workday-task-conversion.write plus the existing Task create/update and internal context guards. Employee identity is resolved server-side.

## Tests

- Preview/save/retry returns one Task and one source time entry while the Workday total stays unchanged.
- Rollback/concurrency/duplicate-source cases do not leave partial Task/time/Workday state.
- Confirmed revision history remains intact; source permissions and actor spoofing are enforced.
- No TicketTimeEntry, invoice, Commercial consumption or automatic Task completion is produced.
- Existing Task registration/completion tests remain green.
- Run the narrow affected Laravel suites on Dev with synthetic fixtures; no local PHP fallback.
- Inspect authenticated UI/API/HTTP read-back for the implemented behavior; an unauthenticated login
  redirect does not prove the feature works.

## Documentation

Document explicit conversion and its internal non-billable boundary in Workday/Task API and Knowledge.
Update this slice and the parent TODO row in the same session as verification or a concrete blocker.

## Done Criteria

UI/API conversion passes transactional tests and concrete Task/time/Workday read-back with no billing side effects.
Record changed files, exact tests/results, migrations/commands, HTTP/UI/API evidence and remaining
human checks. Passing automated tests never marks human review complete. Keep production runtime
off until the required review and separate rollout approval. Do not leave visible stubs for later slices.

## Delivery evidence

Slice 07 delivery (2026-10-02): explicit internal Task conversion is Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-07-verification.md) records 187 distinct tests / 1618 assertions, three API operations, transactional duplicate prevention and one additive Dev migration. No Task/time/billing fixtures or token changes were retained. Next: Slice 08. HR-2026-10-01-WORKDAY remains Pending until complete-pilot review.

Preview/create/read-back use workday.manage_own and Task view/create/update grants. The new Workday-owned preview table retains consumed ranges until the original three-year deadline. Existing sources are linked through the Slice 04 workflow; conversion creates only standalone internal non-billable time. Slice 08 owns cleanup. Main/production remain gated; no partial human review is requested.
