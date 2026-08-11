# RFC: Sales Opportunity Lost And Reopen Workflow

Status: Approved
Date: 2026-07-29
Owner: Codex

## Context

GitHub issue #181 records that Sales opportunities can currently be assigned the `lost` status through
the generic edit form. That does not enforce a loss reason, clear forecasts and follow-up work, record a
workflow activity, or provide a safe way to reopen the opportunity.

## Goals

- Add dedicated Mark as lost and Reopen actions in the Tech UI and Sales API.
- Require and retain a loss reason, with an optional internal note.
- Apply status, probability, forecast, timestamps, and follow-up cleanup atomically.
- Remove only the future calendar event that Nexum generated for this opportunity's follow-up.
- Record system activities for both transitions without altering historical activities or quotes.
- Keep lost opportunities searchable and filterable while hiding them from the default active pipeline.

## Non-Goals

- Changing the separate `not_qualified` or `no_quote_allowed` outcomes.
- Restoring a deleted follow-up event when an opportunity is reopened.
- Deleting quote versions, activities, or other opportunity history.
- Introducing configurable loss-reason taxonomies in this slice.

## Current Behavior

The generic opportunity update accepts `lost` as a normal status and leaves probability, follow-up
fields, generated calendar events, and workflow history dependent on the submitted payload. There is no
reopen action.

## Proposed Change

Shared Sales actions own the lost and reopen transitions so the Tech UI and API use identical rules.
Marking an opportunity lost requires a reason, sets probability and weighted value to zero, records
`lost_at`, clears `won_at` and all next-follow-up fields, and conditionally soft-deletes the linked future
event only when its source and metadata identify it as the generated Sales follow-up for this opportunity.

Reopening requires an active status other than terminal and disqualification statuses. It clears the
lost fields, applies the default probability for that status, recalculates weighted value, and does not
recreate the previous follow-up. Both transitions create a Sales system activity in the same database
transaction. The generic update endpoints reject direct transitions into or out of `lost` so callers
cannot bypass the workflow.

The default Sales index excludes lost opportunities unless an explicit status filter or search is used.
Lost details remain visible on the opportunity page.

## Impact Analysis

- Modules: Sales and Calendar.
- Routes: two Tech actions and two scoped Sales API actions.
- Permissions: Tech actions use the existing `sales.opportunity_manage` permission; API actions use
  `sales.update`.
- Data: existing opportunity, activity, and calendar-event columns only.
- Integrations: existing Calendar soft deletion; no external provider calls or queue work.
- UI: dedicated modal/actions and visible loss details.

## Data And Migration Plan

No migration or backfill is required. Existing lost opportunities remain readable. The transition is
transactional, and a rollback restores opportunity and activity rows; soft-deleted calendar events can
be restored manually if operationally required.

## Testing Plan

- Active-to-lost and lost-to-active transitions through the Tech UI.
- Equivalent API transitions and scope enforcement.
- Required reason and valid reopen status validation.
- Forecast/timestamp/follow-up cleanup and activity audit.
- Generated future event deletion without deleting past, manual, or unrelated events.
- Default index visibility and explicit lost filtering/search.

## Documentation Plan

Update Sales Knowledge and API documentation and add a human-review entry for the workflow.

## Open Questions

None. GitHub issue #181 defines the transition fields and the user approved implementation of all open
issues in this Codex task.

## Approval

Svein Tore approved implementation of all remaining open issues in the Codex task on 2026-07-29.
The implementation is limited to the behavior specified in GitHub issue #181.
