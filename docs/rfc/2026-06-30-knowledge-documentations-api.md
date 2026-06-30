# RFC: Knowledge Documentations API

Status: Approved
Date: 2026-06-30
Owner: Codex

## Context

Nexum PSA has two documentation surfaces:

- Knowledge articles under `/api/v1/knowledge/articles`.
- Template-based Documentation records shown in `/tech/documentations`.

Automation can currently write Knowledge articles, but it cannot create or update the
template-based Documentation records technicians expect to find in the Documentation list. During
customer documentation work this forced direct BookStack writes or Knowledge article workarounds,
leaving `/tech/documentations?cat=...` incomplete.

This is API and cross-domain work because the API is exposed under Knowledge-style URLs, the data is
owned by the Documentation module, and categories use the shared Taxonomy `categories` table.

## Goals

- Add Sanctum-protected API endpoints for Documentation records shown in `/tech/documentations`.
- Reuse existing API abilities:
  - `knowledge.read`
  - `knowledge.create`
  - `knowledge.update`
- Let automation create Documentation categories with `type=documentation`.
- Let automation create Documentation templates for those categories.
- Let automation create Documentation records from structured request `data` and free-form `content`
  or `body`.
- Return enough context for API clients to match UI behavior: category, template, client, site,
  scope, title, structured `fields`, content/body, template snapshot, and timestamps.
- Keep implementation owned by `app/Modules/Documentation`.

## Non-Goals

- Do not create a generic Taxonomy API in this slice.
- Do not expose vendor/supplier registers through these endpoints.
- Do not delete categories or templates in this slice.
- Do not replace Knowledge articles or BookStack sync behavior.
- Do not add a new API ability namespace unless future work needs finer-grained separation.

## Current Behavior

Documentation records are stored in `documentations` with:

- `category_id`
- `template_id`
- `client_id`
- `site_id`
- `scope_type`
- `template_snapshot_json`
- `data_json`

The Tech UI renders fields by mapping `template_snapshot_json[*].Name` to `data_json` values. There
is no API for creating those records.

## Proposed Change

Add Documentation-owned API routes under `/api/v1/knowledge`:

```text
GET    /knowledge/documentations
POST   /knowledge/documentations
GET    /knowledge/documentations/{documentation}
PATCH  /knowledge/documentations/{documentation}
DELETE /knowledge/documentations/{documentation}

GET    /knowledge/documentation-categories
POST   /knowledge/documentation-categories
GET    /knowledge/documentation-categories/{category}
PATCH  /knowledge/documentation-categories/{category}

GET    /knowledge/documentation-templates
POST   /knowledge/documentation-templates
GET    /knowledge/documentation-templates/{documentationTemplate}
PATCH  /knowledge/documentation-templates/{documentationTemplate}
```

Documentation create/update accepts:

- `category_id` or `category_slug`
- optional `template_id`
- optional `client_id`
- optional `site_id`
- `title`
- optional structured request `data`
- optional free-form `content` or `body`

Responses return structured values as `fields` plus `content` and `body` aliases for the free-form
content value.

When free-form content is provided and the selected template snapshot does not contain a `content`
field, the API appends a visible textarea field to the stored snapshot so the content renders in the
existing Tech UI.

## Impact Analysis

- **Documentation:** owns controllers, resources, route file, tests, module docs, and payload
  shaping.
- **Knowledge API:** URL namespace and existing `knowledge.*` abilities are reused for API callers.
- **Taxonomy:** category records are created only with `type=documentation`.
- **Clients:** `client_id` and `site_id` are validated, and a site must belong to the selected
  client. If only `site_id` is supplied, the client is inferred from the site.
- **Permissions/API:** no new web permissions; API token ability enforcement uses existing Sanctum
  abilities.

## Data And Migration Plan

No schema change is required. The slice uses existing tables:

- `categories`
- `documentation_templates`
- `documentations`

Document deletion uses the existing soft delete on `documentations`.

## Testing Plan

- Feature tests for category creation/listing.
- Feature tests for template creation/listing.
- Feature tests for Documentation create/list/show/update/delete.
- Feature tests for ability enforcement.
- Validation tests for client/site mismatch.

## Documentation Plan

- Update Documentation README with API ownership and route overview.
- Update Integration API management Knowledge documentation so API scopes describe Documentation
  records, categories, and templates.

## Open Questions

- Should category/template delete endpoints be added after we decide dependency behavior for
  existing documents?
- Should future API abilities split Documentation away from Knowledge when external integrations
  need narrower permissions?

## Approval

Approved by Svein in conversation on 2026-06-30:

- Reuse existing `knowledge.*` API scopes.
- Support structured request `data` and free-form `content`/`body`.
- Include API creation of Documentation categories and templates.
