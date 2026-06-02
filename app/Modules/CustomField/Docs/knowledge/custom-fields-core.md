Custom Fields are a platform capability for configurable metadata and human-facing fields.

## Purpose

Custom Fields let admins add structured fields to supported records without adding hardcoded columns.

The first supported record type is `Client`.

Common uses:

- MSP Manager client ID.
- Legacy system ID.
- Customer-specific metadata.
- Searchable integration keys.
- Human-visible internal fields.

## Admin Management

Admins manage custom fields from:

```text
Admin -> System -> Custom fields
```

Admins can:

- search field definitions
- create fields in a modal
- edit field definitions in a modal by clicking a definition row
- delete/deactivate fields

## Field Settings

Each field definition stores:

- model type
- key
- label
- field type
- help text
- options
- sort order
- UI visibility
- UI editability
- API editability
- searchability
- uniqueness per model
- required state
- admin-only state
- optional view permission
- optional edit permission

## Permissions

If `view_permission` is empty, the field follows the normal domain view rules.

If `edit_permission` is empty, the field follows the normal domain edit rules.

When a permission is set, the user must have that permission to view or edit the field.

`admin_only` restricts the field to Admin and Superuser roles.

## Client Integration

Client show pages display visible custom fields in the client workspace `Custom Fields` tab.

Editable fields can be updated from the client workspace tab by clicking the field row. This edits
the value for that client only, not the field definition.

Client settings pages also show editable custom fields as part of the broader client settings form.

The Client API includes custom field values in `custom_fields`.

The Client API supports searchable custom fields:

```text
GET /api/v1/clients?custom_field[msp_manager_id]=12345
```

## Definition API

Custom field definitions are exposed through a read-only API so trusted automations and future AI
agents can discover configured fields before writing values through domain APIs.

```text
GET /api/v1/custom-fields
GET /api/v1/custom-fields/{id}
```

The API requires:

```text
custom-fields.read
```

The API supports filters such as `model=client`, `editable_via_api=1`, and `searchable=1`.

This API returns field definitions only. Field values remain owned by each supported domain API.

## Storage

Custom field definitions live in `custom_field_definitions`.

Custom field values live in `custom_field_values`.

Values are stored structurally with scalar columns for search and typed rendering.
