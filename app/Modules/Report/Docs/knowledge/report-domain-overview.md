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
