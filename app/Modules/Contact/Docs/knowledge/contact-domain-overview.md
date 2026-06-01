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

Phase 1 also exposes a Contact workspace at `/tech/contacts`. It is available from the main
navigation and from the Client workspace sidebar. It replaces the old Client Users view as the
primary client contact surface while still keeping the legacy `client_users` bridge populated.

## Contact Workspace

The Contact list follows the active client/site context used by the Client workspace. When
`active_client_id` or `active_site_id` is set in the session, the list is scoped to that client or
site and shows context badges. The context can be cleared from the Contacts page. Without an active
context, technicians can filter by client and site from the collapsed filter control.

The list supports search across:

- Contact name
- Organization name
- Role or title
- Email addresses
- Phone numbers

The list shows the Contact, Organization or Client, Site, and primary communication details so
technicians can quickly confirm that they are working with the right person or endpoint.

The Contact detail page shows the selected Contact's communication details, Organization or Client,
Site, relations, external references, and compatibility links.

## Contact Settings

Contact Settings is available from `Admin -> Clients -> Contact settings` at
`/tech/admin/settings/contacts`.

Access requires the `contact.manage_settings` permission.

Admins can configure the default contact type, default contact status, default relation type, and
which relation types are shown in the Contact form.

Duplicate protection by email and normalized phone remains mandatory and cannot be disabled from
settings. This protects the Contact Domain from accidental duplicate records while still allowing
technicians to select and update an existing match from the Contact form.

## Create And Edit Workflow

The create form is a Livewire form so it can check context while the technician types. It supports
these modes:

- Active site context: client and site are locked automatically.
- Active client context only: client is locked and site can be selected from that client.
- No active context: client and site are optional.

Create and edit use the same Livewire form. Editing a Contact updates the existing record instead of
creating a new Contact.

The form searches for existing Contacts while email and phone are entered. Matching Contacts are
shown before save. If the technician selects an existing Contact, the form fills the known fields and
switches into update mode. If the entered email or normalized phone already belongs to a Contact,
Nexum updates the existing Contact instead of creating a duplicate.

Duplicate prevention is strict for primary communication details:

- The same email address cannot be saved on two Contacts.
- The same normalized phone number cannot be saved on two Contacts.
- Norwegian phone variants such as `0047`, `+47`, and plain local numbers are normalized before
  duplicate matching.

Organization entry searches Clients. Selecting a matching Client creates the Contact relation and
shows the site selector. If no Client matches, the value remains plain organization text for later
vendor, lead, or external organization work. When a selected Client is replaced with a free-text
Organization, Nexum removes the old client and site relations during save.

When a Client is selected but no Site is selected, Nexum uses the Client's default Site. When the
Client is changed, the Site selection is reset and defaults to the new Client's default Site.

When a Contact is saved with a site relation, Nexum also creates or updates the linked `client_users`
compatibility record so older ticket and client workflows continue to work during the transition.

The Role or title field suggests values that already exist on Contacts. The relation selector uses
controlled values such as Contact, Primary contact, Technical contact, Billing contact, Site
contact, Decision maker, Emergency contact, Manager, and CEO.

## Migration Command

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
- Manual Contact merge UI.
- Configurable system language and localized Contact form defaults.
- AI intelligence or analytics fields.
- Availability scheduling UI.
- Activity feed aggregation.
