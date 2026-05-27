# Nextcloud Module Plan

## Purpose

Nextcloud is a dedicated tdPSA domain for connecting Nexum to internal and customer-owned
Nextcloud servers.

The module owns Nextcloud connections, credentials, health checks, file browsing, server-side
mappings, sync jobs, sync logs, and conflict handling. Other domains may use Nextcloud through
module actions and services, but they must not store provider-specific Nextcloud state themselves.

## Domain Ownership

Nextcloud owns:

- Nextcloud server connections.
- Encrypted service account credentials.
- Optional per-user credentials for fallback access.
- Connection mode and scope.
- Health status and sync status.
- File and folder browser metadata.
- Client and site folder mappings.
- Calendar mappings between Nextcloud calendars and tdPSA Calendar records.
- User mappings between Nextcloud users and Nexum users.
- Group mappings between Nextcloud groups and Nexum roles.
- External identities from client/site Nextcloud servers.
- Share-link configuration and metadata.
- Sync conflicts and resolution workflow.
- Dry-run previews before write operations.
- Module-owned Knowledge source pages under `Docs/knowledge` for BookStack-facing documentation.

Nextcloud does not own:

- Nexum calendar events. Calendar owns those records.
- Ticket attachments. Ticket owns ticket attachment records.
- Client records. Client owns client and site records.
- Nexum users, roles, and permissions. UserManagement owns those records.
- Authentication provider abstractions for all providers. A future auth/identity layer should own
  the common login-provider contract.

## Connection Scopes

The module must support these connection scopes from the start:

- `global`: Nexum's internal Nextcloud for technicians and internal operations.
- `client`: a customer-owned Nextcloud connection mapped to a client.
- `site`: a customer-owned Nextcloud connection mapped to a specific client site.

The first UI can stay simple, but the data model must allow multiple connections per client and one
default connection per scope/context.

## Connection Modes

Each connection has a mode that controls what Nexum is allowed to do.

- `read_only`: read metadata, browse allowed folders, read users/groups/calendars, and prepare
  mappings. No writes to Nextcloud.
- `sync`: read and write supported sync objects, such as calendar events or files, but does not
  administratively manage folders, groups, or users.
- `managed`: Nexum can create folders, shares, groups, and group memberships where configured.

Initial rule:

- Global connections may use `sync` or `managed`.
- Client and site connections start as `read_only`.
- Client and site connections should be structurally ready for a mapped writable documents/reports
  folder later, but no general customer-server writes are part of the first build.

## Credentials

The primary credential model is service account first.

Each connection should support:

- One primary encrypted service credential.
- Optional per-user encrypted credentials for fallback or personal audit.
- Credential status, last successful use, and last error.
- A marker on sync records showing whether service or user credentials were used.

Service account is preferred because technicians should not need to maintain app passwords in normal
operation. Per-user credentials remain necessary for calendars or files where the service account
does not have sufficient access.

## Global Nextcloud Behavior

The global connection is the internal Nexum/tdPSA Nextcloud.

It should support:

- Technician calendar sync.
- Technician file browsing.
- Shared customer folders used by technicians.
- User mapping between Nextcloud users and Nexum technicians.
- Group mapping between Nextcloud groups and Nexum roles.
- Nexum-managed group creation and membership sync when mode is `managed`.

Nexum is the local source of truth for PSA workflows. Calendar events are written to Nexum Calendar
first, then synchronized to Nextcloud.

## Client And Site Nextcloud Behavior

Client and site connections are customer-owned Nextcloud servers.

Initial behavior:

- Read-only access.
- Browse allowed folders and metadata.
- Read users and groups for external identity mapping.
- Support a mapped folder intended for future documents/reports writes.
- Do not automatically create Nexum users.
- Do not automatically change customer Nextcloud users or groups.
- Do not enable calendar sync against customer Nextcloud by default.

Client portal login should not be hardcoded to Nextcloud. Nextcloud users/groups should first be
stored as external identities that can later participate in a broader auth provider system alongside
LDAP/OIDC or other providers.

## SSO And Identity Future

Nextcloud must not become the owner of single sign-on. A future Identity/Auth domain should own SSO
providers, login flows, tenant rules, external identity records, and account linking.

Nextcloud should remain a provider integration that can discover users/groups and feed provisioning
rules. The existing user mapping fields (`identity_model_type`, `identity_model_id`, and metadata)
are intentionally compatible with a future provider-neutral external identity table.

Expected future integration points:

- Nextcloud connection references an identity provider or tenant configuration when SSO is enabled.
- Client portal users may authenticate through a customer tenant provider.
- Technician users may authenticate through an internal provider.
- Nextcloud group mappings become provisioning inputs, not the only authentication truth.
- Service account credentials remain necessary for background sync even when interactive login uses
  SSO.

Recommended role precedence for future implementation:

1. Explicit Nexum admin assignment.
2. Tenant SSO group/claim mapping.
3. Nextcloud group mapping for the connected customer server.
4. Default client contact role.

## Folder Mapping

Each connection can define a root folder.

For the global connection:

- Admin selects a root folder, such as a customer folder root.
- Each client can be mapped to a subfolder under that root.
- Nexum can suggest mappings based on client name.
- If connection mode is `managed`, Nexum can create missing client folders.
- If connection mode is not `managed`, Nexum only suggests existing folders.

For client and site connections:

- Admin can map the customer-side folder used for Nexum documents/reports later.
- Initial behavior is read-only.

Folder mapping belongs to the Nextcloud module, not the Client module. Client cards should only read
mapping and status from Nextcloud.

## File Browser And Ticket Attachments

The file browser should support selecting a configured connection and root/mapped folder.

When a file is used from tickets or documentation later, the intended options are:

- Link existing file/share if a suitable share already exists.
- Create a new Nextcloud share link.
- Copy the file into Nexum attachment storage.

Share creation must support:

- Internal user or group share.
- Public link.
- Password.
- Expiry date.
- Read-only or download policy.

Public share must be explicit and should default to an expiry recommendation. Copying into Nexum
storage should be available when the record must keep working even if Nextcloud is unavailable.

## Calendar Sync

Calendar owns Nexum calendar records. Nextcloud sync reads and writes through Calendar actions.

Rules:

- Nexum Calendar is written first for internal workflows.
- Nextcloud receives changes through sync jobs.
- Nextcloud-originated changes are imported into mapped Nexum calendars.
- Private events remain private in both systems.
- Sync interval is configurable per connection, defaulting to 15 minutes.
- A manual Sync now action must be available.

Supported mapping:

- Map a Nextcloud calendar to an existing Nexum calendar.
- Import additional Nextcloud calendars as external calendars when desired.
- Store remote calendar IDs, event IDs, ETags, sync hashes, and last synced timestamps outside the
  Calendar domain when they are provider-specific.

## Calendar Conflict Rules

First implementation should be conservative.

- If only one side changed since last sync, auto-sync.
- If both sides changed, create a conflict.
- If one side deleted/cancelled and the other edited, create a conflict.
- Do not auto-merge field-level changes in the first version.
- Conflicts must not block the rest of the sync batch.

Conflict resolution actions:

- Keep Nexum.
- Keep Nextcloud.
- Edit manually.

Conflicts should appear in the Nextcloud admin panel and notify the relevant technician when the
conflict belongs to a technician calendar.

## Users, Groups, And Roles

Global Nextcloud:

- Nextcloud users can be mapped to existing Nexum technicians.
- Nextcloud users whose username is an email address can also be mapped to existing client contacts
  when the internal server contains customer identities.
- Nextcloud groups can be mapped to Nexum roles.
- Sync reads group membership where the service account is allowed to see it, so user mapping can
  suggest the role inherited from the mapped Nextcloud group.
- Group sync direction is configurable per mapping. A group can stay preview-only, grant a Nexum
  role from Nextcloud membership, or be managed from Nexum toward Nextcloud.
- If connection mode is `managed`, Nexum can create Nextcloud groups for Nexum roles.
- Any write operation should support dry-run/preview before apply.
- No Nextcloud user should be imported automatically. Admins explicitly choose which remote users
  are mapped or imported from the connection settings page.

Client/site Nextcloud:

- Read users and groups as customer identities for the connection's client.
- Allow mapping to existing client contacts for that client only.
- Allow explicit import of selected Nextcloud users as client contacts on the connection's client.
- Never map customer Nextcloud users to Nexum technicians.
- Map customer Nextcloud groups to client roles such as client admin, site admin, viewer, or contact.
- Client/site connections must have a default import site before users can be imported into the
  Client domain. This site decides where new `client_users` records are created.
- When a customer user belongs to a mapped Nextcloud group, the user mapping UI suggests the mapped
  client role and shows the source group beside the user.
- During sync, client/site group mappings with `nextcloud_to_nexum` import or update matching client
  contacts for group members and apply the mapped client role. `preview_only` groups do not import.
- Users explicitly marked as not imported remain skipped even if they belong to a mapped group.
- Do not automatically create Nexum users.
- Do not build login flow in the first implementation.

## Admin Panel

The Nextcloud admin panel should show:

- All connections.
- Scope: global, client, or site.
- Connection mode: read-only, sync, or managed.
- Client/site mapping.
- Base URL.
- Root folder.
- Health status.
- Last successful sync.
- Last error.
- Sync interval.
- Sync now action.
- Shortcut to open the remote Nextcloud login/admin page.
- A per-connection settings page for server details and mapping decisions.
- File/folder mappings.
- Folder browser for selecting root, document, and client folders from the remote server.
- Calendar mappings, with owner suggestions from mapped Nextcloud users.
- User mappings from selected Nextcloud users to existing Nexum users.
- Group/role mappings with configurable sync direction.
- Conflicts.
- Dry-run previews.

Credentials must be stored encrypted. The UI should never display saved secrets.

## Client Card

Clients with a Nextcloud connection should show a Nextcloud card.

The card should show:

- Connection status.
- Connection scope/default marker.
- Mapped folder.
- Last sync status.
- Open Nextcloud shortcut.
- Admin/configuration link for users with permission.

The card reads from Nextcloud state and must not make Client own Nextcloud configuration.

## Implementation Order

Build in this order:

1. Domain skeleton and admin connection panel.
2. Encrypted credentials, connection modes, connection scopes, health check, sync interval, root
   folder, and Sync now shell.
3. Read layer for files, users/groups, and calendars.
4. Mapping UI for client folders, users, groups/roles, and calendars.
5. Global calendar sync with conservative conflict handling.
6. Ticket file picker with link, share-link creation, and copy-to-attachment options.
7. Managed writes for folders, groups, and role membership sync.

## Current Implementation Status

Implemented now:

- Module route file.
- Admin connection panel at `/tech/admin/system/nextcloud`.
- Per-connection settings page for server details, sync overview, and mapping cards.
- Connection model and migration.
- Encrypted service account password storage.
- Optional per-user credential table for future fallback credentials.
- Connection scopes: global, client, and site.
- Connection modes: read-only, sync, and managed.
- Client/site connections are forced to read-only in the first build.
- Root folder and documents folder fields.
- Folder browser modal in the connection settings page for selecting the client root folder and
  documents folder from live WebDAV folders.
- Sync interval field with a 15 minute default.
- Health check action using the standard Nextcloud capabilities endpoint.
- Sync now reads capabilities, users, groups, calendars, and configured root folder metadata.
- Sync preview data is stored on the sync log and summarized on the connection.
- Data tables for folder mappings, calendar mappings, user mappings, group mappings, sync logs, and
  sync conflicts.
- User mapping UI for explicitly linking selected Nextcloud users to Nexum users.
- Group mapping UI for linking Nextcloud groups to Nexum roles with preview-only,
  Nextcloud-to-Nexum, or Nexum-to-Nextcloud sync direction.
- Group mappings can be scoped to a Nexum client so a customer Nextcloud group can later import,
  match, and keep client users in sync for that client.
- Client/site connection settings only expose client contacts from the scoped client when mapping
  users. Remote users can be mapped to existing contacts or imported as new client contacts.
- Client/site group mappings use client roles and inherit the connection client automatically, so
  global roles such as superuser are not offered for customer-owned servers.
- Calendar mapping UI for mapping discovered Nextcloud calendars to Nexum calendars and owners.
- Client folder mapping UI that lists folders under the selected client root folder and links each
  client folder to the correct Nexum client.
- Auto match action for client folders that matches unmapped Nexum clients to unmapped folders by
  normalized name, ignoring common company suffixes such as AS/ASA/ENK.
- Auto match falls back to the active default AI agent when direct name matching leaves unmatched
  clients and folders. AI suggestions are only accepted when they reference existing clients/folders
  and return high confidence.
- Knowledge documentation source pages in `app/Modules/Nextcloud/Docs/knowledge`.
- Knowledge articles under the `Nexum PSA` book and `Nextcloud` chapter for BookStack sync.

Not implemented yet:

- Full file browser for selecting files outside connection setup.
- Real background sync worker.
- Conflict resolution UI.
- Ticket file picker.
- Managed folder/group writes.
- Provider-neutral SSO/identity provider domain integration.
- Incoming Talk bot command processing (receiving and handling commands from Talk users).
- Talk bot webhook endpoint for receiving incoming messages from Nextcloud Talk.

## Talk Bot Integration

The Nextcloud module includes a Talk Bot API integration that sends signed, rich-format
notifications to Nextcloud Talk conversations. This replaces the simple webhook approach
previously used by the Notification module's `NextcloudTalkChannel`.

### Requirements

- Nextcloud 27.1+ / Talk 17.1+ with the `bots-v1` capability.
- A Talk bot installed on the Nextcloud server via `./occ talk:bot:install`.
- The bot's shared secret and numeric ID stored in the Nextcloud connection settings.

### Setup

1. Install a bot on the Nextcloud server:
   ```bash
   ./occ talk:bot:install "Nexum Bot" <secret> <webhook-url> <nextcloud-url>
   ```
2. Enable the bot in one or more Talk conversations.
3. In Nexum admin, go to the Nextcloud connection settings and expand the **Talk Bot Configuration** section.
4. Enter the Bot ID (shown by `./occ talk:bot:list`), shared secret, and default conversation token.
5. Click **Test Bot Message** to verify the integration.

### How It Works

The `NextcloudTalkClient` service sends HMAC-SHA256 signed messages to the Talk Bot API endpoint:

```
POST /ocs/v2.php/apps/spreed/api/v1/bot/{token}/message
```

Each request includes:
- `X-Nextcloud-Talk-Random`: A random string for nonce.
- `X-Nextcloud-Talk-Signature`: HMAC-SHA256 of `{random}{body}` using the bot secret.
- `OCS-APIRequest: true` header.

The `NextcloudTalkChannel` notification channel automatically selects the delivery mode:
- **Bot API** (preferred): When the connection has `talk_bot_id` and `talk_bot_secret` configured.
- **Webhook** (fallback): When only a webhook URL is configured (legacy mode, plain text only).

### Rich Message Formatting

Notifications sent via the Bot API support:
- **Title** (bold header).
- **Details** (key-value bullet list).
- **Links** (Markdown link to the relevant Nexum object).
- **Reference IDs** (deduplication for Bot API).
- **Silent messages** (e.g., SLA warnings don't trigger push notifications).

### Conversation Token Resolution

The channel resolves the target conversation token in this order:
1. Per-user `nextcloud_talk_webhook_url` setting (extracts token from URL).
2. System-wide `default_conversation_token` in the notification channel config.
3. Connection-level `talk_default_conversation_token`.

### Incoming Commands (Planned)

A future webhook controller will receive Talk bot messages (signed with the same shared secret)
and process commands like `!status TK-42` or `!assign TK-42 @username`.

## Technical Direction

Target standard Nextcloud protocols first:

- WebDAV for files.
- CalDAV for calendars.
- OCS/Provisioning APIs for users/groups where available.

Provider-specific behavior should be isolated inside Nextcloud services so Calendar, Ticket, Client,
and UserManagement do not need to know the protocol details.

## Open Technical Risks

- Whether a service account can access and write all required technician calendars without per-user
  credentials.
- How much administrative functionality is available through standard Nextcloud APIs on different
  server versions.
- Share-link behavior and permissions may vary by Nextcloud configuration.
- Enterprise/multi-server customer setups may need connection grouping later.
- Identity provider support should coordinate with future LDAP/OIDC work instead of becoming
  Nextcloud-specific auth code.
