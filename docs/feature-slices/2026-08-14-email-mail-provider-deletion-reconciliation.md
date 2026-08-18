# Feature Slice: Email Mail Provider Deletion Reconciliation

Status: Done
Date: 2026-08-14
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Depends on: remote-operation recovery, retention safety, and durable Smart Inbox artifacts
Owner: Svein / Codex

## Goal

Detect provider-side deletion or moves without guessing, project confirmed placement loss, and
remove unretained Mail-derived content only after a bounded grace period while preserving separately
captured Ticket evidence.

## User-Visible Behavior

Normal Mail users no longer keep seeing a placement after Nexum has safely confirmed that it
disappeared at the provider. Reappearance during grace restores it. Administrators control the
destructive local-cache lifecycle through a clearly warned setting that remains off by default;
provider permanent delete is not added.

## Scope

- Compare a bounded provider folder inventory with local active placements.
- Fail closed on UIDVALIDITY changes, incomplete scans, connection failures, or ambiguous moves.
- Mark a placement missing only after confirmation and distinguish a move when stable provider
  evidence proves the target placement.
- Refresh durable conversations after placement changes.
- After grace/retention, remove Mail payload and derived Smart Inbox/AI artifacts only when no
  provider placement survives and retention eligibility permits it.
- Preserve Ticket-owned evidence and minimal deletion/provenance audit facts.

## Out Of Scope

- New search/index/provider/scanner packages, permanent provider delete, legal-hold/DSAR product
  completion, hidden backup archives, or cross-account matching.

## Data Touched

- Provider inventory runs/folders, immutable deletion findings, hidden placement tombstones, and
  cleanup-attempt records added by migration `115000`.
- Existing provider folders/placements/conversations, retention protection, local Email files/tags,
  Smart Inbox artifacts, and Ticket evidence references are rechecked during reconciliation/cleanup.

## Permissions

There is no user-triggered delete action. Only account-bound scheduled jobs may scan/clean, and each
execution rechecks the exact Admin opt-in plus active account and stable provider evidence. The Admin
setting uses existing Email account-management authority; it does not grant mailbox content access.

## Tests

- External delete and move, incomplete inventory, UID reset, races, and multi-placement survivor.
- Conversation aggregate refresh and account isolation.
- Ticket evidence survives while unretained Mail cache/AI artifacts expire.
- Repeated reconciliation and cleanup are idempotent.

## Done Criteria

- [x] Provider absence is confirmed rather than inferred from one failed fetch.
- [x] Ambiguity never removes an active placement or Mail payload.
- [x] Derived artifacts follow the same bounded lifecycle as the Mail source.
- [x] Focused sync/retention/Smart Inbox tests pass on Dev.

## Documentation

Update Email Knowledge, the module README, `docs/TODO.md`, and `docs/human-review.md`. Keep the
default-off rollout and required queue/scheduler checks explicit.

## Implementation Notes

- Stable, bounded folder inventories compare full UID sets only when start/end `UIDVALIDITY`,
  `UIDNEXT`, and message counts agree. Incomplete folders, projection drift, provider errors, scan
  limits, and concurrent placement changes block reconciliation.
- Confirmed source loss creates an immutable finding and a hidden placement tombstone for a
  seven-day grace period. Exact provider reappearance restores that placement and cancels its old
  cleanup path; a conservatively fingerprinted, already-projected target distinguishes a move.
- Cleanup deletes terminal tombstones only after grace, then rechecks surviving placements and the
  central retention assessment under lock. Ticket-owned evidence remains separate, while eligible
  Mail payloads, local files, tags, and source-derived Smart Inbox artifacts are removed
  idempotently.
- Inventory, findings, and cleanup attempts retain bounded identifiers/fingerprints and status
  facts, never raw provider payload, message content, headers, addresses, attachment names, or
  credentials.
- Scheduler entries dispatch reconciliation daily at `04:00` and cleanup at `05:00`, but every job
  fails closed unless the Admin `provider_deletion_reconciliation_enabled` setting is exactly `1`.
  The default remains off pending controlled Dev review.

## Verification

- Current focused provider-deletion coverage: **13 tests / 129 assertions**.
- Provider deletion plus the earlier retention, conversation identity, remote recovery, and Smart
  Inbox regression set: **41 tests / 323 assertions**.
- PHP syntax, Pint, migration pretend, and whitespace checks passed for the isolated implementation.
- Migration `2026_08_14_115000_add_email_provider_deletion_reconciliation.php` ran on Dev in batch
  93 after the ordered `113000` Undo (batch 91) and `114000` Smart Inbox (batch 92) migrations.
  The four lifecycle tables started empty and the explicit provider-reconciliation opt-in remains
  disabled.
- Human review: `HR-2026-08-14-015` (`Pending`). Keep the opt-in disabled until that review validates
  a controlled mailbox inventory, confirmed move/delete/reappearance, grace behavior, retention,
  Ticket evidence, and account isolation.
