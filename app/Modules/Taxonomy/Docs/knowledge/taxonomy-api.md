Taxonomy API routes expose shared categories and tags for trusted integrations, automation, imports,
and future AI agents.

All routes live under `/api/v1/taxonomy` and use Sanctum bearer tokens.

Required scopes:

- `taxonomy.read`: list and view categories and tags.
- `taxonomy.create`: create categories and tags.
- `taxonomy.update`: update categories and tags.
- `taxonomy.delete`: soft-delete categories and tags.

## Categories

Categories are shared classification records. They may be grouped by `type` and may have a parent
category.

Routes:

- `GET /api/v1/taxonomy/categories`
- `GET /api/v1/taxonomy/categories/{category}`
- `POST /api/v1/taxonomy/categories`
- `PUT /api/v1/taxonomy/categories/{category}`
- `PATCH /api/v1/taxonomy/categories/{category}`
- `DELETE /api/v1/taxonomy/categories/{category}`

`GET /api/v1/taxonomy/categories` supports:

- `q`: search by name, slug, or description.
- `type`: restrict to one category type.
- `parent_id`: restrict to one parent category. Send an empty `parent_id` value to list root
  categories.
- `is_active`: filter active or inactive categories.
- `per_page`: pagination size.

Common create/update fields:

- `parent_id`
- `name`
- `type`
- `description`
- `is_active`

The API generates the slug from the name and prevents duplicate slugs.

Categories are soft-deleted. Deleting a category is blocked when it has child categories, linked
services, or linked documentation templates.

## Tags

Tags are shared labels used by modules such as Tickets, Email, Knowledge, Contacts, and Tasks through
the shared `taggables` table.

Routes:

- `GET /api/v1/taxonomy/tags`
- `GET /api/v1/taxonomy/tags/{tag}`
- `POST /api/v1/taxonomy/tags`
- `PUT /api/v1/taxonomy/tags/{tag}`
- `PATCH /api/v1/taxonomy/tags/{tag}`
- `DELETE /api/v1/taxonomy/tags/{tag}`

`GET /api/v1/taxonomy/tags` supports:

- `q`: search by name, slug, or description.
- `active`: filter active or inactive tags.
- `per_page`: pagination size.

Common create/update fields:

- `name`
- `color`
- `icon`
- `description`
- `active`

The API generates the slug from the name and prevents duplicate tag names or slugs.

Tags are soft-deleted. Existing tagged records keep their historical pivot rows, but deleted tags no
longer appear in normal tag lookups.

## Operational Notes

Use this API when external systems need to prepare categories or tags before importing data, or when
an AI agent needs to classify records consistently across modules.

The API does not attach tags to arbitrary records in this slice. Record-specific tagging remains owned
by each domain API because each domain must validate whether a tag can be applied to that record.
