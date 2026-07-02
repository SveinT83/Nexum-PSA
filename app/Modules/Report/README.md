# Report Module

The Report module owns the shared reporting hub for Nexum PSA.

It follows the module architecture rules:

- Routes live in `app/Modules/Report/routes.php`.
- Controllers live in `app/Modules/Report/Controllers`.
- Views live in `app/Modules/Report/Views`.
- Registry and shared report metadata live in `app/Modules/Report/Support`.
- Module tests live in `app/Modules/Report/Tests`.

## Ownership

The Report module owns discovery, navigation, and common report structure.

Domain modules own their own report calculations and detail pages.

Example:

- `Ticket` owns the Ticket SLA report query and detail view.
- `Report` lists the Ticket SLA report on `/tech/reports`.

Work Context filtering for report results belongs in the owning domain query. The shared Report hub
must not mix internal records into client-safe reports unless the report explicitly exposes that
choice.

## Adding A Report

To add a report:

1. Build the report route, controller, query, view, and tests in the owning domain.
2. Add a report definition class in the owning domain.
3. Register the definition in `config/reports.php`.
4. Update Knowledge documentation.

Do not add report routes to `routes/tech.php`.
Do not add production report views under `resources/views/tech/reports`.

## Post-Beta Direction

Version 2 should add a custom report builder with saved templates and automatic
client report delivery. That work should build on the registry and permissions
created here instead of bypassing the Report module.
