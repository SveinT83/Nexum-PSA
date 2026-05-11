# Documentation Module

The Documentation module owns the internal and client-scoped documentation system.

## Scope

- Documentation index, create, edit, show, and delete screens.
- Documentation context selection for internal, client, and site scopes.
- Documentation template model used by category-driven document forms.
- Sidebar menu data for documentation categories.

## Architecture

Routes, controllers, views, models, menu logic, and tests live under:

`app/Modules/Documentation`

The module keeps the existing route names:

- `tech.documentations.*`
- `tech.context.set`

This preserves current links while moving the implementation out of Laravel's default controller and view folders.
