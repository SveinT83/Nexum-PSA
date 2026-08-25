# Feature Slice: Email/Ticket Conversation Relationship Migration

Status: Implemented / Human Review Pending
Date: 2026-08-19
Updated: 2026-08-24
Parent: `docs/plans/2026-08-16-email-mail-completion-slice-index.md` (Order 13)
Review ID: `HR-2026-08-16-013`

## Purpose

Repair the unsafe legacy backfill scaffold without replacing the existing authoritative
`email_ticket_conversation_links` compatibility table. The repair turns legacy
`email_messages.ticket_id` and exact Ticket-message capture provenance into a durable,
account-scoped conversation link only through a reviewed, fail-closed operation.

The broader target model in
`docs/feature-slices/2026-08-16-email-ticket-conversation-relationship-migration.md` remains the
future direction. This safety slice does not claim that its new relationship/capture/event model,
shared write actions, or read-path cutover are complete.

## 2026-08-24 Safety Rework

The former queued job called the absent `ticketLinks` model relationship, chose `User::first()` as
its actor, caught each failure and continued, and had neither a dispatch surface nor focused tests.
It must not be restored or dispatched.

The replacement adds:

- additive migration-run and migration-item ledgers with a stable public run ID, exact requester,
  frozen IDs/fingerprints, per-item status/reason, counts and timestamps;
- `email:backfill-ticket-conversation-links`, which creates a preview by default and accepts an
  exact reviewed public ID through `--apply` only as a separate operation;
- an active, non-system human operator contract requiring `email.account_manage`,
  `email.mailbox_sync_manage`, and `ticket.update` at preview, queue and worker execution;
- default 100 / hard 500 preview bounds, cap overflow refusal, a 15-minute apply TTL, at most 25
  ready items per queue claim, and one unique Email-queue job per run;
- a fail-closed continuation boundary: if dispatching the next page fails after a committed batch,
  the run becomes terminal `failed` with `continuation_dispatch_failed`, keeps its ready rows intact,
  and a newly reviewed preview classifies committed links as mapped before resuming the remainder;
- a final-attempt failure hook that marks an otherwise stranded queued/running run `worker_failed`
  without overwriting a more precise terminal outcome;
- deterministic checks for the live Ticket, active same-account placement/conversation, one
  agreeing primary Ticket, an existing authoritative link, exact source/live Ticket-message
  pointers, and a recognized customer/internal audience; and
- terminal blocked/failed/stale states instead of skip-and-log continuation.

Any missing source, deleted/merged Ticket, ambiguous placement, account mismatch, competing Ticket,
secondary-link collision, unknown audience, missing capture provenance, changed frozen evidence,
expired preview, changed actor or revoked permission creates no link.

## Apply Boundary And Side Effects

Apply writes only the missing active `primary` row in `email_ticket_conversation_links` and the
durable migration ledger. It records the exact selected human in `linked_by` and stores only bounded
IDs and hashes in migration metadata. Plaintext subjects, bodies, participants, attachment names,
provider UIDs, UIDVALIDITY values, folder paths and private conversation keys do not enter the
migration ledger.

The migration does not invoke `LinkEmailConversationToTicket` or `LinkInboundEmailToTicket`. It does
not rewrite `email_messages.ticket_id`, Ticket messages/events/tags/classification, Mail placements,
personal unread/opened state, provider flags/folders, remote-operation rows, rules, Signals,
notifications, portal publication or outbound communication. The source email remains in its
provider mailbox under the normal Mail authorization/read rules, and existing `TD-...` correlation
is unchanged.

An exact active primary link that already exists is `already_mapped`. Repeating a completed job or
creating a fresh preview cannot create a duplicate. If an exact authoritative writer wins after
preview, the migration accepts that winner only while the frozen base identity still agrees.

## Verification

`EmailTicketConversationRelationshipMigrationTest` runs against explicit SQLite `:memory:` with an
isolated `APP_CONFIG_CACHE`, array cache/maintenance/session stores and `HOME=/tmp`. It covers schema,
read-only/sanitized preview, exact human attribution, command dispatch separation, apply
side-effect isolation, customer/internal audience, idempotency, competing Ticket claims, missing
capture provenance, stale evidence and cross-operator denial.

Result on 2026-08-24: focused coverage passes 17 tests / 150 assertions; adjacent conversation
identity, current Ticket-link intake, provider-deletion retention, not-Ticket and merge coverage
passes 15 / 116 (32 / 266 combined). The opt-in migration contract passes 1 / 52 on an actual
socket-only MariaDB 10.11.14 instance and a random disposable schema. It proves `130000` up, named
indexes and foreign keys, valid JSON metadata with the exact `message`, `ticket`, `placements` and
`conversation` keys plus both fingerprints, rejection of invalid JSON, empty down, and refusal to
erase non-empty evidence. Its guarded cleanup left zero matching schemas before the daemon and
datadir were removed. Pint passes for all changed Order 13 PHP files. No migration, preview, apply,
provider operation, queue/cron change or database-data change was run against shared Dev or
production during implementation. The additive migration later ran in Dev batch 127; both ledgers
remain empty, no preview/backfill ran, and production remains untouched.

## Deployment And Review

Migration `2026_08_24_130000_create_email_ticket_conversation_link_migration_ledger.php` is deployed
in Dev batch 127 and must be deployed elsewhere before the command exists operationally. The schema
migration creates an empty ledger; it
does not dispatch or backfill. Rollback refuses once any run/item evidence exists.

After migration, use an exact active human operator ID and run preview only. Review the public ID,
scope fingerprint, candidate/ready/already-mapped/conflict/failed counts and item reason codes on a
disposable data copy. Do not use `--apply` on shared data until the checks in
`HR-2026-08-16-013` have been completed by a named reviewer. The Email worker must be available only
for the later explicitly approved apply.

Order 14 still owns human conflict resolution, and Order 15 still owns not-Ticket/merge compatibility.
This repair neither guesses those outcomes nor silently demotes/unlinks an existing relationship.
