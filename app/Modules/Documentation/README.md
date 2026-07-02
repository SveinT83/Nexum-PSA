# Documentation Module

The Documentation module owns the internal and client-scoped documentation system.

## Scope

- Documentation index, create, edit, show, and delete screens.
- Documentation context selection for internal, client, and site scopes.
- Work Context alignment for internal and client-scoped documentation.
- Documentation template model used by category-driven document forms.
- Sidebar menu data for documentation categories.
- Sanctum API endpoints for Documentation records, documentation categories, and documentation
  templates under `/api/v1/knowledge`.

## Architecture

Routes, controllers, views, models, menu logic, and tests live under:

`app/Modules/Documentation`

The module keeps the existing route names:

- `tech.documentations.*`
- `tech.context.set`

This preserves current links while moving the implementation out of Laravel's default controller and view folders.

## API

The Documentation module owns the API implementation for template-based records shown in
`/tech/documentations`, even though the route namespace is `/api/v1/knowledge`.

Implemented routes:

- `GET|POST /api/v1/knowledge/documentations`
- `GET|PATCH|DELETE /api/v1/knowledge/documentations/{documentation}`
- `GET|POST /api/v1/knowledge/documentation-categories`
- `GET|PATCH /api/v1/knowledge/documentation-categories/{category}`
- `GET|POST /api/v1/knowledge/documentation-templates`
- `GET|PATCH /api/v1/knowledge/documentation-templates/{documentationTemplate}`

API tokens use the existing Knowledge abilities:

- `knowledge.read` for list/show.
- `knowledge.create` for creates.
- `knowledge.update` for updates and Documentation soft delete.

Documentation create/update accepts structured request `data` and optional free-form `content` or
`body`. Responses return structured values as `fields` plus `content`/`body`. If content is provided
and the selected template does not already include a `content` field, the API adds a textarea field
to the stored snapshot so the Tech UI renders the content.

Documentation records expose `work_context_id` and `work_context`. The list endpoint supports
`work_context_id` and `context_type` filters in addition to the existing `client_id`, `site_id`, and
`scope_type` filters.
