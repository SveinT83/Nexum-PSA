The Report domain owns the shared reporting hub in Nexum PSA.

It does not own every report calculation. Domain modules own the data and query logic for their own workflows, while the Report domain owns discovery, navigation, permissions, and shared report structure.

## Ownership Rules

The Report domain owns:

- `/tech/reports`
- Report registry and discovery.
- Shared report hub layout.
- Report navigation.
- Shared report permission conventions.
- Future shared filters, export behavior, saved views, and scheduling.

Domain modules own:

- Report-specific queries.
- Report-specific detail routes.
- Report-specific calculations.
- Domain-specific filters.
- Domain-specific Knowledge documentation.

Example:

- Ticket owns the SLA report query and SLA detail page.
- Report lists the Ticket SLA report in the shared report hub.

## Report Registry

Report entries are registered through report definition classes.

Each report definition provides:

- Stable key.
- Title.
- Description.
- Owning domain.
- Route name.
- Permission.
- Icon.
- Tags.

This keeps the hub decoupled from individual module controllers.

## Current Reports

Current registered reports:

- Ticket SLA Report.
- Confirmed workdays, when Workday is enabled and the viewer has explicit workday.view_all.

The Ticket SLA Report is Work Context aware. It defaults to client work so customer/SLA reporting
does not mix in internal Tickets. Technicians can explicitly switch the report to internal work or
all work when they need an operational view.

## Permissions

Opening the Report hub requires:

```text
report.view
```

Individual reports may later require more specific permissions such as export or admin-level report access. The first foundation keeps viewing under `report.view`.

## Future Scope

Future Report work should add:

- Shared date range filters.
- Shared Work Context filters for report results after each domain exposes safe context-aware
  queries.
- Export support.
- Saved report views.
- Custom report builder.
- Saved report templates.
- Client-specific scheduled reporting.
- Automatic report delivery through email and later customer portal surfaces.
- Delivery history and failure tracking.
- Scheduled report delivery.
- Report categories.
- Better cross-domain report metadata.
- Report API endpoints after the API foundation is approved.

## Workday evidence and worklogs

Workday source reconciliation is an employee workflow owned by Workday. Its guarded adapters
read Task/Ticket time and explicitly selected Calendar evidence using the employee's source
permissions. They do not call coordinator worklog APIs or expand workload grants.
Source attribution stays within actual day totals and does not add Commercial consumption,
Task billing projections or planned Calendar time to those totals. Confirmed cross-employee
Workday oversight is a separate registered report; source discovery remains an own-employee workflow.

## Domain-specific discovery checks

A report definition may implement ReportVisibility to enforce its current domain activation and
identity/permission policy before legacy report discovery shortcuts. Workday uses this for its
explicit oversight permission; report.view and the Superuser role name do not bypass it.
The hub shows only domains with a currently visible report. Workday owns confirmed list/detail/
history and totals; the Report hub only links to them. See Workday confirmed-oversight guidance.

## Client time consumption (Dev, 2026-11-05)

The Client-owned internal report is registered as client.time. Requires report.view, client.view,
ticket.view, task.view and commercial.timebank.view together, even for discovery. Open Reports >
Client time consumption, find a Client by name/number/website, then select it. Use inclusive work-date
filters or leave dates blank for all history. Excel/PDF preserve the same scope. Read the Client
Domain Overview section for source-aware totals, private Task restrictions and internal-only sharing.
Done on Dev is separate from Main/production and human acceptance; no new API data export.

## Ticket/Task time and employee suggestions — 22 November 2026

Availability: implemented on Dev. Main source promotion and production deployment are separate.
Do not assume this workflow is available in production until its installed version is verified.

### Select an employee in Workday

Prerequisites: Workday is enabled, your internal account is active, and you have the explicit
workday.view_all permission. Reports navigation also requires report.view.

1. Open **Reports > Confirmed workdays** and focus **Employee name**.
2. The list opens immediately. Type part of a name to narrow it, including Norwegian letters.
   Use **More suggestions** to reach additional employees; people with no days in the chosen
   period are still selectable. Inactive/former staff are labelled.
3. Click a suggestion, or use Arrow keys and Enter. Escape closes the list.
4. Choose **From** and **To**, then **Filter**. Workday retains its maximum of 93 inclusive dates.
5. Edit or clear the employee name to remove the previous exact employee selection.
   **Reset** returns to the default period and all employees.

Workday also retains literal name filtering if you type without selecting a suggestion.
It shows recorded/confirmed work, not private drafts, Calendar plans or billing totals.
Selecting an employee with no matching days correctly returns an empty report.

### Report Ticket and Task work

Prerequisites: an active internal human account with report.view, ticket.view, task.view and
client.view. Private Tasks retain their existing creator/assignee/owner visibility.

1. Open **Reports > Ticket and Task time**.
2. Choose **Employee**, **Client**, **Ticket** and/or **Task** using clickable suggestions.
   Leave Employee or Client empty to include all permitted matching people or Clients.
   Exact-ID selectors require choosing a suggestion or clearing the text.
3. Choose **Work date from** and **Work date to**, or use **Today**, **This week**, **This month**,
   **Previous month** or **All history**. Dates include both endpoints and use original work_date.
   For open-ended history, keep **All history when dates are empty** checked.
4. Combine **Source**, **Work context**, **Billable source**, **Record status** and **Group by**,
   then select **Filter**. A source-specific status restricts results to that source.
   A selected Ticket includes its visible Task effort; a selected Task includes that Task only.
5. Review registered hours/minutes, Ticket and Task subtotals, grouped summaries and time entries.
   Entry and group pages are independent; totals always cover the full matching dataset.
6. Use **Excel** or **PDF** for all matching groups and entries, including entries on other pages.

Examples: select Employee only for one person's work across Clients; Client only for everyone's
work for that customer; choose both for that person's work for that customer. **Internal work**
has no Client. Combining Internal work and a particular Client intentionally gives no matches.

Registered minutes count direct Ticket time plus Task time once. Task-linked Ticket billing
projections, Workday actual time, Calendar plans and direct Commercial quick consumption are
excluded. Estimated/completion-derived Task entries retain their provenance label; this is not
approved payroll or an invoice. Reversing a customer charge does not erase work performed.
Use **Client time consumption** for the separate customer billing/coverage/quick-consumption report.

Archived history is labelled and read-only. Private or mismatched source contexts are excluded
from lookups, totals and downloads. Downloads contain employee names and internal notes; keep them
internal and do not send automatically to customers.

### Troubleshooting

- No names: check native internal account access and the Workday oversight permission. The
  directory does not require a time registration for the selected period.
- Unable to load suggestions: focus the field again; ask an administrator to check access if it
  persists. Do not switch to a shared identity or weaken permissions.
- No entries: check all combined filters and original work dates; clear the selected record or
  employee if necessary. Some records may legitimately have no registered time.
- More than 10,000 entries: choose a narrower period. The report fails explicitly instead of
  providing incomplete totals or downloads.
- Registration and invoice values differ: this report measures registered effort. Financial
  minimums, allocation, coverage and charge corrections belong to Client time consumption.

Human checklist HR-2026-11-22-TIME-REPORTS remains open for intended operator verification.
Employee-assistant retrieval is a separate check from BookStack publication.
