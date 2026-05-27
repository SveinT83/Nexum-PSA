The Nextcloud integration connects Nexum PSA to internal and customer-owned Nextcloud servers.
It owns provider-specific connection setup, credentials, folder browsing, user/group discovery,
calendar sync metadata, mapping state, sync logs, and conflict tracking.

Nexum remains the local source of truth for PSA work. Other domains, such as Calendar, Ticket,
Client, and future Tasks, should call Nextcloud module services instead of storing Nextcloud-specific
state themselves.

## Connection scopes

`global` is the internal company Nextcloud used by technicians and shared operations.
It can sync technician calendars, browse internal folders, map customer folder roots, and map
Nextcloud groups to Nexum roles.

`client` is a customer-owned Nextcloud server tied to one client. Users discovered here are customer
identities only. They must never be mapped to technicians.

`site` is a customer-owned Nextcloud server tied to a specific customer site. It follows the same
rules as `client`, but the connection is more narrowly scoped.

## Connection modes

`read_only` allows health checks, users/groups discovery, calendar discovery, and file browsing.
It does not write to Nextcloud.

`sync` allows supported bidirectional sync objects, such as mapped calendar events.

`managed` is reserved for future administrative writes such as creating folders, groups, and
memberships. Customer-owned connections currently stay read-only by design.

## Credentials

The preferred credential model is a service account with an app password. Per-user app credentials
are supported structurally for future fallback cases, but normal operation should not require every
technician to maintain their own app password.

Secrets are encrypted in storage and must not be displayed back in the UI.

## Boundaries

Calendar owns Nexum calendar records. Ticket will own ticket attachment records. Client owns clients,
sites, and contacts. UserManagement owns technician users, roles, and permissions. Nextcloud owns
only the provider-specific state needed to connect these domains to Nextcloud.

