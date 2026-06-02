# RFC: Custom Fields Core

Status: Approved
Date: 2026-06-02
Owner: Svein / Codex

## Context

Nexum PSA needs configurable fields that can be used by humans in the UI and by integrations such as
n8n. A concrete beta need is storing `msp_manager_id` on Clients so an n8n MSP Manager sync can find
and update existing clients reliably.

## Goals

- Create a generic CustomField platform module.
- Store field definitions separately from field values.
- Support Client as the first consumer.
- Allow fields to be visible/editable in UI, API, or both.
- Support searchable and unique fields for integration identifiers.
- Support optional per-field view and edit permissions.
- Expose Client custom fields in Client UI and Client API.

## Non-Goals

- Do not add custom fields to every domain in the first slice.
- Do not build Ticket Type or Workflow bindings yet.
- Do not build a full external reference/sync history framework.
- Do not delete or replace native domain columns.

## Current Behavior

Clients can be searched and updated through native columns only. External IDs require either native
columns or separate integration-specific mappings.

## Proposed Change

Add a `CustomField` module with:

- custom field definitions
- polymorphic custom field values
- admin CRUD for field definitions
- Client show/settings UI integration
- Client API read/write/search integration

## Impact Analysis

Affected modules:

- `CustomField`: new platform capability.
- `Clients`: first UI/API consumer.
- `Integration`: API docs and API ability catalog may later expose dedicated custom field scopes.

Permissions:

- Field definitions may declare optional `view_permission` and `edit_permission`.
- Empty permission falls back to the domain's normal access rules.

## Data And Migration Plan

Add:

- `custom_field_definitions`
- `custom_field_values`

Values are stored structurally with normalized scalar columns for search and a JSON column for complex
values.

## Testing Plan

- Admin can create, edit, search, and deactivate custom fields.
- Client show displays visible fields.
- Client settings can edit UI-editable fields.
- Client API can create/update custom fields.
- Client API can search by custom field key/value.
- Unique custom fields reject duplicate values for the same model type.

## Documentation Plan

- Add CustomField Knowledge documentation.
- Update Client Knowledge documentation.
- Update API documentation.

## Open Questions

- Additional domains should be connected one at a time after Client is stable.

## Approval

Approved by Svein in conversation on 2026-06-02.
