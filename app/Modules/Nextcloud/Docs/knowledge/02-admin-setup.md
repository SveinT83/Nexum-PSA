Use `/tech/admin/system/nextcloud` to create and manage Nextcloud connections.

## Create a connection

Choose the connection type first:

- `Internal/global` for the company Nextcloud.
- `Client` for one customer Nextcloud server.
- `Client site` for a customer Nextcloud server dedicated to one site.

Set `Base URL` to the server root, for example `https://cloud.example.com`. The admin URL is derived
as `/settings/admin` unless an override is saved.

Set the service username and service app password. The service account must have enough access to
read the folders, users, groups, and calendars you want Nexum to see.

## Client and site connections

For client-owned servers, select the client and a `Default import site`. The import site decides
where new `client_users` records are created when Nextcloud users are imported from mapped groups or
from the Users card.

If the default import site is missing, sync can still preview users and groups, but it must not
create client contacts.

## Folders

Global connections use a client root folder. Nexum can list folders under that root and map each
folder to a Nexum client. The Auto match action tries direct name matching first and can use the
default AI agent for remaining uncertain matches.

Client and site connections use a documents folder. This is the customer-side folder intended for
future documentation and reports. Customer connections do not show the global client-folder mapping
flow.

## Health and sync

`Check` validates the connection through Nextcloud capabilities.

`Sync now` reads enabled areas:

- users and groups
- group members
- calendars
- files under the configured root
- mapped calendar events when calendar sync is enabled

Sync results are stored as sync logs and the latest summary is stored on the connection.

## API administration and safe changes (2026-10-27)

Availability: Dev implementation; production deployment remains separate.

1. Open `Admin -> System -> Nextcloud` to identify the exact connection and scope.
2. Open API Management and select only the required Nextcloud abilities for the caller.
3. Read `/api/v1/nextcloud/connections/{connection}` and retain its `ETag`.
4. Send a new `Idempotency-Key` and that exact `If-Match` for a write. PATCH keeps omitted
   fields, credentials and Talk configuration. Supplying a secret additionally needs
   `nextcloud.credentials.write`; stored secrets are never returned.
5. Read the exact resource and `/api/v1/nextcloud/operations/{operation_id}` afterward.

A 412 means the connection/mappings changed: read again before creating another command.
A 428 means the key or revision header is missing. Use the original identical key and payload
for a lost response; never change the payload under an existing key. Credential/server/owner
scope changes pause affected automatic calendar consent. Review targets before enabling again.
