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

Current registered report:

- Ticket SLA Report.

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
