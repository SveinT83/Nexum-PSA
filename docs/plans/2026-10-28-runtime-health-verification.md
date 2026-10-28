# Issue 262: Shared Runtime Verification

Date: 2026-10-28 (implementation began 2026-10-27)
Authority: Dev /var/Projects/tdPSA; branch Dev; shared contributor changes preserved.
Approval: Svein Tore, 2026-10-27: Ja godkjenner, responding to the exact runtime-health RFC.
Human-review checklist: HR-2026-10-27-OPS-RULES-COORDINATOR remains In Review.

## Result

The approved health contract, duplicate boundary repair and controlled Dev reconciliation are
implemented and verified. Issue #262 remains open for the explicit remaining cohort/human acceptance
criteria. Healthy active components do not make the whole runtime healthy: economy, email-live and
notifications remain operationally blocked, and the Email domain still reports historical failures.
No history was retried, purged or suppressed.

## Implemented Behavior

System owns a durable scheduler row and one bounded probe row per explicit queue identity.
Read-only CLI JSON reports healthy/stale/blocked/unknown, thresholds, timestamps, queue latency,
reason codes and explicit non-verification of business delivery. It never publishes or executes work.
Missing schema/configuration/evidence, unsupported/synchronous backends and ambiguous enqueue do
not become healthy. Outstanding probes never multiply; old/wrong/duplicate completions cannot
refresh new evidence. Reconciliation requires the current queue/UUID plus explicit non-delivery
attestation and never claims completion or directly publishes a replacement.

A repeated scheduled Ticket occurrence now locks its parent and reuses the exact existing identity.
Cloned internal notes, attachments and Task groups cannot be duplicated by delivery retry. Deleted
occurrences stay deleted; one-time activation also rechecks locked due state.

## Data And Runtime Changes

Only these additive Dev migrations ran:
- 2026_10_27_180000_create_system_runtime_heartbeats, batch 45.
- 2026_10_27_181000_add_queue_latency_to_runtime_heartbeats, batch 46.
The second is forward-only relative to the already-applied first migration; no applied history
was rewritten. No business-table backfill or historical job mutation was run.

Privileged root/current-user cron, unit files and process-cwd inspection identified one shared
Nexum runner. Original direct Email plus five Supplier triggers were redundant; they were removed
after the domain/health tests passed. Current roles are separate default, Email and Supplier
consumers plus the shared scheduler. No new business queue was introduced.
Current process environment read-back confirms retry_after 2100 for default/email and 300 for
Supplier, with umask 0002. The former exceeds the 1800-second default job maximum; the latter
exceeds Supplier's 240 seconds. Workers naturally restarted on their bounded cron cycle.

The first live default canary waited 545 seconds because email,default always prioritized Email.
It correctly remained stale after late completion. Current-cohort review showed no campaign,
Ticket schedule or Signal webhook outbox rows, disabled CloudFactory, and no BookStack integration.
Splitting the already consumed queues removed starvation; a later default canary completed in
one second. Email and Supplier also completed real probes on their existing connections.
This is queue runtime evidence, not proof of mail delivery or historical processing.

The current Dev profile requires default, email, supplier-orders, economy, email-live and
notifications; probes are allowed only on the first three. The last three remain explicitly
blocked. Deployment defaults remain disabled with no implicit queue allowlist.
An isolated acceptance-queue worker consumed only the one owned probe; its queue was empty before
and afterwards. Actual Ticket concurrency fixtures were also fully removed; sequence numbers were
not rewound.

A protected pre-cutover crontab, post-first-cutover crontab, exact candidates and SHA-256 read-back
were saved. No credential or environment-file backup was created: only the health keys' prior
absence was recorded. Root logrotate policy covers scheduler, default, Email and Supplier logs:
daily, maxsize 20M, rotate 14, compressed, copytruncate, existing user/group ownership preserved.
Debug validation passed; the installed OS timer is active. No forced log rotation was requested.

## Verification

- Affected domain/runtime matrix: 117 passed / 817 assertions.
- System API/browser regression and contract matrix: 29 passed / 885 assertions.
- Final health-contract matrix: 15 passed / 96 assertions.
The first matrix includes earlier health tests; totals overlap and must not be added.
The scheduled-occurrence regression failed before the fix and passes afterwards.
Native MySQL concurrent publishers produced one queued probe; one scoped worker completed it.
Two independent native occurrence creators returned the same Ticket and one cloned note, followed
by exact fixture cleanup.
Owned PHP syntax/Pint, XML parsing and scoped whitespace checks pass. Trusted HTTPS /up returned
200 with certificate validation enabled. CLI and web/PHP-FPM load the new provider/classes.
No full application test suite was run: focused affected/domain/API matrices were used on the
heavily shared Dev server. Actual manual human acceptance is not inferred from automated checks.

External cron evidence after the existing 2026-10-25 reboot includes independent System scheduler
timestamps at 22:53:18, 22:55:12, 22:57:10, 23:00:51 and 23:04:19 UTC on 2026-10-27.
No manual heartbeat was invoked with the live dev runtime key. Repeated changed live probe IDs
and completion timestamps independently confirm workers, rather than schedule registration alone.

## Known Cohorts And Remaining Acceptance

Snapshot 2026-10-27T22:55:45Z: email-live 74,798; notifications 69,189; their old pending rows date
from 2026-09-03. They remain unconsumed by this work. Counts are moving observations, not an
exact transactionally consistent total.

The five failed rows observed before cutover remain preserved:
- One old ProcessScheduledTickets PDO missing-ticket_schedules failure, 2026-09-03.
- Three EmailAccountHealthCheckJob provider_work_locked failures on 2026-10-27.
- One FetchImapAccount MaxAttemptsExceeded failure on 2026-10-27.
Active account, ingest, scheduler poll, last fetch and account-error signals were OK before/after.
The Email Queue worker aggregate remains error because of these records. Supplier Automation
remains intentionally off; its existing scheduler/worker heartbeats stay healthy.
No provider lock, authentication/TLS guard or data-authority boundary was weakened.

Next owner: Svein/runtime and Email owners review the exact failed cohorts and decide bounded
recovery/disposition; approve ownership and side effects before starting any cold-queue worker.
The prepared stop/reconciliation/rollback guide and human checklist must be reviewed before
autonomous activation. Nothing here authorizes a production runtime change.

## Handbook And Other Issues

Authoritative System and Ticket source guides were updated first. Actual BookStack page 367
revision 11 and page 424 revision 34 were read back exactly; existing employee access is retained.
Employee OpenWebUI retrieval remains untested, a separate claim.

Issue #285's Dev UI fixes and pending production activation remain separate. This RFC approval did
not mark that UI review complete or authorize Rules production activation. Issue #290 still needs
the actual receiving service/provider/model/mode and review facts. No token was issued or replaced.
No commit, push, Main merge or production action was performed.

## Final Follow-Up

The Email lock-contention regression failed before the fix. New checks defer only
provider_work_locked for 30 seconds, at most 20 attempts; non-contention exceptions retain one
exception attempt, and every run revalidates the provider binding. No false check/failure is
recorded while deferred. Existing serialized jobs and failed records are not silently upgraded,
retried or deleted. Final Email deadline/polling matrix: 16 passed / 117 assertions.
The read-only health CLI now exposes its opaque pending/completed UUIDs for supported exact
reconciliation; final scoped CLI recovery regression passes 1 / 14. Totals overlap.

Final live read-back at 2026-10-27T23:16:35Z showed fresh scheduler plus default, Email and Supplier
completion (queue latency 1/0/0 seconds). Current source aggregate counts at 23:16:24Z were one
ordinary ready job each on default and Email; the cold queues remained untouched at 74,814
email-live / 69,203 notifications. Failed counts were 9 default and 1 Email; these preserved
cohorts remain a manual disposition gate. Email's rich queue summary stays error and was not hidden.

18 owned PHP files pass syntax lint; XML parses. Source formatting/whitespace are checked on the
owned scope without formatting unrelated shared files. Protected durable rollback material is
/home/sveintore/.local/state/nexum-runtime/20261027-issue262 (mode 0700, contained files 0600).
No full environment, plaintext credential or job-payload backup was created.

Source documentation and actual BookStack 367 revision 11 / 424 revision 34 are verified. Existing
staff-internal Editor read access and inheritance are unchanged. OpenWebUI employee retrieval is
not claimed. The companion JSON contains sanitized read-back and native/test evidence.

GitHub Issue #262 body and comment 6456083329 were independently read back after publication.
Only proven acceptance items were checked; closure still requires the remaining cohort,
duplicate-path and human acceptance evidence. The owned file manifest records final SHA-256
hashes for review. Both Dev migrations and runtime changes are already applied; no further
Dev deploy command is pending for this work. Main/production promotion remains with Svein.
