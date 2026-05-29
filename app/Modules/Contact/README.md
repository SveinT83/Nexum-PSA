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

## Future Phases

- Contact create and edit UI.
- Duplicate detection and manual merge.
- MSP Manager external references and import mapping.
- Contact activity feed built from domain events.
- Availability and communication preferences UI.
- Legacy cleanup only after all dependent modules are migrated.
