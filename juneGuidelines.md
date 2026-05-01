# Breadcrumbs
- Always ensure that new routes and view files have corresponding breadcrumb definitions in `config/breadcrumbs.php`.
- The `breadcrumbs()` helper and `partials.breadcrumbs` component automatically handle rendering in the `default_tech` layout.
- If a route uses a different naming convention (e.g. with or without `tech.` prefix), the helper function in `app/Helpers/helpers.php` should be updated if it cannot automatically resolve it.
