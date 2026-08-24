# ADR: Durable At-Most-Once Marketing Delivery Identity And Claims

Status: Accepted
Date: 2026-08-24
Decision Makers: Svein Tore Ramstad / Codex
Related RFC: `../rfc/2026-08-24-evergreen-marketing-contact-sequences.md`
Related Feature Slices:

- `../feature-slices/2026-08-24-evergreen-marketing-delivery-invariant-migration.md`
- `../feature-slices/2026-08-24-evergreen-marketing-contact-progression-calendar-scheduling.md`
- `../feature-slices/2026-08-24-evergreen-marketing-technician-api-documentation-review.md`

## Context

Marketing currently identifies a recipient row by campaign email, list-member row, and cycle. A list
refresh can replace the list-member row, and a repeat cycle deliberately creates another recipient
for the same campaign-email record. The current send job also calls SMTP before it durably records
exclusive ownership of the delivery. Two workers, or a process failure after provider acceptance,
can therefore invite a duplicate transmission.

The approved evergreen sequence changes completion from campaign-wide to contact-specific. A
campaign-email record must be delivered no more than once to the same person and no more than once
to the same normalized destination mailbox, regardless of list membership, cycle, scheduler runs,
or later campaign extension. SMTP cannot provide exactly-once delivery, so an uncertain outcome must
prefer a missed delivery requiring review over an automatic duplicate.

## Decision

### Delivery Ledger

Marketing owns an additive `marketing_campaign_deliveries` ledger. One row represents the lifetime
claim for one `marketing_campaign_email_id` and one resolved recipient identity set. It is separate
from `marketing_campaign_recipients`, so historical cycles and tracking rows remain intact while one
durable record controls whether SMTP may ever be attempted.

The ledger stores:

- `marketing_campaign_id` and `marketing_campaign_email_id`;
- `marketing_campaign_recipient_id` as the canonical recipient row that acquired the claim;
- `status`;
- a unique 64-character `claim_token` used for stale-worker compare-and-set checks;
- a unique, reserved `rfc_message_id` that is generated before the claim transaction and reused
  unchanged at the Email transport boundary;
- `claimed_at`, `provider_write_started_at`, `sent_at`, and `outcome_unknown_at`;
- a sanitized `last_error_code`, metadata, and normal timestamps.

`marketing_campaign_recipients` gains the additive fields
`marketing_campaign_delivery_id`, `claimed_at`, and `outcome_unknown_at`. Its existing
`rfc_message_id` carries the same reservation for compatibility and tracking. The delivery ledger,
not recipient cycle or status alone, is the authority for lifetime no-resend decisions.

### Identity Keys

Every delivery owns one or more rows in `marketing_campaign_delivery_identity_keys`. Each row stores
the delivery ID, denormalized `marketing_campaign_email_id`, `identity_type`, and `identity_hash`.
Supported identity types are:

1. `contact` when a first-class Contact is known;
2. `client_user` while the legacy client-user compatibility identity is known;
3. `email` for the trimmed, case-normalized destination mailbox.

The hash is SHA-256 over a versioned canonical type/value representation. Email normalization is
provider-neutral: it trims and case-normalizes only; it does not remove plus tags or apply
provider-specific dot rules. Every available identity key is attached to the same delivery so the
database protects both the person and the destination mailbox.

The database enforces a unique constraint on
`(marketing_campaign_email_id, identity_type, identity_hash)`. Neither
`marketing_list_member_id` nor `cycle_number` participates in the delivery identity. A new
campaign-email row is a new content identity even when its rendered content matches an older row.

Before claiming, Marketing resolves all keys from current and historical evidence. If several keys
match one existing delivery, that delivery wins. If the keys resolve to more than one existing
delivery, the identity cluster is ambiguous and sending is blocked for review. Missing keys may be
added to the one existing delivery only while uniqueness and historical evidence remain coherent.

### Atomic Claim And SMTP Boundary

`App\Modules\Marketing\Actions\ClaimMarketingCampaignDelivery` owns claim acquisition. The runtime
performs all checks that can safely fail before transmission, including campaign/email state,
content rendering, sender/provider binding, consent, suppression, and quiet-hours eligibility.
It then reserves one stable RFC Message-ID and uses one database transaction to:

1. lock the selected recipient and matching delivery identity keys;
2. reject any existing delivery/key match across all historical cycles;
3. create the delivery and every available identity key atomically;
4. link the canonical recipient, copy the Message-ID, and move it from `pending` to `claimed`.

The identity-key unique constraint is the final concurrency authority. If two workers race, one
transaction wins; the loser re-reads the winning delivery and does not call SMTP. A pending legacy
or duplicate recipient that matches an existing guard is marked `duplicate_skipped` and may link to
the existing delivery without creating another claim.

After claim commit, the sender durably changes the delivery to `provider_write_started` before it
calls `SmtpAccountMailer`, passing the reserved Message-ID. Confirmed provider acceptance changes the
delivery and recipient to `sent`. An exception after the provider-write boundary changes both to
`outcome_unknown` where the process can record that transition. A process crash may leave
`claimed` or `provider_write_started`; both states still consume the identity and are never
automatically reclaimed or replayed.

The delivery state transitions are therefore:

```text
claimed -> provider_write_started -> sent
                                  -> outcome_unknown
```

Recipient statuses add `claimed`, `outcome_unknown`, and `duplicate_skipped` to the existing states.
Only a failure conclusively completed before any delivery claim exists may follow a safe retry path.
Once a ledger/key match exists, no automatic retry, queue redelivery, list refresh, schedule repeat,
or operator action may create a second claim for that campaign email. An operator may investigate or
reconcile an uncertain result, but there is no blind resend action. Intentionally sending again
requires a new `marketing_campaign_email_id`.

### Preflight And Historical Backfill

Migration `2026_08_24_150000_add_marketing_campaign_delivery_invariant.php` adds the ledger, identity
keys, recipient links, indexes, and compatibility statuses. It does not delete recipients, rewrite
tracking evidence, queue work, or send email.

The read-only `marketing:delivery-preflight` command, backed by
`App\Modules\Marketing\Actions\InspectMarketingCampaignDeliveryHistory`, reports campaign/cycle
counts, duplicate identity clusters, pending repeats, and uncertain historical outcomes. It has no
apply mode.

Backfill is additive and idempotent:

- confirmed sent rows create consuming deliveries and all provable identity keys;
- rows with evidence that transmission may have started are conservatively guarded as unresolved;
- only failures proven to precede transmission remain eligible for a safe future attempt;
- pending rows that repeat an already guarded identity become `duplicate_skipped` without SMTP;
- incompatible identity clusters block the deployment/send gate until they can be resolved safely;
- cycle, repeat, completed, tracking, and event history remain unchanged as evidence.

Legacy completed campaigns stay inert until the separate explicit continuation workflow runs.
Rollback may remove unused new structures only before new live claims exist; once claims exist, the
guards must be preserved to avoid making old deliveries sendable again.

## Rationale

- A dedicated ledger separates irreversible delivery truth from replaceable list membership and
  historical campaign cycles.
- Multiple unique identity keys protect a known person after an address or list-member change while
  also preventing two Contacts from receiving the same campaign email at one mailbox.
- Database uniqueness and a claim token make queued-job overlap safe; application-only lookup is not
  sufficient.
- Reserving Message-ID before SMTP gives reconciliation one stable outbound identity.
- Treating uncertain and abandoned claims as consuming follows the user's explicit preference: a
  possible missed email is safer than sending the same campaign email twice.
- Additive backfill preserves engagement and audit evidence.

## Consequences

Positive:

- The no-resend rule survives cycle changes, list refresh, overlapping lists, email casing, job
  redelivery, and concurrent workers.
- Historical rows remain available for tracking and audit.
- Ambiguous provider outcomes are visible and cannot silently advance or resend.
- A newly appended campaign-email record can safely extend an ongoing contact journey.

Negative:

- A crash after claim may leave an email unsent and require operator review.
- Identity resolution and migration are more complex than one recipient-row uniqueness key.
- Contact merges, legacy links, and mailbox changes require identity-key enrichment and ambiguity
  handling rather than destructive reassignment.
- The Marketing send job must use the reserved Message-ID capability of Email transport and cannot
  call SMTP outside the claim state machine.

## Alternatives Considered

- **Keep recipient uniqueness by list member and cycle.** Rejected because list refresh and repeat
  continue to authorize duplicate delivery.
- **Use only normalized email.** Rejected because a Contact can change address and would then receive
  an old campaign email again.
- **Use only Contact ID.** Rejected because legacy/client-user and email-only members exist, and two
  records can target the same mailbox.
- **Add nullable unique columns directly to recipient rows.** Rejected because one delivery may own
  several identity aliases and historical duplicate rows must be preserved.
- **Retry SMTP exceptions with the same Message-ID.** Rejected because Message-ID helps
  reconciliation but does not prove that a provider did not accept the first transmission.
- **Delete or collapse historical cycles.** Rejected because tracking, engagement, and audit evidence
  must remain intact.

## Follow-Up

- Implement and verify the three related Feature Slices in order.
- Run the preflight before migration against backed-up Dev data, review every ambiguous cluster, and
  record sanitized counts.
- Add focused SQLite and MariaDB concurrency/uniqueness tests plus process-interruption tests.
- Document scheduler, queue-worker, migration, rollback, and uncertain-outcome review operations.
- Keep the large update's `docs/human-review.md` entry open until a named human reviewer explicitly
  confirms the required behavior.
