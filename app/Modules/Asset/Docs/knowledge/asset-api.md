Asset API routes are available under `/api/v1/assets`.

The API is intended for trusted integrations, n8n workflows, RMM enrichment, inventory imports, and
future AI agents that need to work with asset records.

## Scopes

- `assets.read`: list and view assets.
- `assets.create`: create assets.
- `assets.update`: update assets and ownership context.

## Routes

- `GET /api/v1/assets`
- `GET /api/v1/assets/{asset}`
- `POST /api/v1/assets`
- `PUT /api/v1/assets/{asset}`
- `PATCH /api/v1/assets/{asset}`

`GET /api/v1/assets` supports these ownership filters:

- `client_id`
- `work_context_id`
- `context_type` with `client` or `internal`

## Create Payload

`POST /api/v1/assets` uses the same manual asset defaults as the Tech UI. If `type`, `ip_type`, or
`status` are omitted, Asset Settings supplies the default values.

Required fields:

- `name`

Common optional fields:

- `client_id`
- `site_id`
- `user_id`
- `vendor_id`
- `type`
- `vendor`
- `model`
- `serial_number`
- `mac_address`
- `ip_address`
- `ip_type`
- `hostname`
- `status`

When `client_id` is omitted, Nexum creates an internal Asset for the owning organization. Internal
Assets must not include `site_id` or `user_id`.

When `client_id` is supplied, Nexum resolves the Client Work Context and validates that any supplied
`site_id` or `user_id` belongs to that Client.

## Update Payload

`PUT` and `PATCH /api/v1/assets/{asset}` support partial updates.

If `client_id` is changed without a new `site_id`, Nexum clears the existing Site and Client User
relations. This prevents an Asset from remaining linked to a Site or user under the wrong Client.

Changing an Asset to internal clears `client_id`, `site_id`, and `user_id`, then stores the default
internal Work Context. Internal Assets are excluded from client-specific RMM sync behavior.

When `site_id` or `user_id` is supplied during update, Nexum validates that the selected relation
belongs to the final Client.

## Example

```json
{
  "client_id": 12,
  "site_id": 4,
  "name": "Reception Laptop",
  "type": "laptop",
  "serial_number": "SN-123",
  "hostname": "reception-laptop",
  "status": "in_service"
}
```

Internal Asset example:

```json
{
  "name": "Office Firewall",
  "type": "network",
  "hostname": "office-fw",
  "status": "in_service"
}
```
