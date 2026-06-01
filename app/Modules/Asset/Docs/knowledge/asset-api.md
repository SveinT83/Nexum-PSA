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

`GET /api/v1/assets` supports `client_id` as a filter.

## Create Payload

`POST /api/v1/assets` uses the same manual asset defaults as the Tech UI. If `type`, `ip_type`, or
`status` are omitted, Asset Settings supplies the default values.

Required fields:

- `client_id`
- `name`

Common optional fields:

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

When `site_id` is supplied, Nexum validates that the Site belongs to the selected Client.

## Update Payload

`PUT` and `PATCH /api/v1/assets/{asset}` support partial updates.

If `client_id` is changed without a new `site_id`, Nexum clears the existing Site relation. This
prevents an Asset from remaining linked to a Site under the wrong Client.

When `site_id` is supplied during update, Nexum validates that the Site belongs to the final Client.

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
