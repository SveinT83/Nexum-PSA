# Automatic Nextcloud calendar synchronization verification - 2026-10-22

Status: Done On Dev; tested Main candidate ready for explicitly authorized merge.
Owner: Codex. RFC and Main merge approved by Svein Tore in chat: Godkjenner. Fix og merge til main.
RFC: ../rfc/2026-10-22-nextcloud-calendar-automatic-sync.md.
ADR: ../adr/2026-10-22-nextcloud-calendar-claims-and-conditional-reconciliation.md.
Human-review checklist: HR-2026-10-22-NEXTCLOUD-CALENDAR-SYNC remains In Review for live pilot.
No real-calendar scenario or completed human review is inferred from automated checks.

## Implemented behavior

- Calendar-only minutely dispatcher honors the connection interval and explicit reviewed activation.
- Durable connection and Calendar leases serialize manual/queued/cross-connection runs; atomic
  job start, fencing tokens, shorter worker timeout and bounded attempts reject stale deliveries.
- Activation fingerprints bind non-secret server/principal/mapping targets; retargeting revokes
  automatic execution. Ordinary settings saves preserve the reviewed activation.
- Calendar-owned mutations preserve internal Work Context, privacy, unsupported workflow ownership
  and same-second local edits. Existing Workday absence guards remain effective.
- Canonical hashes distinguish unchanged, local-only, remote-only and two-sided edits. Legacy
  hashes require conservative reconciliation; unlinked matching copies are never auto-merged.
- Original provider hrefs and conditional ETags are retained. New events have deterministic UUID
  paths and If-None-Match. Persisted intent survives unknown responses; UID/content/ETag read-back
  is required before acknowledgement. Absent bound resources are not blindly recreated.
- UTC instants, private/confidential classes, valid durations and calendar-local all-day boundaries
  are preserved. UTF-8 calendar lines are folded safely. Invalid inventories cannot become empty
  successful reads and trigger writes.
- Recurring provider resources remain retained/skipped rather than flattened. Unsupported
  nonrecurring resources may project read-only; provider-only fields are never overwritten.
  No delete propagation, calendar sharing, invitation sending or scheduled identity/file sync.

## Verification evidence

1. Original baseline Dev Nextcloud suite: 16 passed / 115 assertions.
2. Regression proof against untouched origin/main f086cfb18bb313ca842517d53271564d654787a1:
   all seven new direct transport tests failed for the expected old behavior (conditional writes,
   original resource paths, confidential class, UTC representation, UTF-8 folding, foreign-resource
   refusal and missing-ETag refusal). The ordinary Dev tree was not temporarily reverted.
3. Final authoritative Dev affected suite:
   HOME=/tmp php artisan test app/Modules/Nextcloud/Tests/Feature app/Modules/Calendar/Tests/Feature
   Result: 94 passed / 510 assertions, 53.79 seconds.
4. Same suite on the isolated Main candidate: 94 passed / 510 assertions, 47.12 seconds.
   Candidate has its own copied vendor tree, Composer install/dump-autoload against Main's lock,
   and a test-only environment/key with SQLite :memory:. No Dev/production credentials copied.
5. Scoped Pint and whitespace checks pass. No new package or frontend build is required.
6. Applied only 2026_10_22_180000_create_nextcloud_calendar_sync_states.php on Dev.
   Both additive lease tables read back present and empty. Eligible automatic connections: zero.
   Direct nextcloud:sync-calendars run dispatched zero jobs; no provider calls/events were created.
7. schedule:list registers the named minutely nextcloud.calendars.dispatch_due command.
   Trusted-host LAN HTTPS /up smoke returns 200. Registration is not proof of an actual auto cycle.
8. Authoritative Knowledge sources updated. Supported BookStack connector published pages 420
   and 455, each revision 4. Independent full reads confirm the exact new sections, explicit
   Dev verification/not-production status and retained original content. Page/chapter inheritance
   and book inheritance remain unchanged; staff Editor role 2 has book/chapter/page view-all.
   Search's connector pattern rejected a multi-character query; page listing and exact editing
   provided the supported alternative. Employee OpenWebUI retrieval was not tested.

## Scoped files and preservation

Implementation includes the thin SyncNextcloudCalendars command, Calendar ApplyExternalCalendarEvent,
Nextcloud DispatchDueNextcloudCalendars/NextcloudCalendarLease/RunNextcloudCalendarSync/
SyncMappedNextcloudCalendars actions, SyncNextcloudCalendar job, existing RequestNextcloudSync,
StoreNextcloudConnection and NextcloudReadClient, two new test classes, adjusted existing read-back
test, one migration, the console schedule append and relevant Knowledge/README/process records.
Shared TODO/human-review and Calendar Knowledge changes are extracted as this scope's sections;
unrelated dirty Dev content is not copied into the Main candidate. Original Nextcloud files before
this work match origin/main exactly. Ordinary Dev HEAD/index are pinned separately for final read-back.
No ordinary Dev commit, index staging, branch switch or production application/config/event write.

## Deployment and recovery (not executed in production)

1. Obtain the separately authorized production release and review the relevant human-review entry.
2. Back up application/database and deploy the scoped merged code.
3. Apply only the new additive migration before using manual or automatic calendar sync:
   php artisan migrate --path=database/migrations/2026_10_22_180000_create_nextcloud_calendar_sync_states.php --force
4. Refresh affected PHP/opcache/long-running queue workers through the normal deployment path.
   Keep the existing minute scheduler and worker; do not enable unrelated jobs or create another
   global scheduler. Verify actual execution, not only schedule:list.
5. Revalidate the approved work-calendar mapping and five historical duplicate candidates.
   Preserve Personlig and other users' mappings/data; do not export their existing bound events.
6. After explicit production activation authorization, use the installed runtime:
   php artisan nextcloud:sync-calendars --enable --connection=1 --mapping=1
   This configuration command does not start a sync. It changes only the explicit automatic scope.
7. Verify Overhalla at 23 October 12:00-13:00 Europe/Oslo in both systems, one controlled outbound
   change/read-back, two real automatic cycles with stable identities/counts, and private scope.
   Unlinked duplicate candidates and unsupported recurrence stay retained for operator review.
8. Pause with php artisan nextcloud:sync-calendars --disable --connection=1 before recovery.
   Drain or wait out active attempts; retain event identities, pending intent and conflict logs.
   Rolling back code must not delete synchronized events. Schema removal is unnecessary for pause.

No Main merge implies production deployment or completed real-provider acceptance. Live pilot,
duplicate decisions, production automatic activation and Svein's manual checklist remain pending.
