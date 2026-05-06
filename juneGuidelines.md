# Domain Architecture (STRICT)
- This project follows a strict **Domain-Driven Modular Architecture**.
- ALL domain-specific code (Controllers, Views, Routes, Actions, Menus, etc.) MUST be placed within its respective module under `app/Modules/{Domain}/`.
- Logic previously found in `app/Service/SideBarMenus/` MUST now be moved to `app/Modules/{Domain}/Menus/SideBar/` if it belongs to a domain.
- Refer to `module-architecture.md` for the full specification. This file is the **primary source of truth** for project structure.
- NEVER use standard Laravel directories (`app/Http/Controllers`, `resources/views`, etc.) for domain-related files.

# Breadcrumbs
- Always ensure that new routes and view files have corresponding breadcrumb definitions in `config/breadcrumbs.php`.
- The `breadcrumbs()` helper and `partials.breadcrumbs` component automatically handle rendering in the `default_tech` layout.
- If a route uses a different naming convention (e.g. with or without `tech.` prefix), the helper function in `app/Helpers/helpers.php` should be updated if it cannot automatically resolve it.
