The Assets API covers the supported Asset browser workflows: listing and filters,
manual registration/editing, classifications, ownership, alert tabs and summaries,
settings and explicitly scoped RMM import/alert synchronization.

Availability (2026-10-26): implemented and verified on Dev; scoped Main promotion
is authorized and tracked in HR-2026-10-26-ASSETS-API. Production rollout is separate.
Provider API execution remains disabled by default. Human scenario review is pending.

## Access And Preparation

1. Open Admin > API keys and create a key with only the required Assets scopes.
2. Confirm the key owner is an active internal user with the matching Asset
   permission. A key never grants its owner a missing user permission.
3. Open Swagger at `/api/documentation` and select the Assets operations. Use the
   target installation's HTTPS address and keep the key in a protected secret store.
4. Start with `GET /api/v1/assets/context`. It returns configured defaults, enabled
   types, sensitivity/criticality values, sorting choices and provider runtime status.

| Scope | Purpose | Current user permission |
| --- | --- | --- |
| assets.read | Lists, records, context/lookups/docs, alerts and owned operation evidence | asset.view; operation evidence rechecks original rights |
| assets.create | Manual creation | asset.create |
| assets.update | Partial update and existing enrichment fields | asset.update |
| assets.settings.read | Read manual defaults | asset.manage_settings |
| assets.settings.update | Configure manual defaults | asset.manage_settings |
| assets.sync | Provider preview and explicit execution | asset.view + asset.update; RMM import also integration.rmm_manage |

Existing keys do not automatically receive the new settings/sync scopes.

## Find And Inspect Assets

Use `GET /api/v1/assets` with `search`, `client_id`, `context_type` (`client` or
`internal`), `work_context_id`, `type`, `status`, `sensitivity_level`,
`criticality_level` and `has_alerts`. Sorting supports `name`, `type`, `client_site`,
`status` and `last_seen_at`, with `direction=asc|desc`. Use `page` and `per_page`
(1-100). These filters are stateless; browser remembered filters do not affect API reads.

`GET /api/v1/assets/{asset}` returns the complete record, classification, ownership
IDs, Work Context, RMM links, lifecycle fields and existing metadata. It includes a
strong `revision` and ETag. `GET /api/v1/assets/docs` returns the browser's Markdown
manual. Related Tickets currently has no implemented relationship action.

## Register Or Edit An Asset

1. Read `GET /api/v1/assets/context`, or add `?asset_id=ID` when editing to retain
   an existing type/status that is no longer enabled for new records.
2. Read `GET /api/v1/assets/lookups/clients` and `vendors` for IDs/names. Read
   `lookups/sites?client_id=ID` and `lookups/owners?client_id=ID` after selecting
   the Client. Every lookup supports search and bounded pagination.
3. Send `POST /api/v1/assets` with `name`. Optional fields are `client_id`,
   `site_id`, `user_id`, `vendor_id`, `type`, `vendor`, `model`, `serial_number`,
   `mac_address`, `ip_address`, `ip_type`, `hostname`, `sensitivity_level`,
   `criticality_level` and `status`. Omitted type/IP/status use manual defaults.
4. To edit, send `PUT` or `PATCH /api/v1/assets/{asset}` with changed fields only.
   Existing API enrichment fields `source`, `is_managed`, `last_seen_at` and
   `metadata` remain supported on update. Unknown fields are rejected.
5. Supply the read ETag in `If-Match` to refuse stale updates. Existing clients may
   omit it for compatibility. Read the returned exact fields and optionally GET
   the record again before marking the integration step complete.

No Client means an internal Asset. Internal Assets cannot select a Client Site or
owner. Changing Client clears omitted Site/owner; repeating the current Client does
not clear them. Supplied Site/owner must belong to the final Client.
Sensitivity: low/medium/high/ultra. Criticality: low/medium/high/critical.

Use an `Idempotency-Key` of 8-200 characters for repeat-safe manual writes.
Repeating the same key/body on the same actor/key returns the original exact result;
a changed body/operation returns 409. Returned `operation_id` can be read through
`GET /api/v1/assets/operations/{operation}` with the original key and current rights.
Receipts are encrypted at rest. They do not grant new access after permission revocation.

## Read Alerts And Checks

`GET /api/v1/assets/alerts` supports `asset_id`, `client_id`, `status=active|resolved`,
`unresolved_only`, page and per_page. This represents Outage, Checks and the alert
sidebar, including pagination through resolved history. `GET assets/alerts/summary`
returns the active count and resolutions in the last day for a Client/Asset or globally.

## Explicit Provider Actions

1. Use `POST /api/v1/assets/alerts/sync/preview` with optional `asset_id` or
   `client_id`; omit both for the global scope. For RMM asset import use
   `POST /api/v1/assets/rmm/preview` with `provider=rmm|tactical_rmm` and optional
   Client/Site. A selected Site requires its Client. An asset_id expands to its
   Client/Site import scope, matching the browser button; it is not remote control.
2. Check the returned `scope`, provider list, `assets_considered` and
   `execution_enabled`. RMM preview may include `inspect_provider=true` to read
   candidate counts without creating/updating Assets or placeholder Sites.
3. Execution requires the operator-authorized runtime switch, a current
   `preview_revision`, `confirm=true`, and an `Idempotency-Key`. Send the exact
   scope to `POST assets/alerts/sync` or `POST assets/rmm/sync`.
4. Read `state`, exact counters/provider results and the operation evidence.
   `completed` means the adapters observed completion, `partial` means some
   provider/import work failed, and `skipped` means no eligible provider work ran.
   `running` or `unknown` requires operator reconciliation; never blindly resubmit
   it with a new key. Replaying its original key does not repeat network work.

Import reads from providers and updates mapped local Assets. Alert observations can
invoke the existing Integration-owned routing/notifications. Neither API installation
nor a preview enables provider execution, schedulers or new rules. Credentials and
raw provider exceptions are excluded from API evidence. Keep APP_KEY and receipts
when recovering or rolling back. Long imports serialize Asset writes while running.

## Settings And Troubleshooting

Read `GET assets/settings`; use `PUT assets/settings` with the complete enabled_types,
default_type, default_ip_type and default_status object. The same validation is used
by Admin > Assets > Asset settings. Optional If-Match refuses a stale settings edit.

401: check authentication. 403: check both scope and current user permission.
404: check the ID and original actor/key for operation evidence. 409: reread state,
check provider activation/mapping or correct conflicting replay identity. 422: fix
fields, classification or Client/Site/owner relations. 428: obtain the preview and
confirm/key before execution. 503: ask the deployment owner to verify database and
migration `2026_10_26_020000_create_asset_api_commands` before writes.
