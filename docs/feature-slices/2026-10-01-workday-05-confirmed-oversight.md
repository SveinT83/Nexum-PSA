# Feature Slice 05: Confirmed-Time Oversight And Reusable Rights

Status: Done On Dev; employee pilot active, human review Pending (2026-10-03).
Date: 2026-10-01
Owner: Codex; product/reviewer: Svein Tore
Parent: [Workday RFC](../rfc/2026-10-01-daily-workday-confirmation.md)
Delivery contract: [Implementation plan](../plans/2026-10-01-workday-implementation-plan.md)
Human review: [HR-2026-10-01-WORKDAY](../human-review.md) - Pending, complete pilot not ready.
Dependencies: Slices 02-04.

## Goal

Give Superuser a reliable named overview and make access assignable to future HR roles.

## User-Visible Behavior

Authorized viewers filter confirmed work by person/date and see actual totals, descriptions, allocations and confirmed revisions. Drafts and absence reasons remain private.

## Scope

- Implement a Workday-owned confirmed overview/query and register it in the Report hub. Report owns discovery; Workday owns confirmed-time calculations and detail routes.
- Grant workday.view_all explicitly to Superuser through the documented migration/role path. Permission checks, rather than role-name conditionals, allow a test HR-style role to receive the same access.
- Show only confirmed versions; corrections in progress do not replace published confirmed facts. Show unallocated time neutrally, without productivity ranking.
- Add a separately scoped overview API with bounded dates, pagination, truthful completeness and explicit worker filtering.
- Keep confirmed Workday totals separate from the older Task/Ticket worklog totals; never add both as if they are independent hours.
- Recheck access to linked source detail. Return neutral unavailability, not sensitive absence classification, where an operational availability indicator is needed.

## Out Of Scope

Manager approval/editing, other-user draft inspection, employee ranking, cross-company export and creating an HR module.

## Data Touched

New read models/registry entries over Workday revisions; additive indexes if required. No source time or billing mutation.
No time-table or source-data migration is required. Additive migration 2026_10_02_180000
creates workday.view_all and explicitly grants it only to the existing Superuser role.
It was applied on Dev after tests; existing token abilities are unchanged.

## Permissions

workday.view_all plus workdays.read-all for other-worker API reads; own read scopes remain self-only. Report navigation permission does not substitute for the Workday grant. No automatic broad API-key upgrades.

## Tests

- Superuser and an explicitly granted synthetic HR role can read confirmed data; ordinary/ungranted roles cannot.
- Drafts, current correction drafts, raw suggestions and restricted absence details remain absent from UI/API/search/history.
- Revocation takes effect on list/detail/history and source links; forged filters cannot widen company/worker scope.
- Existing Report discovery remains usable and counts/pages are truthful for large fixtures.
- Run the narrow affected Laravel suites on Dev with synthetic fixtures; no local PHP fallback.
- Inspect authenticated UI/API/HTTP read-back for the implemented behavior; an unauthenticated login
  redirect does not prove the feature works.

## Documentation

Add Workday oversight/permission documentation and Report registry guidance; document default role grants and API opt-in.
Update this slice and the parent TODO row in the same session as verification or a concrete blocker.

## Done Criteria

The access matrix passes on Dev and desktop/mobile views give matching confirmed totals without leaking drafts or counting source hours again.
Record changed files, exact tests/results, migrations/commands, HTTP/UI/API evidence and remaining
human checks. Passing automated tests never marks human review complete. Keep production runtime
off until the required review and separate rollout approval. Do not leave visible stubs for later slices.

## Delivery Evidence

Slice 05 delivery (2026-10-02): confirmed overview, detail/history, Report discovery and reusable oversight rights are Done On Dev, default-off. [Verification](../plans/2026-10-02-workday-slice-05-verification.md) records 136 passing tests / 1392 assertions, three API reads and the Superuser-only Dev permission migration. Existing tokens were not changed. Next: Slice 06. Human review remains Pending until the complete pilot is ready.
