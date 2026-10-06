# Feature Slice 08: Three-Year Retention And Recovery Operations

Status: Done On Dev; preview available in Dev pilot, cleanup disabled (2026-10-03).
Date: 2026-10-01
Owner: Codex; product/reviewer: Svein Tore
Parent: [Workday RFC](../rfc/2026-10-01-daily-workday-confirmation.md)
Delivery contract: [Implementation plan](../plans/2026-10-01-workday-implementation-plan.md)
Human review: [HR-2026-10-01-WORKDAY](../human-review.md) - Pending; complete pilot not yet ready.
Dependencies: All persisted data and copy inventories from Slices 01-07.

## Goal

Implement the agreed three-year lifecycle without leaving hidden retained copies or damaging source records.

## User-Visible Behavior

Administrators can inspect the three-year policy and a metadata-only expiry preview. Expired Workday records are removed through a bounded scheduled process after the approved activation.

## Scope

- Inventory Workday rows, revisions, source snapshots, mutation/reminder receipts, Calendar projections, notifications, caches, exports, logs and backup restore paths.
- Slice 04 adds workday_source_allocations with a restrictive revision foreign key. Purge these owned reservation rows before expired revisions, and cover them in restore/expiry fixtures; preserve Task/Ticket/Calendar sources.
- Slice 06 adds workday_reminders, workday_reminder_deliveries and canonical Notification copies. Delete all generations and linked notifications by the original work-date expiry; snooze and submission never extend it. Queue payloads contain only deliveryId and must no-op after the receipt is purged. Preserve the non-personal discovery cursor; never replay ambiguous external claims after restore.
- Slice 07 adds workday_task_conversion_previews: exact activity/target payload, retained creation result and consumed range evidence. Delete these before revisions (restrictive revision foreign key), plus conversion mutation receipts. retained_until is the original day expiry; preview expires_at only controls its 30-minute usability. Preserve the created Task, TaskTimeEntry and Task activities as source-domain records. Their opaque conversion UUID has no Workday description after Workday cleanup. Include adjacent-date duplicate prevention and conversion/purge lock coordination in fixtures.
- Anchor expiry to work date or absence-period end in the record timezone; corrections, replay and source refresh cannot restart it. Both configured retention values start at three years.
- Implement a read-only preview and bounded/idempotent purge command. Define deterministic leap-date cutoff; keep locks short and coordinate confirmation/edit/purge races.
- Delete Workday-owned copies and neutral Calendar projection/link records through domain-owned cleanup actions. Preserve original Task/Ticket time, independent Calendar events, user profiles and external Tripletex entries.
- Prevent replay receipts, raw audit payloads and queued jobs from retaining expired personal content. Keep only minimal operational cleanup metadata with its own stated lifecycle.
- Keep current recurring plans available. Document backup rotation and a restore cleanup step before reopening the application, with verification of expired-record absence.
- Disabling Workday stops writes/prompts/imports; retention remains an explicit operational policy so feature disablement cannot silently retain records forever.

## Out Of Scope

Purging production data during development, source-domain cleanup, deleting Tripletex records, speculative archival services and treating soft deletion alone as expiry.

## Data Touched

All Workday-owned personal records/copies; existing scheduler/queue and Calendar cleanup path. No destructive down-migration.
Names for new storage/actions/routes are implementation proposals, not claims of existing tables.
Confirm exact migrations and existing state on authoritative Dev before runtime changes.

## Permissions

workday.manage_settings for retention settings/preview; purge runs as the documented internal scheduler actor with bounded authority, metadata audit and no employee impersonation. Changing policy/activating cleanup follows the reviewed deploy plan.

## Tests

- One instant before/at/after cutoff, leap day, timezone and long absence boundaries.
- Revision/retry does not extend lifetime; valid records remain; expired owned copies/history disappear.
- Concurrent edit/confirmation/purge and interrupted batch recovery are consistent and repeatable.
- Original Task/Ticket/Calendar/Tripletex data and active plans are unchanged.
- Restore rehearsal removes expired active data before reopening; no raw content leaks in cleanup logs.
- Run the narrow affected Laravel suites on Dev with synthetic fixtures; no local PHP fallback.
- Inspect authenticated UI/API/HTTP read-back for the implemented behavior; an unauthenticated login
  redirect does not prove the feature works.

## Documentation

Create Workday retention/restore runbook and operators' scheduler verification; extend the human-review checklist with synthetic expiry checks.
Update this slice and the parent TODO row in the same session as verification or a concrete blocker.

## Done Criteria

Synthetic preview/purge/restore tests pass with a complete owned-copy inventory. Production deletion remains gated by named human review and deployment approval.
Record changed files, exact tests/results, migrations/commands, HTTP/UI/API evidence and remaining
human checks. Passing automated tests never marks human review complete. Keep production runtime
off until the required review and separate rollout approval. Do not leave visible stubs for later slices.

## Delivery evidence (2026-10-02)

See [Slice 08 verification](../plans/2026-10-02-workday-slice-08-verification.md) and the
[retention/restore runbook](../runbooks/workday-retention-and-restore.md). Application scope is
implemented/tested: 228 distinct tests pass after the expected reminder-metadata contract update.
No production or live Dev purge was run; no migration is needed. Retention and employee switches
remain off. The live preview finds no Workday roots and 105 historical diagnostic copies; the
restore gate remains closed until approved cleanup. Backup rotation and archived copy handling
are infrastructure checks retained for Slice 09 and human rollout review.
