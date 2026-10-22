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

## Automatic calendar synchronization update (2026-10-22)

Implementation is verified on Dev. Production deployment and automatic activation have not been
performed for this update. Existing installations remain paused until an administrator explicitly
activates the reviewed calendar targets. The approved first scope is the work calendar only; other
personal or shared calendars require their own mapping decision.

The calendar-only job follows Sync interval and requires an active global connection, Calendar sync
enabled, active owned calendar mappings and explicit automatic activation. It does not synchronize
users, groups, roles or files. An ordinary connection save preserves activation. Changing the server,
service username or mapping target invalidates automatic activation until an operator reviews it again.

### Check a calendar connection

1. Open Admin > System > Nextcloud and open the connection's settings.
2. Check connection activity, Calendar sync, Sync interval and the mapped calendar/owner.
3. Inspect the latest sync log. Automatic runs use scheduled_calendar_sync; success, partial, failed,
   queued and cancelled are distinct states. A queued job or old health check is not completed sync.
4. In Calendar, open the expected date and compare the event time and privacy with Nextcloud.
5. For partial or failed runs, contact the administrator with the connection and affected date.
   Avoid creating duplicate appointments or repeatedly clicking Sync now.

### Safety and limitations

Manual Sync now retains its existing identity/file preview work, while its calendar step shares the
same lease and reconciliation rules as automatic jobs. Several remote calendars may be viewed
together, but unbound outbound events require a single reviewed destination. Events already bound to
one remote calendar are never exported to a different calendar through batch ordering.

Unlinked matching copies require explicit reconciliation; matching title/time alone is insufficient.
Edits on both sides, deleted/moved resources, unsafe scope changes and unknown write outcomes are
retained for review. Existing remote resources use their original path and an ETag precondition.
A provider write is acknowledged only after its identity and content are read back.

Recurring resources and exceptions are retained in Nextcloud and reported for review rather than
flattened. Nonrecurring resources with unsupported fields can be imported as read-only projections;
provider-only data is never overwritten. Private and confidential classes, UTC times, supported
durations and all-day date boundaries are preserved. No new sharing or invitation delivery occurs.

### Administrator activation

Activation is an operator action after backup, approved mapping scope and deployment verification.
Use the installed PHP runtime and nextcloud:sync-calendars --enable --connection=ID --mapping=ID
for each explicitly reviewed target. This saves activation without starting a sync. Pause with
nextcloud:sync-calendars --disable --connection=ID. Do not enable the whole scheduler as a repair:
the existing minutely scheduler and queue worker must be verified independently.

There is no conflict-resolution UI in this update. An operator must inspect source/target identity
and immutable delivery evidence before reconciling a conflict. Never delete historical records,
clear a pending write blindly or broaden calendar sharing to make synchronization succeed.
