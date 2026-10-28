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


## Service-user Talk notification destinations (Issue #205, 2026-10-28)

Availability: implemented on Dev; Main verification is in progress. Production has not been
promoted or activated. The human-review checklist HR-2026-10-28-TALK-DESTINATIONS remains
Pending before production and any external test. The older webhook/Bot setup above describes
legacy compatibility; ordinary setup uses the service account and the steps below.

### Configure one connection

Prerequisites: an active Nextcloud connection, trusted HTTPS, a configured service username/app
password with access to the intended Talk conversations, and both nextcloud.connection_manage
and nextcloud.talk_manage permissions. Delivery needs Sync or Managed mode; creating an optional
private group needs Managed mode. Changing modes or granting access is a separate decision.

1. Open Admin > System > Nextcloud and the exact connection.
2. In Talk Notifications, select Refresh conversations. This reads the service user's accessible
   conversations without enabling delivery or changing Calendar, Files, or Users settings.
3. Select the permitted destinations, choose Default conversation for this connection, enable
   Talk notifications for this connection, then select Save Talk settings.
4. Open Admin > Notification Channels > Nextcloud Talk. Select the exact conversation in the
   connection group, enable the global channel and save. A connection default is never silently
   substituted for the explicit global destination.
5. After the actual recipient/conversation is authorized, use Send test for the selected target.
   The fixed generic message contains an opaque test reference, not Ticket or Email content.
6. Inspect Last test and its timestamp. A confirmed result includes a message ID independently
   read from that exact conversation. It proves provider storage, not that a person has read it.

Both the global channel and the connection/destination switches must permit sending. Disabling
Talk on one connection preserves unrelated Nextcloud features. Missing, disabled, inaccessible
or stale targets stop delivery; Nexum never chooses the first active connection. A changed host,
service account or credential invalidates cached targets until a successful refresh and review.
A failed refresh marks that connection's cached health unknown and leaves other connections alone.

### Optional private conversation and existing setups

Create a private Talk conversation is an explicit Managed-mode action. The service user creates
a private group, and Nexum independently reads its token/type. No participants are automatically
added. The new conversation remains disabled until selected and saved. Bot configuration remains
an advanced compatibility option; ordinary notification delivery needs no Bot secret.

Existing legacy settings are preserved. The ordinary channel form no longer asks for webhook URLs.
Existing personal legacy targets are not silently moved to a global conversation. In Profile >
Notifications, explicitly choose Clear my legacy target and use the configured conversation for
the relevant event only after checking the recipient scope, then save. This migration does not
enable the user's event preference automatically.

### Troubleshooting and safe retry

- No conversations: verify the exact service account's membership, trusted TLS and connection,
  then refresh. An HTML login page or malformed OCS response is a failure even with HTTP 200.
- Cannot enable: select an available destination and its connection default first, then select an
  eligible global target. Read-only connections cannot send.
- Provider accepted: a message ID was returned but independent read-back did not verify storage.
- Unknown: a POST may have reached Nextcloud. Reconcile the original test reference/message in the
  exact conversation before starting another test; refreshing or using a new key does not prove
  the previous attempt failed. Repeating the identical original action does not send again.
- Stale form / HTTP 412: reload and inspect the current settings. Do not replace an uncertain send
  with a new action key just to bypass a conflict.

### Official API and MCP path

Use the existing official Nextcloud API path for integrations and generated MCP access.
Read GET /api/v1/nextcloud/connections/{connection}/talk-notifications for the cached context,
Talk revision and response ETag. Require current connection_manage and talk_manage permissions.

- POST .../talk-notifications/refresh: nextcloud.talk.write; body revision.
- PATCH .../talk-notifications: nextcloud.talk.write; revision, enabled, complete destination_ids
  replacement array, and nullable default_destination_id.
- POST .../talk-notifications/conversations: nextcloud.talk.write and nextcloud.providers.execute;
  revision, name and confirm=true.
- POST .../talk-notifications/destinations/{destination}/test: nextcloud.talk.execute and
  nextcloud.providers.execute; revision and confirm=true.

Writes require an Idempotency-Key and connection If-Match in addition to the Talk body revision.
Provider writes retain the default-off nextcloud_api.provider_execution_enabled gate. Global
channel PUT uses notifications.channels.write and an explicit config.talk_destination_id; its
paired connection/token are resolved server-side. Global channel tests use notifications.channels.test,
the current channel If-Match and the separately controlled external-test runtime gate. No scopes,
provider gates or recipient grants are activated by this implementation. Receipts retain bounded
IDs/state/reference hashes without message bodies, provider response copies or credentials.
