The Contact domain is Nexum's long-term source of truth for external people, customer contacts,
shared mailboxes, departments, vendor representatives, and communication endpoints.

The first implementation is intentionally migration-safe. Existing customer contacts in
`client_users` continue to support Tickets, Sales, Assets, Nextcloud, and other modules while Contact
records are introduced beside them.

## Phase 1 Scope

Phase 1 creates the canonical Contact tables and compatibility links:

- `contacts`
- `contact_emails`
- `contact_phones`
- `contact_addresses`
- `contact_relations`
- `contact_external_refs`
- `contact_merge_records`
- `client_users.contact_id`
- `user_management.contact_id`

Phase 1 also exposes a Contact workspace at `/tech/contacts`. It is available from the Clients
dropdown in the main navigation and replaces the old Client Users menu entry as the primary client
contact surface.

The Contact list follows the active client/site context used by the Client workspace. When
`active_client_id` or `active_site_id` is set in the session, the list is scoped to that client or
site. Without an active context, technicians can filter by client and site from the filter control.

The create form supports both modes:

- Active site context: client and site are locked automatically.
- Active client context only: client is locked and site can be selected from that client.
- No active context: client and site are optional.

When a new Contact is created with a site relation, Nexum also creates or updates the linked
`client_users` compatibility record so older ticket and client workflows continue to work during the
transition.

The migration command is:

```bash
php artisan contacts:migrate-client-users
```

It creates Contact records from existing client contacts, links the legacy records, and creates
relations to the connected client and site.

## Compatibility Policy

`client_users` must not be removed in the first Contact release. It remains a compatibility layer
until all dependent modules have been migrated.

The safe upgrade path is:

1. Add Contact tables and links.
2. Run the client contact migration.
3. Let old modules keep reading `client_users`.
4. Move modules to Contact one at a time.
5. Verify no module still depends on old fields.
6. Remove legacy fields only in a later cleanup release.

Installations should upgrade gradually through these phases. Large core-domain changes must not
assume that an old installation can safely jump several major versions without running the required
upgrade steps.

## Design Principles

- A Contact is independent from a User Account.
- User Accounts may link to Contacts through `user_management.contact_id`.
- Client contacts may link to Contacts through `client_users.contact_id`.
- Communication methods are stored as separate records, not directly on the Contact table.
- Domain relationships are polymorphic so one Contact can relate to multiple clients, sites, assets,
  vendors, opportunities, contracts, or future records.
- External systems such as MSP Manager should use `contact_external_refs` for source IDs and sync
  metadata.

## Out Of Scope For Phase 1

- Replacing all `client_users` reads.
- Removing old tables or columns.
- Full Contact edit and merge UI.
- AI intelligence or analytics fields.
- Availability scheduling UI.
- Activity feed aggregation.
