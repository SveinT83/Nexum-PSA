Tasks have their own time entries for internal effort and workload reporting.

Task Work Context keeps internal effort separate from customer-scoped task work.
Standalone tasks without a Client are internal work. Ticket-owned tasks inherit
the Ticket Work Context so customer billable work still flows through Ticket
time rules.

The Task rightbar includes a stopwatch with the same start, pause, resume, and
stop behavior as the Ticket stopwatch. Elapsed time is a browser-local draft.
Stopping opens the Task time form and prefills rounded-up minutes plus today's
work date; no database record exists until the form is saved.

Manual time entries can also be added when actual work time is known. Standalone
and Client-owned Tasks store non-billable Task time for workload reporting.

Estimated time can be used for fast completion. If a task is completed and no
actual time exists, the system creates a time entry from the estimate. This makes
small delegated tasks quick to close while still keeping reporting useful.

Ticket-owned Tasks are different because customer work must stay connected to
Ticket billing, contract rates, and timebank handling. Saving Task time requires
work date, minutes, rate, and invoice text. The Task entry always stores the
technician's actual minutes. The system separately creates a pending Ticket time
entry for the customer-billable delta and links that projection back to the Task.

Admin Task Settings decide whether customer billing follows actual Task time or
uses the Task estimate as a minimum. The calculation is cumulative per Task. For
example, a 30-minute estimate with 5 actual minutes tracks 5 minutes for the
technician and bills 30 minutes to the customer. If cumulative actual time reaches
60 minutes, total customer billing becomes 60 minutes. Repeated short sessions do
not apply the estimate minimum repeatedly.

Task-originated Ticket billing projections are excluded from technician worklog
totals because the linked Task entries already contain the actual work. They stay
included in Ticket billing and Economy processing. If actual time is already
registered, later Task completion uses that time without creating duplicate Task
or Ticket time. A Ticket-owned Task with no actual time keeps the existing
completion-time registration requirement.

This makes the ticket the billing source of truth while still allowing tasks to
split and assign the work.

Activity is internal in beta. The task activity stream records important events:

- task created
- status changed
- task completed
- template applied
- assignment changed
- dependency events
- time registered
- internal notes

The activity table includes visibility so customer-visible task activity can be
added later without redesigning the module.

## Workday attribution

When Workday is enabled, employees may select their own Task time as evidence within an actual
workday. Manual and Ticket-linked actual Task entries retain recorded provenance; automatic
estimate-based completion remains estimated and requires explicit employee verification.
Date-only time remains unplaced unless the employee places it. Selecting Task time does not
increase the workday total or change Task entries, estimates or Ticket billing projections.
Changed sources require explicit reconciliation before a new workday confirmation.

## Create an internal Task from saved activity

Workday offers an explicit preview/create action for an employee's saved activity. The preview
shows the exact description, internal Work Context, owner/assignee, open status and actual minutes.
It uses the existing standalone Task creation and time-registration actions. The resulting Task is
owned by and assigned to the employee, and contains one non-billable actual time entry. It is not
completed automatically. A later completion reuses the actual time without creating estimated time.

Workday replaces unattributed minutes with a source reference; its total stays unchanged. Excluded
breaks become separate Workday placements referencing one date-level Task entry. Existing Task or
Ticket time is linked through Sources instead of copied. No conversion enters Ticket-owned billing,
Commercial consumption or invoice processing. A confirmed Workday receives a correction draft.

The preview/create/read-back API requires workday-task-conversion.write and tasks.read/create/update,
plus workday.manage_own and task.view/create/update. Task descriptions follow ordinary internal Task
visibility. Workday-owned previews/receipts have the original work-date retention boundary; created
Tasks/time keep Task ownership. See the Workday task-conversion Knowledge guide and generated
WorkdayTaskConversion schemas. Mark both articles for Knowledge/BookStack sync at approved rollout.
