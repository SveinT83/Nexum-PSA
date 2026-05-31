# Juine / Legacy Assistant Entry Instructions

`AGENTS.md` is the authoritative instruction file for Nexum PSA / tdPSA.

Before making code changes, read and follow:

1. `AGENTS.md`
2. `docs/development/ai-team-process.md` for team workflow, change levels, RFCs, ADRs, impact
   analysis, and multi-agent handover
3. `docs/module-architecture.md` when changing modules, routes, controllers, views, or domain ownership
4. `docs/ui-guidelines.md` when changing UI, Blade, layout, navigation, components, or page styling
5. `docs/TODO.md` and any task-relevant documents in `docs/`

This file exists only so Juine or legacy assistant tools discover the tdPSA instruction chain. It
must not override `AGENTS.md`, `docs/development/ai-team-process.md`,
`docs/module-architecture.md`, or `docs/ui-guidelines.md`.

## Historical Breadcrumb Note

- New routes and views should have corresponding breadcrumb definitions in `config/breadcrumbs.php`.
- The `breadcrumbs()` helper and the breadcrumbs partial render breadcrumbs in the `default_tech`
  layout.
- If a route uses a different naming convention, update the helper in `app/Helpers/helpers.php` only
  when it cannot resolve the route automatically.
