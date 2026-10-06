# Feature Slice 03: Simple Absence And Consistent Calendar Display

Status: Done On Dev; employee pilot active, human review Pending (2026-10-03).
Date: 2026-10-01
Owner: Codex; product/reviewer: Svein Tore
Parent: [Workday RFC](../rfc/2026-10-01-daily-workday-confirmation.md)
Delivery contract: [Implementation plan](../plans/2026-10-01-workday-implementation-plan.md)
Human review: [HR-2026-10-01-WORKDAY](../human-review.md) - Pending; automated Dev evidence available, complete pilot not yet ready.
Dependencies: Slices 01 and 02.

## Goal

Record explicit employee absence once and reflect it safely in Calendar.

## User-Visible Behavior

An employee records full/partial-day sickness, already agreed holiday, agreed time off in lieu or other absence. Calendar shows unavailable time; sickness registration does not wait for manager approval.

## Scope

- Implement own absence create/read/update/cancel with interval, timezone, classification and revision history. Record administrative absence without diagnosis/medical files or mandatory medical free text.
- Use a dedicated permission-aware Workday absence action as the source of truth. In the same database transaction, create/update/cancel a linked, generically titled Calendar block through Calendar-owned actions.
- Reuse CalendarEventLink for provenance and add a tested uniqueness invariant. Projection blocks have no participants or external sharing side effects.
- Guard every edit/delete/recurrence path to a Workday-owned Calendar block: delegate to the absence action or return a source-edit link. An ordinary Calendar permission must not bypass the absence guard.
- Keep existing calendar-only events unchanged unless the worker explicitly links an eligible source. Do not infer sickness from event titles, automatically backfill, or create two editable copies.
- Derive full-day affected work intervals from the effective plan; partial days suppress only their overlap. Surface conflicting actual work for correction without silently deleting it.
- Deliver complete own absence API; return only neutral availability to shared work/calendar views.

## Out Of Scope

Leave requests/approval, vacation entitlement or flex balance calculation, detailed sickness case management, other-worker absence-reason access and Tripletex writes.

## Data Touched

Implemented workday_absences, workday_absence_revisions and workday_absence_receipts; Calendar events/unique links and an absence fingerprint on Workday previews. Two additive migrations were applied only on Dev. Cancellation preserves history; automatic retention removal remains Slice 08.

## Permissions

Own workday.absence_view_own/manage_own with workday-absences.read/write scopes. Ownership applies even to Superuser. General workday.view_all does not reveal reasons; no cross-worker medical-detail endpoint is introduced. Calendar projection writes use a narrow domain action, not a generic permission bypass.

## Tests

- Immediate full/partial-day registration, correction and cancellation produce one consistent Calendar block.
- Retry, transaction failure and concurrent Calendar/Workday edits do not create orphan or contradictory records.
- Calendar UI/API/overlay/link/metadata responses reveal no absence reason to an ordinary viewer, including a user with broad calendar administration.
- Calendar-only old events remain untouched; plan-free dates and unknown schedules do not invent work hours.
- Day confirmation warns about unresolved work/absence overlap; agreed holiday recording neither approves leave nor changes a balance.
- Run the narrow affected Laravel suites on Dev with synthetic fixtures; no local PHP fallback.
- Inspect authenticated UI/API/HTTP read-back for the implemented behavior; an unauthenticated login
  redirect does not prove the feature works.

## Documentation

Create Workday absence SOP/API Knowledge; update Calendar source-owned editing and privacy documentation.
Update this slice and the parent TODO row in the same session as verification or a concrete blocker.

## Done Criteria

Synthetic sickness/holiday examples pass UI/API/privacy/transaction tests and read back consistently in both domains. No external calendar or phone-provider action occurs.
Record changed files, exact tests/results, migrations/commands, HTTP/UI/API evidence and remaining
human checks. Passing automated tests never marks human review complete. Keep production runtime
off until the required review and separate rollout approval. Do not leave visible stubs for later slices.

## Delivery Evidence (2026-10-02)

See [Slice 03 verification](../plans/2026-10-02-workday-slice-03-verification.md): 121 tests / 960 assertions, six published API operations, neutral Calendar/source guards, Nextcloud exclusion and empty Dev tables. Both switches remain off. Existing independent Calendar events are untouched; explicit legacy linking is not exposed. No manual review or real MCP acceptance is inferred. Next: Slice 04.
