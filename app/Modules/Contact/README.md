# Contact Domain

The Contact domain is the long-term canonical identity layer for external people, client contacts,
shared mailboxes, departments, and communication endpoints in Nexum.

## Migration Strategy

Contact is introduced gradually. Existing `client_users` remain the compatibility layer for Tickets,
Sales, Assets, Nextcloud, and other modules until those modules are migrated one by one.

Phase 1 adds:

- `contacts`
- `contact_emails`
- `contact_phones`
- `contact_addresses`
- `contact_relations`
- `contact_external_refs`
- `contact_merge_records`
- `client_users.contact_id`
- `user_management.contact_id`
- `/tech/contacts` read-only Contact workspace

Run the compatibility migration after deploying the tables:

```bash
php artisan contacts:migrate-client-users
```

The command is idempotent. It creates missing Contact records from existing `client_users`, links
`client_users.contact_id`, creates primary email/phone/address records, and creates relations to the
client and site.

## Upgrade Rule

Do not remove `client_users` or legacy contact fields in the Contact phase 1 release. Future releases
must migrate each dependent module before any cleanup migration removes old columns or tables.

## Source Ownership

New contact functionality should use Contact models. Existing workflows may keep using
`ClientUser` until their module is migrated.

## Customer Portal Invitations

Contact Settings stores whether an authorized create form should select `Send customer portal
invitation` by default. The installation default is off. A user with `customer_portal.invite` can
override the setting for one create action.

When selected, Contact saves the Contact and its Client/Site relations before asking CustomerPortal
to create a viewer invitation in the same database transaction. CustomerPortal remains responsible
for active Contact and Client validation, Site scope, email identity, existing portal access,
pending-invitation replacement, audit, and queued email delivery.

The option is intentionally unavailable on ordinary Contact edit. Editing a Contact never resends a
portal invitation. The Contact API and other create surfaces do not inherit this UI-only default.

## Ownership Repair API

The Contact API includes a repair surface for trusted cleanup tools while `client_users` is still
the compatibility layer.

Supported routes:

- `GET /api/v1/clients/{client}/contacts`
- `POST /api/v1/contacts/{contact}/move`
- `POST /api/v1/clients/{client}/contacts/bulk-fix`
- `POST /api/v1/clients/{client}/contacts/legacy-orphans/cleanup`
- `DELETE /api/v1/clients/{client}/contacts/{contact}`

`{client}` accepts the internal Client ID or `client_number`. This is important in production where
operators may know a customer by client number instead of database ID.

Mutating ownership routes require the `contacts.ownership_manage` API scope and support `dry_run`.
Actual moves update `contact_relations` and the linked `client_users` bridge in one transaction.
Detach removes the selected Client and Site relations and deletes linked legacy `client_users` rows
for that Client, but it does not hard-delete the Contact by default.
Legacy orphan cleanup deletes explicitly selected `client_users` rows only when they belong to the
selected Client and have no linked Contact. Rows with a `contact_id` must use the Contact detach
endpoint instead.

Repair calls are written to the activity log with the actor, API token ID when available, reason,
before state, result, and after state.

## Future Phases

- Duplicate detection and manual merge.
- MSP Manager external references and import mapping.
- Contact activity feed built from domain events.
- Availability and communication preferences UI.
- Legacy cleanup only after all dependent modules are migrated.
