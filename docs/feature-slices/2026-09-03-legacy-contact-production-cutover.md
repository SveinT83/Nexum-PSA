# Feature Slice: Legacy Contact Production Cutover

Status: Done On Dev
Date: 2026-09-03
Parent: ../rfc/2026-09-03-canonical-contact-workflow-and-legacy-cutover.md
Owner: Codex

## Goal

Automatically map every production Client User to Contact while preserving all current relationships
and irreversible Marketing delivery evidence.

## User-Visible Behavior

After the normal production migration, old Client contacts appear in canonical Client and Contacts
views without manual copying. Existing Tickets, Assets, Sales records, Nextcloud mappings, Marketing
history, calls, intake records, and portal/user links continue to point to the same person.

## Scope

- Idempotent Contact mapping and field copy.
- Canonical Client and Site relations.
- Stable Client User bridge IDs.
- Additive Marketing list/member/recipient/event and manual-criteria links.
- Guarded Marketing delivery identity-key enrichment.
- Additive Telephony, Intake, Signal, and User canonical links.
- Automatic forward-only Laravel data migration.
- Maintenance-command read-back and idempotent rerun.
- Cross-module migration and preservation tests.

## Out Of Scope

- Sending or replaying Marketing email.
- Deleting or collapsing historical recipient rows.
- Dropping legacy-only columns.
- Automatically resolving ambiguous identity conflicts.

## Data Touched

- contacts, contact_emails, contact_phones, contact_addresses, contact_relations.
- client_users and user_management contact links.
- marketing_lists segment criteria.
- marketing_list_members.
- marketing_campaign_recipients and marketing_campaign_events.
- marketing_campaign_delivery_identity_keys only through Marketing's guarded action.
- telephony_calls, intake_submissions, and provable signal contact links.

## Permissions

The data migration runs only through the deployment migration process or server-side maintenance
command. It exposes no HTTP route and grants no permission.

## Tests

- Legacy field and relation copy.
- Idempotent rerun.
- Existing explicit Contact mapping wins.
- Ambiguous mapping fails closed.
- Stable bridge IDs retain Ticket, Asset, Sales, and polymorphic references.
- Marketing canonical IDs and delivery keys are added to the same delivery.
- Marketing identity conflicts remain blocked and cannot send.
- Telephony, Intake, Signal, and User links are populated.
- Migration performs no queue, scheduler, provider, or outbound-email action.

## Documentation

- Contact deployment and compatibility documentation.
- Marketing deployment and identity documentation.
- TODO implementation/read-back state.
- HR-2026-09-03-005 production checklist.

## Done Criteria

- The automatic migration is present and passes against SQLite tests and authoritative Dev.
- Zero legacy Client Users remain unlinked after a successful cutover.
- Every provable dual-identity relation receives the same canonical Contact ID.
- Existing legacy IDs and domain relationships remain unchanged.
- Marketing identity evidence is enriched without dispatching or replaying a delivery.
- An ambiguous match or identity conflict stops safely, reports sanitized evidence, and leaves only
  additive idempotent work for a reviewed rerun.
- Rerunning the cutover produces no duplicate Contacts, relations, recipients, or identity keys.
- Knowledge, TODO, and HR-2026-09-03-005 describe deployment and read-back.
- Production remains pending until the backup, worker stop, migration, idempotent read-back,
  historical relationship checks, cache clear, worker restart, and smoke verification are complete.
