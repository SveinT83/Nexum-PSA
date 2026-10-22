# Nextcloud calendar synchronization diagnosis - 2026-10-22

Status: Read-only investigation complete; RFC draft awaiting approval. No application code,
production configuration, mapping, event, credential or provider data was changed.
Owner: Codex. Decision/release owner: Svein Tore.
RFC: ../rfc/2026-10-22-nextcloud-calendar-automatic-sync.md.
Human review: HR-2026-10-22-NEXTCLOUD-CALENDAR-SYNC (planned; not reviewed).

## Verified evidence

- Authoritative working copy /var/Projects/tdPSA is on Dev with broad existing dirty changes.
  Read remote AGENTS.md, team process, module architecture, TODO, RFC/slice process, relevant
  human-review state and Nextcloud/Calendar source. No existing approved calendar-sync RFC found.
- Windows sandbox process launch initially failed CryptUnprotectData before SSH. The approved
  dedicated key with IdentitiesOnly/BatchMode/StrictHostKeyChecking succeeded outside that sandbox.
- Production connection 1 is active, sync mode, 15-minute interval; last successful/requested sync
  remains 2026-05-28. Production registered Schedule contains zero Nextcloud tasks. Dev code has
  no Nextcloud scheduler/command/job; module README explicitly lists real background sync missing.
- Host-key-verified production SSH ran read-only Python and Laravel probes. Subscription crontab
  has exact minutely /opt/plesk/php/8.3/bin/php calls to the portal artisan queue:work and schedule:run.
  This proves installation, not actual successful scheduler execution. No Nextcloud-specific cron
  entry was found. System scan read 249 files; two unrelated /etc/cron.d files were denied, so this
  is not a claim that every system account's scheduler was readable.
- Active mappings 1 Svein Tore and 2 Personlig both target local calendar 2, owned by user 1;
  calendar 2 contains 35 events without external_source and 96 marked Nextcloud. Mappings 3/4 have
  no calendar_id and have never synced. No changes to any mapping.
- Using the production Nextcloud read client, mapping 1 returned 95 events within the current
  one-year-back/two-year-forward window: five exact title/start/end duplicate candidates against
  unlinked local events, nine RRULE events, one without parsed DTEND. Mapping 2 returned 16,
  including one RRULE and no unlinked exact title/time candidates. These are candidates, not
  approved duplicate merges; other recurrence/exception equivalence was not assessed.
- Target Nextcloud-demo - Overhalla kommune is present remotely at 2026-10-23 10:00-11:00 UTC,
  corresponding to 12:00-13:00 Europe/Oslo. The coordinator previously verified the bounded Nexum
  API returned no events for calendar 2 on 22-25 October; that API result was not repeated here.
- Source inspection: push query does not isolate remote calendar provenance; writer reconstructs
  href from UID and has no conditional write; conflict compares incompatible local/remote hashes;
  importer mutates Calendar models directly rather than Calendar actions. Protect Workday absence
  exclusions already present in the shared dirty files.

## Baseline verification

On authoritative Dev: umask 0002; HOME=/tmp php artisan test
app/Modules/Nextcloud/Tests/Feature/NextcloudModuleTest.php.
Result: 16 passed, 115 assertions, 39.92 seconds. Isolated SQLite :memory: test configuration;
no real Nextcloud writes. These existing tests do not prove the proposed fix or live sync.

## Next action and gates

Svein approves the RFC and initial mapping/privacy scope. Codex then implements/tests slices A/B
on Dev, preserves dirty work, and prepares review/deployment steps. Main/production authorization,
activation, duplicate reconciliation and actual bilateral read-back remain separate. No deploy
command is currently required because this session changed planning documents only. Handbook
publication is deferred until verified behavior exists; no claim of completed BookStack update.
