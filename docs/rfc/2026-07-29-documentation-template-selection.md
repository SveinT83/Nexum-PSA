# RFC: Documentation Template Selection Within A Category

Status: Approved
Date: 2026-07-29
Owner: Codex

## Context

GitHub issue #179 records that the Tech create flow always selects the first active template for a
Documentation category. Categories intentionally support several active templates, and the API can
already accept a template identifier, but the technician UI cannot choose one.

## Goals

- Preserve the one-click flow when a category has one active template.
- Require an explicit template choice when a category has several active templates.
- Render fields from the selected template and snapshot that exact schema on create.
- Reject inactive templates and templates owned by another category.

## Non-Goals

- Changing templates on existing Documentation records.
- Adding template versions, defaults, or new permissions.
- Rebuilding the Documentation form renderer or context selector.

## Current Behavior

The create controller and store action both take the first active template for the category. Template
ordering therefore decides the schema silently whenever more than one template is active.

## Proposed Change

The category remains the first choice. The controller loads every active template in deterministic
name/ID order. One template is selected automatically. Several templates produce a compact second
step, and the selected ID is carried into the create form and store request.

The store action resolves the selected template only from active templates in the submitted category.
For backward compatibility, a request without `template_id` may still use the template automatically
when exactly one is active. Missing or invalid selection with several templates is a validation error.

## Impact Analysis

- Module: Documentation.
- UI: Tech Documentation create flow.
- Data: existing `documentation_templates`, `documentations.template_id`, and snapshot JSON only.
- Permissions and routes: unchanged.
- Integrations, queue, scheduler, cache, and frontend build: unchanged.
- Risk: wrong-category or inactive templates must never be accepted through a crafted request.

## Data And Migration Plan

No migration or backfill is required. Existing Documentation records and snapshots are unchanged.

## Testing Plan

- One active template is selected automatically.
- Several active templates require a choice and persist the chosen template/snapshot.
- An inactive or wrong-category template returns a validation error.
- Run the complete Documentation feature suite on Dev.

## Documentation Plan

Update the Documentation module README and Knowledge overview. Add a human-review entry for the
changed create flow.

## Open Questions

None. The issue defines the required selection and validation behavior.

## Approval

Svein Tore approved implementation of all remaining open issues in the Codex task on 2026-07-29.
The implementation is limited to the behavior specified in GitHub issue #179.
