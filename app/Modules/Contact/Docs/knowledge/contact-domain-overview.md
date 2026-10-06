The Contact domain is Nexum's source of truth for external people, customer contacts, shared
mailboxes, departments, vendor representatives, and communication endpoints.

## Canonical Identity And Compatibility

The central Contacts workspace, Client Contacts tab, and Site Contacts tab all read canonical
`contacts` and `contact_relations`. They link to the same Contact create, detail, and edit routes.
A Client or Site entry point supplies context to the standard Contact form; it does not expose a
second Client User form.

The canonical records use separate email, phone, address, relation, external-reference, and merge
tables. `client_users.contact_id` and `user_management.contact_id` connect older workflows and user
accounts to the same person.

`client_users` is internal compatibility state. Tickets, Assets, Sales, Nextcloud, and other
legacy consumers may keep their existing Client User ID while they migrate independently. The bridge
row and its primary key are retained so old relationships remain resolvable.

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
which relation types are shown in the Contact form. They can also choose whether authorized Contact
create forms select `Send customer portal invitation` by default. This invitation default is off on
installations without an explicit saved setting.

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
- Contact phone numbers can be marked as allowing transactional SMS. SMS permission is stored on
  the phone record, not on Marketing consent, and `do_not_call` blocks transactional SMS attempts.

Organization entry searches Clients. Selecting a matching Client creates the Contact relation and
shows the site selector. If no Client matches, the value remains plain organization text for later
vendor, lead, or external organization work. When a selected Client is replaced with a free-text
Organization, Nexum removes the old client and site relations during save.

When a Client is selected but no Site is selected, Nexum uses the Client's default Site. When the
Client is changed, the Site selection is reset and defaults to the new Client's default Site.

When a Contact is saved with a Site relation, Nexum creates or updates the linked `client_users`
compatibility bridge. An existing bridge ID is reused when context changes. Removing a Client
relation deactivates the old bridge and clears its default flags instead of deleting it, so Tickets,
Assets, Sales, Nextcloud, and other historical consumers retain their references.

The Role or title field suggests values that already exist on Contacts. The relation selector uses
controlled values such as Contact, Primary contact, Technical contact, Billing contact, Site
contact, Decision maker, Emergency contact, Manager, and CEO.

Users with `customer_portal.invite` see a `Send customer portal invitation` switch while creating a
Contact. Its initial state follows Contact Settings, but it can be changed for that Contact before
save. The switch requires a valid email and an active Client relation. When it is selected, Contact
saves the Contact and relation first, then CustomerPortal applies its existing Client/Site scope,
email identity, active-access, pending-invitation, audit, and queued-email safeguards in the same
transaction. The invitation grants the Customer Portal `Viewer` role for the selected scope.

The switch is not rendered while editing a Contact, and ordinary edits never resend portal
invitations. Users without `customer_portal.invite` cannot reveal or submit the option by changing
the Livewire request.

## API Usage

The Contact Domain exposes read and write API routes under `/api/v1/contacts`.

`GET /api/v1/contacts` can be used for lookup before creating or updating records.

Useful lookup filters:

- `q`: broad search across name, organization, email, and phone.
- `email`: exact email address lookup.
- `phone`: normalized phone lookup.
- `status`: status filter.

Example:

```text
GET /api/v1/contacts?email=ola@example.test
```

`POST /api/v1/contacts` is the primary automation endpoint for n8n, AI agents, and other trusted
integrations. It behaves as an upsert:

- If the submitted email or normalized phone matches an existing Contact, that Contact is updated.
- If no match exists, a new Contact is created.
- If `client_id` is supplied and `site_id` is omitted, Nexum uses the Client's default Site when one
  exists.
- When a Site relation exists, the `client_users` compatibility bridge is created or updated.

The upsert endpoint requires an API token with both `contacts.create` and `contacts.update`.

`PATCH` and `PUT /api/v1/contacts/{contact}` update a known Contact by ID and require
`contacts.update`.

## Ownership Repair API

The Contact Domain also exposes a repair API for trusted cleanup workflows while Nexum still keeps
the legacy `client_users` bridge.

Routes:

- `GET /api/v1/clients/{client}/contacts`
- `POST /api/v1/contacts/{contact}/move`
- `POST /api/v1/clients/{client}/contacts/bulk-fix`
- `POST /api/v1/clients/{client}/contacts/legacy-orphans/cleanup`
- `DELETE /api/v1/clients/{client}/contacts/{contact}`

The `{client}` value can be either the internal Client ID or the Client's `client_number`. If one
value matches more than one Client, Nexum rejects the request instead of choosing one.

`GET /api/v1/clients/{client}/contacts` requires `contacts.read` and returns both canonical Contact
relations and legacy `client_users` so operators can see where the Contact actually belongs.
Ticket records still store the stable compatibility ID from `legacy_client_users[].id` as
`tickets.contact_id`; the top-level canonical Contact `id` is not interchangeable with that value.
Use the Client contact lookup after create/upsert when an integration needs to attach the Contact to
an existing Ticket.

The move, bulk-fix, and detach routes require `contacts.ownership_manage`. They support `dry_run` so
an integration can preview the operation before writing data.

`POST /api/v1/contacts/{contact}/move` accepts:

- `target_client_id` or `target_client_number`
- `target_site_id`
- `dry_run`
- `reason`

Actual moves are transactional. Nexum removes old Client/Site ownership, creates the target
Client/Site relation, and moves or creates one `client_users` bridge row for the target Site.

`POST /api/v1/clients/{client}/contacts/bulk-fix` accepts a list of Contact IDs and returns per-row
statuses such as `no_change`, `would_move`, `would_attach`, `conflict`, and `missing_contact`.
Bulk-fix is conservative: Contacts with multiple current Client owners or multiple linked legacy
rows are reported as conflicts for manual review.

`POST /api/v1/clients/{client}/contacts/legacy-orphans/cleanup` accepts `client_user_ids`,
`dry_run`, and `reason`. Selected unlinked rows are copied to canonical Contact and retain their
stable Client User IDs. Linked rows are skipped.

`DELETE /api/v1/clients/{client}/contacts/{contact}` detaches the Contact from that Client. It
removes canonical Client/Site relations and retires linked compatibility rows by setting them
inactive and clearing default flags. It never deletes the stable bridge IDs. The Contact is
soft-deleted only when `delete_if_orphan` is true and the ordinary orphan checks pass.

Ownership repair calls are written to the activity log with the actor, API token ID when available,
reason, dry-run flag, before state, result, and after state.

## Automatic Legacy Cutover

The forward-only migration
`2026_09_03_180000_complete_canonical_contact_cutover.php` runs automatically during the normal
production migration. It maps every old Client User to a canonical Contact, copies communication
data, creates Client/Site relations, and retains every original Client User ID.

The same cutover adds canonical identity to Marketing members, manual criteria, recipients, events,
and durable delivery keys, plus Telephony calls, Intake submissions, provable Signals, and linked
User accounts. Ticket, Asset, Sales, and Nextcloud IDs remain unchanged and valid.

The action is additive and idempotent. It performs no email send, queue dispatch, provider call, or
Marketing replay. Ambiguous identity or conflicting Marketing delivery evidence stops the migration
for review instead of guessing. Already copied rows remain safe for a corrected rerun.

The maintenance command invokes the same action for controlled read-back or rerun:

```bash
php artisan contacts:migrate-client-users
```

A successful read-back reports zero unlinked `client_users` rows.

## Compatibility Policy

Do not delete `client_users`, recycle bridge IDs, or remove legacy identity columns until every
dependent module has migrated and a later approved ADR defines the historical-data strategy.

## Design Principles

- A Contact is independent from a User Account.
- User Accounts may link to Contacts through `user_management.contact_id`.
- Client contacts may link to Contacts through `client_users.contact_id`.
- Communication methods are stored as separate records, not directly on the Contact table.
- Domain relationships are polymorphic so one Contact can relate to multiple clients, sites, assets,
  vendors, opportunities, contracts, or future records.
- External systems such as MSP Manager should use `contact_external_refs` for source IDs and sync
  metadata.

## Customer Portal Identity

Customer Portal access uses Contact as the canonical external identity. A portal user must be linked
through `user_management.contact_id`, and the Contact must have an active Client or Site relation
before portal membership can be granted.

Portal membership does not make `client_users` authoritative. `client_users` remains a compatibility
bridge for older modules, while Customer Portal reads Contact relations and the explicit portal
membership tables for access decisions.

Authorized Contact creation can request a portal invitation explicitly. Contact owns only the
create-form default and checkbox. CustomerPortal still owns invitation validation, audit, delivery,
acceptance, accounts, and memberships.

## Deferred Work

- Replacing all `client_users` reads.
- Removing old tables or columns.
- Manual Contact merge UI.
- Configurable system language and localized Contact form defaults.
- AI intelligence or analytics fields.
- Availability scheduling UI.
- Activity feed aggregation.
