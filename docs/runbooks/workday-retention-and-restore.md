# Workday Retention And Restore

Status: Implemented on Dev; activation and production use require HR-2026-10-01-WORKDAY and Svein's rollout approval.
This runbook implements the approved Workday product policy. It does not certify backup-provider configuration.

## Deadlines and authority

Workdays expire at midnight after the third calendar anniversary of the original work date,
in the original timezone. Absence uses the last included local date of the original period.
29 February maps to 28 February before adding the next midnight. Corrections, cancellation,
snooze, source refresh and receipt replay never extend the stored deadline. At the exact
deadline, reads/writes/replays and Calendar projection reads are denied.

WORKDAY_ENABLED and the stored workflow setting control employee access. The separate
WORKDAY_RETENTION_ENABLED switch controls cleanup and defaults to false. Once approved,
retention continues even if the employee workflow is disabled. Do not disable retention
implicitly as a rollback shortcut.

The command is a trusted local scheduler operation under the normal application service
account. It never impersonates an employee. There is no HTTP/MCP purge operation.
Administrators with workday.manage_settings can preview counts at Workday settings.
API POST /api/v1/workday-settings/retention-preview additionally requires workdays.settings;
it accepts no worker, cutoff, date, policy or execution override.

## Copy inventory

| Storage | Treatment |
| --- | --- |
| workdays, workday_revisions | Physically remove expired roots and all revisions; clear revision pointers first. |
| workday_previews, workday_source_allocations, workday_task_conversion_previews | Remove owned snapshots, reservation ranges and retained conversion results before restrictive revision FKs. Preview usability of 30 minutes does not extend retention. |
| workday_mutation_receipts | Remove with the day; expired settings/detached receipts are also cleaned. |
| workday_absences, revisions, receipts | Remove the original-expiry root and every revision/receipt. |
| Calendar event/link for an absence | Calendar-owned action verifies exclusive ownership, personal calendar, exact source link and absence of independent relationships. Physically removes the projection/link inside the source transaction. Mismatch rolls back that root. |
| workday_reminders, workday_reminder_deliveries | Remove every generation/channel at original work-date expiry, including pending/attempting receipts. |
| notifications | Remove all reminder generations by stable source UUID or legacy landing URL; current copies contain original expiry. Expired/orphaned current copies are removable after partial restore. Untracked legacy copies block restore readiness until reconciled; do not guess their deadline. |
| Telescope entries/tags | Workday request/SQL/job entries are excluded from new collection. Existing Workday-marked diagnostic batches are removed in bounded batches; FK cascades remove tags. Diagnostic copies have no retention purpose and need not wait three years. |
| failed_jobs | Workday reminder failures are redacted at the job boundary; historical copies are removed by exact job displayName. Other job failures are preserved. |
| Queue payload | Contains only deliveryId. Missing/expired receipt is a no-op, including removal between external claim and send. No work description or absence reason is queued. |
| Session / HTTP cache | Workday form validation renders in the current request without flashing input. Workday responses use private/no-store. No Workday personal cache or generated server export store is implemented. |
| Reports / API | Confirmed report reads retained source rows; there is no Workday DataExchange export provider or persisted report file. API consumers own any copies they deliberately save. |
| Task, TaskTimeEntry, Ticket, independent Calendar | Preserve originals, including explicitly created internal Tasks and actual source time. Their own policies apply. No billing cleanup or Tripletex operation is called. |
| User profile, current recurring plan, preferences, discovery cursor | Preserve. These are current configuration, not expired Workday history. |
| Mail, Web Push, browser/client downloads | Generic notices contain no work text/absence reason. Delivered remote copies cannot be recalled by server cleanup. Recipient/device/provider policies apply. |
| Log archives, old sessions/caches, backups | See restore procedure. Application preview cannot certify filesystem archives or infrastructure retention. |

Cleanup outputs contain counts and fixed reason codes only. No identities, dates, descriptions,
categories, SQL bindings or exception messages are printed. Keep operational command summaries
for at most 30 days; they are not a second employee audit/history store. Configure any supervisor,
cron or log collector to the same limit before activation.

## Preview and bounded execution

~~~bash
php artisan workday:retention
php artisan workday:retention --restore-check
~~~

Preview makes no changes. restore-check exits nonzero while expired roots, orphaned copies,
diagnostic copies or untracked notification copies remain. Counts can overlap; one reminder
may also count as a notification copy. A zero inventory is only one restore prerequisite.

After explicit activation approval and configuration/cache reload:

~~~bash
php artisan workday:retention --execute --limit=100
~~~

Limit is 1-200 root/copy attempts; a root and all its children are one atomic transaction.
The employee row is locked before the root, matching edit/confirmation/conversion/delivery.
A failed root rolls back; other selected roots continue. Run again to resume an interrupted batch.
Do not automatically loop through a blocked root: inspect the fixed reason code and reconcile
ownership on the closed system. No force-delete or broad Calendar cleanup option exists.

Exit 1 means disabled execution, blocked cleanup or unavailable inventory; exit 2 means invalid
options. Preview is the default. Combining execute and restore-check is rejected.

The schedule registers workday:retention --execute --limit=100 every five minutes, with a
ten-minute overlap guard and its independent activation predicate. Verify the actual external
minute runner (OS cron/Plesk/systemd), not only schedule:list. Check worker reload and the next
observed retention result after approved activation. No queue or frontend build is required
for cleanup itself; reload existing workers for the reminder/privacy changes.

## Restore before reopening

1. Keep web/API access, external scheduler and queue workers stopped. Restore to an isolated
   environment with outbound mail/push/provider writes disabled. WORKDAY_ENABLED stays false.
2. Restore a consistent database with FKs, the complete Workday schema and compatible code.
   Do not replay old queue attempts. In particular, attempting external deliveries remain
   ambiguous and must never be automatically reset to pending.
3. Remove restored session/cache/browser-proxy caches and reconcile diagnostic/log archives
   using the installation's approved storage procedure. Old archive formats may lack Workday
   provenance; do not infer that a zero database count proves those archives clean.
4. Inspect the counts with workday:retention. Resolve any unsupported diagnostic store or
   untracked notification provenance. Validate Calendar ownership mismatches individually.
5. Under the separately approved retention setting, execute bounded batches. Stop on failure;
   resume only after resolving the reported cause. Do not restore stale ownership links merely
   to force a purge.
6. Run workday:retention --restore-check until successful, then verify synthetic expired IDs are
   unreadable and original Task/Ticket/Calendar records plus current plans remain unchanged.
7. Record only counts, build identifier, completion timestamp and operator. Verify current
   preferences, scopes, scheduler, worker code and external delivery-claim state before
   reopening. Employee workflow activation remains a separate decision.

The Slice 08 automated rehearsal snapshots synthetic SQLite rows, purges, restores those rows,
verifies the closed restore gate, then purges again. It does not restore or purge Dev/production
business data and does not prove a real backup can be restored.

## Backup and external-storage release checks

The infrastructure owner must identify all database/filesystem/provider backup destinations,
encryption/access controls, a finite rotation window, and deletion of superseded snapshots.
Backups do not authorize reopening expired employee data. Every restore must use the cleanup
gate above before access or workers resume. Confirm how expired content is isolated until old
backup media rotates; record the actual backup window and its approved handling before rollout.

This repository does not manage those external backup policies. No live backup rotation,
archive deletion or disaster-recovery rehearsal was authorized/performed by Slice 08.
HR-2026-10-01-WORKDAY and Slice 09 must retain these checks; do not describe production
retention as verified on the strength of passing application tests.

## Recovery and rollback

Disabling employee access stops writes/prompts, while approved retention stays independently
active. Purge is irreversible in the active database: use a reviewed isolated restore to investigate,
and apply expiry before reopening. Never run destructive down migrations or restore expired
rows to extend their lifetime. Integrity failures leave the complete source root available only
to the closed-system operator; ordinary reads remain expired.
