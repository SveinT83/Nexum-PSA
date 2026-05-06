# DocController

Controller file: `app/Http/Controllers/Tech/Doc/DocController.php`

## Purpose

`DocController` handles the technician-facing Documentation / Knowledge area.

This is not only a placeholder controller. The current implementation already supports a working documentation flow with:

- documentation listing
- client/site/internal context filtering
- category filtering
- dynamic create form based on active documentation templates
- storing documentation records with template snapshots
- editing existing documentation using the saved snapshot
- rendered read view
- soft deletion through the `Documentation` model

The controller currently uses the existing `Documentation`, `DocumentationTemplate`, `Category`, `Client`, and `ClientSite` models.

---

## Domain naming clarification

Current code uses the name **Documentations** in routes/views/models, but functionally this behaves like an internal documentation / knowledge base system.

Recommended interpretation:

- `Documentation` = structured internal/client/site documentation record
- `DocumentationTemplate` = dynamic JSON field definition for documentation forms
- `Category` = shared system category used to group documentation and attach templates
- `scope_type` = visibility/context level: `internal`, `client`, or `site`

TODO:

- Decide whether this domain should remain named `Documentations` or be aligned with `Knowledge` naming.
- Avoid mixing this module with generic uploaded client/site files unless that is explicitly intended.
- Keep permission names consistent with the global permission plan.

---

## Active controller actions

### `index(Request $request)`

Purpose:

- Displays the documentation index.
- Loads sidebar menu items from `DocumentationsMenu`.
- Loads active clients for the context selector.
- Loads documentation records with category/client/site/template relations.

Filtering behavior:

- Optional `cat` query parameter filters by category ID or slug.
- `cat=all` disables category filtering.
- If `session('active_client_id')` exists, only documentation for that client is shown.
- If `session('active_site_id')` also exists, only documentation for that site is shown.
- If `session('only_internal')` is true, only `scope_type = internal` records are shown.
- If request has `exclude_internal`, internal documentation is excluded.

View:

- `resources/views/tech/documentations/index.blade.php`

Returns:

- paginated documentation records ordered by latest update.

TODO:

- Document the exact route name and URL from `routes/tech.php`.
- Add explicit permission requirement.
- Add empty-state behavior to the view documentation.
- Confirm whether technicians should see all documentation by default when no context is selected.

---

### `setContext(Request $request)`

Purpose:

- Stores active documentation context in the session.
- Used by the context selector to switch between all, internal, client, and site views.

Session keys used:

- `active_client_id`
- `active_site_id`
- `only_internal`

Behavior:

- `active_client_id = none` clears all context filters.
- `active_client_id = internal` enables internal-only mode.
- Any other `active_client_id` filters documentation by client.
- `active_site_id = none` clears site filter.
- Any other `active_site_id` filters documentation by site and clears internal-only mode.
- Preserves existing query parameters such as `cat` when redirecting back.

TODO:

- Validate that selected client/site IDs are accessible to the current user.
- Validate that selected site belongs to selected client.
- Document exact POST route name.
- Add tests for context switching and query parameter preservation.

---

### `create(Request $request)`

Purpose:

- Shows the create form for a new documentation record.
- Loads active categories that have at least one active template.
- Loads active clients.
- Loads sites for the active client context, if present.
- If a category is selected via `cat`, loads the active template for that category and exposes its JSON fields to the view.

Template behavior:

- The create view does not hardcode all fields.
- Fields come from the first active `DocumentationTemplate` for the selected category.
- Template fields are stored in `DocumentationTemplate.fields` and cast to array in the model.

View:

- `resources/views/tech/documentations/docs/create.blade.php`

TODO:

- Decide what should happen if a category has multiple active templates.
- Decide whether category selection should be allowed without an active template.
- Add validation/UX for missing template.
- Document the dynamic field schema used in `fields` JSON.
- Consider moving dynamic form rendering into a reusable Blade partial or Livewire component.

---

### `store(Request $request)`

Purpose:

- Validates and stores a new documentation record.
- Requires category, title, and an active template for the selected category.
- Captures a snapshot of template fields at creation time.
- Stores user-entered dynamic form data in `data_json`.
- Calculates `scope_type` from selected client/site.

Validation:

- `category_id`: required, must exist in `categories.id`
- `client_id`: nullable, must exist in `clients.id`
- `site_id`: nullable, must exist in `client_sites.id`
- `title`: required string max 255

Scope calculation:

- default: `internal`
- if `client_id` is set: `client`
- if `site_id` is set: `site`

Template snapshot:

- `template_snapshot_json` stores the template field structure at the time the documentation was created.
- This protects existing records from breaking when the original template is changed later.

Redirect:

- Redirects to `tech.documentations.show`.

TODO:

- Validate that `site_id` belongs to `client_id`.
- Validate permission for creating internal/client/site documentation.
- Add audit logging: `documentation.created`.
- Store `created_by` / `updated_by` if the table supports it later.
- Decide if `firstOrFail()` on template should become a user-friendly validation error.
- Add tests for snapshot creation.

---

### `edit($id)`

Purpose:

- Shows the edit form for an existing documentation record.
- Loads documentation with category, client, site, and template.
- Loads active categories with active templates.
- Loads active clients.
- Loads sites for the documentation's current client.
- Uses `template_snapshot_json`, not the current live template, to render editable fields.

Important behavior:

- Existing documentation remains editable even if the original template has changed.
- This is intentional and should not be removed without a migration/versioning plan.

View:

- `resources/views/tech/documentations/docs/edit.blade.php`

TODO:

- Add permission checks for editing internal/client/site documentation.
- Decide if category/template can be changed after creation. Current update flow does not update category/template.
- Document whether changing category is blocked by design or simply not implemented.
- Add audit logging: `documentation.edit.opened` only if view audit is desired; otherwise only log saves.

---

### `update(Request $request, $id)`

Purpose:

- Updates metadata and dynamic data for an existing documentation record.
- Preserves original template snapshot.
- Recalculates `scope_type` from client/site values.

Validation:

- `client_id`: nullable, must exist in `clients.id`
- `site_id`: nullable, must exist in `client_sites.id`
- `title`: required string max 255

Important behavior:

- `category_id` is excluded from dynamic data.
- `template_id` is not changed.
- `template_snapshot_json` is not changed.
- Only `client_id`, `site_id`, `title`, `scope_type`, and `data_json` are updated.

Redirect:

- Redirects to `tech.documentations.show`.

TODO:

- Validate that `site_id` belongs to `client_id`.
- Add audit logging: `documentation.updated` with before/after values.
- Add permission checks.
- Add optimistic locking or updated_at conflict detection if multiple technicians may edit the same documentation.
- Confirm whether scope changes should be allowed after creation.

---

### `show($id)`

Purpose:

- Displays one documentation record in rendered/read mode.
- Loads category, client, site, and template relations.
- Uses dedicated rendered view.

View:

- `resources/views/tech/documentations/docs/show_rendered.blade.php`

Expected rendering behavior:

- `template_snapshot_json` provides field labels/structure.
- `data_json` provides stored values.
- The read view should map data values back to the saved snapshot instead of relying on the current live template.

TODO:

- Add permission checks for internal/client/site visibility.
- Document the exact rendering rules in `show_rendered.blade.md`.
- Add fallback display for fields missing in `data_json`.
- Add handling for deleted/inactive category/template references.

---

### `destroy($id)`

Purpose:

- Deletes a documentation record.
- Since `Documentation` uses `SoftDeletes`, this performs a soft delete.

Redirect:

- Redirects to `tech.documentations.index`.

TODO:

- Add permission checks for deletion.
- Add confirmation UI requirement in view documentation.
- Add audit logging: `documentation.deleted`.
- Decide whether deletion should be replaced by archive/inactive for documentation history.
- Add restore flow if soft deletes are intended to be recoverable.

---

## Related models

### `App\Models\Doc\Documentation`

Current fields:

- `template_id`
- `category_id`
- `client_id`
- `site_id`
- `title`
- `scope_type`
- `template_snapshot_json`
- `data_json`

Model behavior:

- Uses `SoftDeletes`.
- Casts `template_snapshot_json` to array.
- Casts `data_json` to array.

Relationships:

- `template()` belongs to `DocumentationTemplate`
- `category()` belongs to `Category`
- `client()` belongs to `Client`
- `site()` belongs to `ClientSite`

TODO:

- Consider adding `created_by`, `updated_by`, `deleted_by`.
- Consider adding explicit scope constants for `internal`, `client`, `site`.
- Consider adding query scopes: `internal()`, `forClient()`, `forSite()`.

---

### `App\Models\Doc\DocumentationTemplate`

Current fields:

- `category_id`
- `name`
- `fields`
- `is_active`

Model behavior:

- Uses `SoftDeletes`.
- Casts `fields` to array.
- Casts `is_active` to boolean.

Relationships:

- `category()` belongs to `Category`

TODO:

- Decide if templates need versioning.
- Decide if more than one active template per category is allowed.
- Add documented JSON schema for `fields`.
- Add audit logging for template changes.

---

### `App\Models\System\Category`

Current fields:

- `parent_id`
- `name`
- `slug`
- `type`
- `description`
- `is_active`

Model behavior:

- Uses `SoftDeletes`.
- Auto-generates slug from name on create if slug is empty.

Relationships:

- `parent()`
- `children()`
- `templates()` has many `DocumentationTemplate`
- `services()` has many services through `category_id`

Important note:

- `Category` is shared across multiple domains, not documentation-only.
- Documentation-specific category behavior should use `type` or a dedicated scope/filter.

TODO:

- Confirm category `type` value used for documentation categories.
- Ensure documentation category selectors filter by documentation type only.
- Avoid showing service categories as documentation categories unless intended.

---

## Related views

Known views from current controller and repository search:

- `resources/views/tech/documentations/index.blade.php`
- `resources/views/tech/documentations/docs/create.blade.php`
- `resources/views/tech/documentations/docs/edit.blade.php`
- `resources/views/tech/documentations/docs/show_rendered.blade.php`
- `resources/views/tech/documentations/docs/show.blade.md`
- `resources/views/tech/documentations/docs/docs.md`
- `resources/views/tech/documentations/documentations.md`

TODO:

- Verify that every Blade file has matching `.md` documentation.
- Update view docs so they mention template snapshot behavior.
- Ensure create/edit docs match current controller flow.
- Ensure future Livewire category/template forms do not duplicate controller logic accidentally.

---

## Related services

### `App\Service\SideBarMenus\DocumentationsMenu`

Used by:

- `index()`
- `create()`
- `edit()`
- `show()`

Purpose:

- Builds sidebar navigation for documentation categories.

TODO:

- Document exact menu behavior in the service's own `.md` file.
- Ensure menu category filtering uses documentation category type only.

---

## Database behavior

### `documentations` table

Current migration creates:

- `id`
- `template_id` foreign key to `documentation_templates`
- `category_id` foreign key to `categories`
- `client_id` nullable foreign key to `clients`
- `site_id` nullable foreign key to `client_sites`
- `title`
- `scope_type`
- `template_snapshot_json`
- `data_json`
- timestamps
- soft deletes

Current delete behavior in migration:

- `template_id` uses cascade delete.
- `category_id` uses cascade delete.
- `client_id` uses cascade delete.
- `site_id` uses cascade delete.

TODO:

- Reconsider cascade delete. Documentation may need historical preservation if client/site/category/template is deleted.
- Consider `nullOnDelete()` for client/site/template/category if historical records should survive.
- Add indexes for `scope_type`, `category_id`, `client_id`, `site_id`, and `updated_at` if list performance becomes an issue.

---

## Permissions and access control

Current controller does not show explicit authorization checks in the methods.

Expected permission direction:

- View internal documentation: likely `knowledge.view.tech` or a dedicated documentation permission.
- Create documentation: likely `knowledge.create` or documentation-specific create permission.
- Edit documentation: likely `knowledge.edit` or documentation-specific edit permission.
- Manage templates/categories: `template.admin` or a documentation-template-specific permission.

TODO:

- Decide final permission naming.
- Add middleware or policy checks.
- Add model policies for documentation visibility by `scope_type`.
- Ensure client/site scoped documentation cannot be viewed by unauthorized users.

---

## Audit requirements

Current controller does not visibly write audit logs.

Required future audit events:

- `documentation.created`
- `documentation.updated`
- `documentation.deleted`
- `documentation.restored` if restore is implemented
- `documentation.context.changed` only if context switching should be audited
- `documentation.template.snapshot.created`
- `documentation.scope.changed`

TODO:

- Add audit logging for create/update/delete.
- Include actor, object ID, object type, before/after values, origin, and timestamp.
- Mask sensitive dynamic fields if templates later support secret/sensitive field types.

---

## Error handling

Current behavior mostly relies on Laravel defaults:

- `findOrFail()` returns 404 when documentation is missing.
- validation returns 422.
- `firstOrFail()` on missing active template returns 404.

TODO:

- Replace missing template 404 with a user-friendly validation or redirect error.
- Add explicit handling for inaccessible client/site scope.
- Add consistent error messages for missing category/template.
- Add feature tests for 403/404/422 paths.

---

## Known technical risks

1. Category leakage across domains
   - `Category` is shared with services.
   - Documentation category selectors must filter by `type` or another clear rule.

2. Cascade deletes may remove documentation history
   - Current migration cascades category/template/client/site deletion.
   - This may conflict with audit/history expectations.

3. Multiple active templates per category are undefined
   - Current controller uses first active template.
   - This should become deterministic or explicitly prevented.

4. Authorization is not visible in controller
   - Must be enforced through route middleware or policies.
   - Needs verification.

5. Dynamic fields lack documented schema
   - Copilot/developers need a clear schema before extending templates.

---

## Done when

This controller documentation is considered complete when:

- All routes are listed with route names and URLs.
- Permissions are explicitly documented.
- All related views have matching `.md` files.
- Template JSON schema is documented.
- Context filtering is covered by tests.
- Store/update/show flows are covered by tests.
- Audit events are implemented and documented.
- Category filtering by documentation type is confirmed.
- Cascade delete behavior is reviewed and intentionally accepted or changed.

---

## Maintenance rule

- Keep this file updated whenever controller methods, validation rules, route bindings, models, migrations, dynamic field schema, or side effects are changed.
- File naming follows controller naming: `DocController.md`.
