# Legacy Assistant Notes

This file is retained for historical context only.

Use these files instead:

- `AGENTS.md` for mandatory assistant instructions and read order.
- `module-architecture.md` for the current module and domain architecture standard.
- `ui-guidelines.md` for current UI, layout, Blade, component, and styling standards.

## Historical Breadcrumb Note

- New routes and views should have corresponding breadcrumb definitions in `config/breadcrumbs.php`.
- The `breadcrumbs()` helper and the breadcrumbs partial render breadcrumbs in the `default_tech`
  layout.
- If a route uses a different naming convention, update the helper in `app/Helpers/helpers.php` only
  when it cannot resolve the route automatically.
