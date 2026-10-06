Use source evidence to describe work already included in your actual workday.

## Review and select sources

Save the actual workday first, then open **Review sources and allocations**.
Choose Task or Ticket to see your own time entries. Choose Calendar and explicitly select a
permitted calendar to see meeting evidence. Access to the source domain is still required.

Select only sources that describe your work. Set the minutes to attribute, then save the
complete selection before switching source type or page. Selecting one source more than once
splits its attribution; removing a selection releases it in the current draft.

Eight actual hours with two Task hours attributed still means eight actual hours: two attributed
and six unallocated. Work without a detailed source remains valid. Billing minimums and
Task-originated Ticket billing projections are excluded. Commercial consumption is not imported.

## Basis and placement

- **Recorded:** an explicit manual Task/Ticket entry, including Task actual time linked to Ticket.
- **Estimated:** completion-derived Task estimates. Explicitly verify before using these minutes.
- **Planned:** Calendar meeting evidence. A calendar appointment does not prove attendance.
- **Unknown:** a source type without a verified actual-time meaning. Explicit verification is required.

Verification preserves the original basis. It does not rewrite the source or automatically confirm
the workday. Calendar absence projections, cancellations, your declined invitations and private
event details you may not view are excluded.

Date-only minutes stay unplaced until you enter start and end. Optional placements must have
exactly the selected elapsed minutes, lie within actual work, exclude unpaid breaks and not
overlap another numeric allocation. Recorded source intervals additionally bound placement.
Use the work description for concurrent activity labels instead of counting the same minutes twice.

## Limits and changes

All source allocations together must fit actual minutes. A source has one shared minute capacity
across your retained workdays. The current draft and last confirmed revision reserve capacity;
a pending correction cannot release the last confirmation for reuse on another day.

Discovery reports **complete**, **partial** or **unavailable**. Follow next-page links and read
truncation notices. Queries and recurrence expansion are bounded; Calendar totals may be only
the observed count when truncated. Missing or inaccessible evidence does not establish missing work.

Source references keep an exact fingerprint. Changed, deleted or inaccessible sources are shown
as needing reconciliation and block a new preview/confirmation. Remove the old reference or
explicitly select the current source again. The previous confirmed snapshot is retained unchanged.

Workday stores minimal source identity, provenance, selected minutes and placement. It does not
copy source titles, notes, calendar descriptions or attendees into history. Source details are
read live through their existing permissions. Workday does not change source records or billing.

## API and MCP clients

GET /api/v1/workdays/{id}/sources accepts kind=task|ticket|calendar, calendar_id, page and per_page.
Use workdays.read and workday.view_own, plus tasks.read/task.view, tickets.read/ticket.view or
calendar.read/calendar.view for the selected source. per_page defaults to 20 and is at most 50.
At most 500 rows are exposed per discovery window. Calendar has additional event, series and
recurrence limits; status/next_page/truncated must be inspected together.

PUT /api/v1/workdays/{id}/allocations requires workdays.write, workday.manage_own, a fresh version,
Idempotency-Key and the complete allocations array. Send [] to clear. Use source_key,
source_revision, kind, calendar_id, minutes, optional start/end and acknowledged. Do not post
discovery titles, URLs or server-derived basis fields as allocation input.

Draft saves may include the same allocations array. Omission preserves and revalidates existing
selections; [] clears them. Preview and explicit confirmation recheck source grants, token abilities,
fingerprints and remaining capacity. Retrying a mutation returns the original receipt; GET
reads current reconciliation state.

Read back data.id, data.version, snapshot.actual_minutes, allocated_minutes, unallocated_minutes
and reconciliation. Do not infer confirmation from selected evidence. Personal bearer API access
is verified independently; LiteLLM/MCP tooling is deferred. The feature remains disabled until rollout is ready.
