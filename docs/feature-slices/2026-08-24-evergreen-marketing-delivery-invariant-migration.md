# Feature Slice: Evergreen Marketing Delivery Invariant And Migration

Status: Done / Human Review Pending
Date: 2026-08-24
Level: 3
Parent: `../rfc/2026-08-24-evergreen-marketing-contact-sequences.md` (Slice 1)
Owner: Svein Tore Ramstad / Codex
Related ADR: `../adr/2026-08-24-marketing-at-most-once-delivery-identity-claims.md`

## Goal

Create the durable, additive Marketing delivery identity and historical migration foundation that
prevents one campaign-email record from being claimed more than once for the same person or
normalized destination mailbox.

## User-Visible Behavior

This slice sends no email and does not change campaign scheduling. Its observable result is a safe
deployment gate: operators can inspect legacy repeat history and ambiguous deliveries before
Marketing resumes, while later runtime slices have one database-backed no-resend authority.

Existing campaign recipients, cycles, tracking, and events remain visible. Pending rows that would
repeat a guarded delivery are classified as `duplicate_skipped`, not transmitted or deleted.

## Scope

- Add `marketing_campaign_deliveries` and `marketing_campaign_delivery_identity_keys` according to
  the accepted ADR.
- Add the recipient delivery link, claim/outcome timestamps, compatibility statuses, and indexes.
- Implement `ClaimMarketingCampaignDelivery` with atomic identity-key acquisition, stable
  Message-ID reservation, and one concurrency winner.
- Implement the read-only `marketing:delivery-preflight` command and
  `InspectMarketingCampaignDeliveryHistory` action.
- Backfill confirmed sends and conservative unresolved/ambiguous historical attempts without
  deleting or rewriting source evidence.
- Mark pending historical duplicates `duplicate_skipped` and link them to the existing guard where
  safe.
- Keep cycle and list-member identifiers as history only; they never authorize another claim.
- Make migration/backfill bounded, idempotent, and incapable of queueing or sending email.

## Out Of Scope

- Selecting the next contact step or calculating future campaign occurrences.
- Calling SMTP or wiring the due-send job to the provider-write state transitions.
- Changing campaign completion/repeat lifecycle behavior.
- Technician UI, API compatibility, derived counts, campaign continuation, or blind-resend tooling.
- Automatically reactivating legacy completed campaigns.
- Deleting duplicate historical sends or tracking events.

## Data Touched

- New `marketing_campaign_deliveries` table.
- New `marketing_campaign_delivery_identity_keys` table.
- Additive fields and statuses on `marketing_campaign_recipients`.
- Read-only inspection of `marketing_campaigns`, `marketing_campaign_emails`, recipients, list-member
  identity evidence, tracking/events, and relevant Email logs.
- Migration:
  `database/migrations/2026_08_24_150000_add_marketing_campaign_delivery_invariant.php`.

The migration must not mutate campaign approval/status, create recipients, enqueue jobs, or access
SMTP.

## Permissions

No technician, admin, API, or Contact permission changes. The preflight is an operator-only Artisan
command. Existing Marketing route guards and API abilities remain unchanged.

## Tests

- Schema, foreign-key, index, and model-cast coverage for both new tables and recipient links.
- Database uniqueness for Contact, legacy client user, and case-normalized email identities.
- A list refresh, changed list-member ID, overlapping lists, and historical cycle do not acquire a
  second delivery.
- Two concurrent claims yield one delivery, one identity-key set, and one winning claim token on the
  real MariaDB uniqueness path.
- Message-ID and claim token remain stable and unique.
- Preflight is read-only and reports sanitized campaign, duplicate, pending-repeat, and unresolved
  counts.
- Backfill is idempotent, preserves every source row/event, guards confirmed or possibly transmitted
  history, and skips only unsafe pending duplicates.
- Ambiguous multi-delivery identity clusters fail closed.
- Migration/backfill dispatches no jobs and makes no SMTP call.
- Rollback refuses or preserves live guard evidence according to the ADR boundary.

## Documentation

- Accepted at-most-once delivery identity/claim ADR.
- This Feature Slice document.
- Exact preflight, backup, migration, readback, and rollback commands are recorded in the final
  Marketing operational documentation and human-review entry in Slice 3.

## Completion Evidence And Remaining Gates

- The complete isolated Marketing suite passed on authoritative Dev with 65 tests and 802
  assertions under an explicit SQLite `:memory:` test configuration.
- The read-only Dev preflight found no ambiguous identity splits, consumed rows without stable
  identity, or uncertain outcomes before migration.
- The additive migration completed on authoritative Dev MariaDB after the overlong index-name defect
  was corrected. Readback preserved the campaign fingerprint and historical sent evidence, created
  one sent ledger with three stable keys, and converted one matching pending replay to
  `duplicate_skipped` without queue or provider activity.
- This implementation/data slice is complete. Three active `email,default` queue workers with
  `/var/Projects/tdPSA` as their working directory verify default-queue capacity for Marketing. No
  tdPSA `schedule:run` or `schedule:work` runner was found in accessible cron, systemd, or process
  sources, and the root crontab was unavailable, so scheduler dispatch remains unverified. Named
  review remains open under `HR-2026-08-24-002`.

## Done Criteria

- [x] The additive migration and models implement the accepted ledger/key schema.
- [x] Identity normalization and database uniqueness paths are covered by focused tests and Dev
  MariaDB migration/readback evidence.
- [x] Claim acquisition is atomic and cannot use cycle or list-member identity to resend.
- [x] The read-only preflight produces sanitized, reviewable counts and has no apply/send path.
- [x] Historical backfill is idempotent, evidence-preserving, and queues no work.
- [x] Pending duplicates become `duplicate_skipped` without SMTP.
- [x] Ambiguous clusters block safely instead of selecting a delivery by guess.
- [x] Focused SQLite verification and authoritative Dev MariaDB migration/readback pass.
- [x] `git diff --check` passes for the slice changes.
