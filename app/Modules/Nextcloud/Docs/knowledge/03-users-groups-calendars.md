Users, groups, and calendars are mapped from the per-connection settings page after a successful
sync preview.

## Users

Global Nextcloud users can be mapped to active Nexum technician users. If the remote username is an
email address that already exists as a client contact, the user list can also suggest that contact.

Client and site Nextcloud users can only be mapped to client contacts for the scoped client. They can
also be imported as new client contacts, but only when the connection has a default import site.

`Do not import/map` stores the user as intentionally skipped. A skipped user is not imported later
even if they are a member of a mapped Nextcloud group.

## Groups

Global groups map to Nexum roles. A mapping can be:

- `Preview only`
- `Nextcloud group grants Nexum role`
- `Nexum role manages Nextcloud group`

Client and site groups map to client roles:

- `Client admin`
- `Site admin`
- `Viewer`
- `Contact`

For customer-owned connections, `Nextcloud-to-Nexum` group mappings import or update client contacts
for group members and apply the mapped client role. `Preview only` does not import.

## Group membership

Sync reads group membership through the Nextcloud OCS endpoint where the service account is allowed
to see it. The Users card shows the source groups under each remote user.

When a user belongs to a mapped group, the UI suggests the mapped role. For example, if `Ledelse` is
mapped to `Client admin`, a member of `Ledelse` is suggested as `Client admin` during import.

## Calendars

Calendar mappings connect a remote Nextcloud calendar to a Nexum Calendar record. Nexum writes local
calendar events first, then sync pushes to Nextcloud for writable connections.

Supported directions:

- `Two way`
- `Pull only`
- `Push only`

Private events remain private. Sync stores remote calendar IDs, event IDs, ETags, sync hashes, and
timestamps as provider-specific metadata.

## Conflicts

Calendar sync is conservative. If only one side changed since the last sync, Nexum can sync the
change. If both sides changed, a conflict record is created. Conflict UI is planned separately.

