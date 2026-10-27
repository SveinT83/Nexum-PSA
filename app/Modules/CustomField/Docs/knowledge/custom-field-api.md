Availability: this guide describes the approved CustomField API extension on Dev.
Main promotion and production deployment are separate; production availability is
not established by Dev tests or this handbook page. Human review is tracked by
HR-2026-10-27-CUSTOM-FIELD-API. Ticket write/rule capabilities remain default-off.

## Prerequisites And Access

1. An authorized administrator manages definitions under **Admin -> System -> Custom fields**.
2. For automation, open **Admin -> Integrations -> API** and create a key for the
   intended active internal user with only the needed abilities below.
3. Send `Authorization: Bearer {token}` and `Accept: application/json` over trusted HTTPS.
4. Configuration requires the user's existing `customfield.manage_settings` permission.
   A token ability never grants that user permission. A narrow manager can configure
   fields without receiving Client or Ticket record access.

| Ability | Supported operation |
| --- | --- |
| `custom-fields.read` | Discover visible definitions, read exact definitions; managers also get full configuration/context |
| `custom-fields.create` | Create definitions with native management permission |
| `custom-fields.update` | Edit definitions, sorting and active state with native management permission |
| `custom-fields.delete` | Soft-delete definitions with native management permission |
| `custom-fields.operations.read` | Read this user/token's receipt; original mutation ability and management permission are also required |

Non-managers need the relevant Client or Ticket view permission. They see only active
field definitions allowed by `admin_only` and the optional view permission. Filtering
occurs before totals/pagination. Unknown model/filter names fail validation. Management
permission names are not disclosed in ordinary discovery. No field values are returned
by these definition endpoints. Customer Portal receives no Ticket Custom Field data.

## Discover And Configure Definitions

| Method | Path under `/api/v1` | Result |
| --- | --- | --- |
| GET | `/custom-fields` | Filtered and paginated definitions, links and metadata |
| GET | `/custom-fields/context` | Manager-only models, 11 types, available permissions, defaults and Ticket gate status |
| GET | `/custom-fields/{definition}` | Exact definition, content revision and ETag |
| POST | `/custom-fields` | Created definition and operation receipt (201) |
| PATCH | `/custom-fields/{definition}` | Updated definition and operation receipt |
| DELETE | `/custom-fields/{definition}` | Exact inactive/deleted result; stored values retained |
| GET | `/custom-fields/operations/{operation}` | Own encrypted command's exact original result/status |

List filters: `model=client|client_site|ticket`, `q`, `active`, `editable_via_api`,
`searchable`, `page` and `per_page` (1-100; default 25). Boolean query filters use 0/1.
Canonical registered PHP model names are accepted for compatibility; integrations
should use the stable aliases. For example:

```text
GET /api/v1/custom-fields?model=client&searchable=1&per_page=25
```

Before creating a field, read `/custom-fields/context` and check for the model/key in
`/custom-fields`. Select the target model once: it cannot be retargeted after creation.
Use a lowercase key beginning with a letter, containing only letters, digits and
underscores. Keys remain reserved after soft deletion and across canonical/legacy
aliases. Do not reuse a field key to represent an unrelated meaning.

```json
{
  "model_type": "client",
  "key": "external_reference",
  "label": "External reference",
  "field_type": "text",
  "help_text": "Reference used by the approved integration.",
  "visible_in_ui": true,
  "editable_in_ui": true,
  "editable_via_api": true,
  "searchable": true,
  "unique_per_model": true,
  "required": false,
  "admin_only": false,
  "active": true,
  "sort_order": 0
}
```

Supported types: `text`, `textarea`, `number`, `date`, `datetime`, `select`,
`multiselect`, `checkbox`, `email`, `phone`, `url`. Select/multiselect use `options`,
a JSON list of strings, or the web-compatible `options_text` with one option per line;
do not send both. Optional `view_permission` and `edit_permission` must name existing
permissions or be null. `default_value` is not a supported setting in this workflow.

PATCH changes only supplied settings. For example `{"active":false}` deactivates a
field without resetting visibility, permissions, options or other settings. The web
form retains full-form checkbox behavior. Editing key/type/options does not convert
old stored values or update immutable Ticket Rules targets: review affected records
and rule versions before such an edit. Incompatible pinned rules fail closed.

## Command Headers And Safe Retries

1. Generate a new `Idempotency-Key` of 8-200 characters for each intended mutation.
2. For PATCH/DELETE, GET the exact definition and send its ETag as `If-Match`.
3. Send the mutation and retain `operation_id` / `X-Operation-Id` and the exact headers.
4. Read the returned definition or `/custom-fields/operations/{operation}` to verify
   the exact result. Do not infer success from a submitted request alone.
5. After a transport timeout, retry the identical body/path/headers with the same key.
   It returns the original result with `replayed:true`, without a second write.

The key is bound to the user and current token/session. Reusing it for different
input, path or precondition returns 409. A stale If-Match returns 412; reload and
review before creating a new command. Missing required headers return 428, invalid
input returns 422, denied access 403, unavailable targets 404 and a missing command
migration 503. Revoked rights or original abilities also prevent replay/read-back.
Receipts and their result snapshots are encrypted at rest; keep the installation
APP_KEY available for recovery. Receipts are historical results, not evidence that
an old definition still has that same current configuration.

DELETE sets active=false and soft-deletes the definition. It keeps stored values for
retention/audit, returns `values_retained:true`, and ordinary definition GET becomes
404. Retrying the identical DELETE uses the receipt even after the target is hidden.
Restoration and physical deletion are not supported by this administrative workflow.

## Read And Write Values In The Owning Domain

Use existing Client, Client Site and Ticket APIs with their own token/native rights.
Definition administration does not grant access to the records where values live.

```json
{
  "custom_fields": {
    "external_reference": "REF-123"
  }
}
```

- Client: POST `/clients`, PATCH `/clients/{client}`; read the exact Client/workspace.
- Site: POST `/clients/{client}/sites`, PATCH `/client-sites/{site}`; read that exact Site.
- Ticket: POST `/tickets`, PATCH `/tickets/{ticket}`; read the exact authorized Ticket.

Text/textarea/phone values are scalar text; number must be finite and fit decimal(20,6)
(up to 14 integer and 6 fractional digits), date is a real
`YYYY-MM-DD` calendar date, datetime is a valid date/time, checkbox is a recognized
boolean, email/URL must validate, select must use an allowed option and multiselect
must contain a list of allowed strings. Nested or wrong-type values are rejected.
Send null to clear an optional value. Multiselect displays as an empty list after
clearing. Required values cannot be cleared; creating a record requires its editable
required fields. Omitted fields on a partial update are preserved. Unknown, inactive,
wrong-model, API-disabled or unauthorized keys are rejected, and a failed multi-field
patch does not partly save its valid values. Unique fields reject a value already
assigned to a different record of that model, including legacy storage aliases.

`editable_in_ui` and `editable_via_api` are independent. A field editable through
one channel does not become editable through the other. `admin_only`, view and edit
permissions still apply. Searchable Client/Site values can be filtered through:

```text
GET /api/v1/clients?custom_field[external_reference]=REF-123
GET /api/v1/client-sites?custom_field[site_reference]=SITE-123
GET /api/v1/clients/{client}/sites?custom_field[site_reference]=SITE-123
```

Ticket retains its work-context restrictions, normal change events and independent
UI/API/rule gates. Disabled writes return an explicit error. This API extension does
not activate Ticket Rules or any provider/AI/worker/scheduler. A real Ticket field
change emits one normal minimized event; identical input emits none.

## Troubleshooting

- Missing definition: verify model, active/deleted state, native domain rights and view permission.
- Cannot configure: verify both `customfield.manage_settings` and the exact token ability.
- Cannot write a value: verify owning record rights, field edit permission and channel flag.
- Ticket write disabled: follow its separately approved activation process; do not bypass the gate.
- Duplicate key: check inactive/soft-deleted definitions and legacy model aliases.
- Stale update: reload the exact definition and review changes before retrying with a new key.
- Missing migration: an operator must install the exact approved CustomField command migration.

The source guide, Swagger schemas and automated evidence describe verified behavior;
manual review and production rollout remain separately recorded release decisions.
