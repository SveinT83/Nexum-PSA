Queues and workers keep background work running without making technicians wait in the browser.

Production should run workers through Supervisor, systemd, Docker, or another process manager. Do not rely on manual terminal sessions, because a closed SSH session or reboot will stop the worker.

The Admin Queue and Worker page renders setup examples with the current Laravel `base_path()`.
Use the path shown in the UI for the target server instead of copying a development path from another
environment.

Reference worker roles after reviewing queue ownership, job timeouts and current cohorts:

```bash
DB_QUEUE_RETRY_AFTER=2100 php artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=120
DB_QUEUE_RETRY_AFTER=2100 php artisan queue:work database --queue=email --sleep=3 --tries=3 --timeout=120
```

This is a reference queue set, not a complete current runtime policy. Inventory every named queue, job timeout and historical cohort before starting consumers. The shared runtime contract below separates scheduler and worker evidence.

Current queue overview:

| Queue | Required | Used by | Notes |
| --- | --- | --- | --- |
| `default` | Yes | Scheduled jobs, normal queued jobs, AI chat cleanup, integrations, email polling, Lead Intelligence research runs, sales emails, ticket emails, RMM sync jobs, BookStack sync jobs | This is the main Laravel queue. Most jobs use this unless code explicitly chooses another queue. |
| `economy` | Yes | Economy order generation after ticket close | Ticket close dispatches `GenerateEconomyOrdersJob` to this queue. A worker that only listens to `default` will not process these jobs. |
| `email` | Optional but supported | Manual async email polling and manual async inbound rule processing | CLI commands can dispatch `email:poll --async` and `email:process-inbound-rules --async` to this queue. Keep it in the worker list so those commands work when used. |

Admin visibility:

- Go to `Admin -> System -> Queues and Workers`.
- `Pending jobs` shows rows from Laravel's `jobs` table.
- `Ready` means the job can be picked up now.
- `Delayed` means the job exists but is scheduled for later.
- `Reserved` means a worker has picked it up and is processing or has not released it yet.
- `Failed jobs` shows jobs from Laravel's `failed_jobs` table.

If a queue shows ready jobs that never disappear, check the worker command. The `--queue=` list must include that queue name.

Example Supervisor program:

```ini
[program:tdpsa-worker]
process_name=%(program_name)s_%(process_num)02d
environment=DB_QUEUE_RETRY_AFTER="2100"
command=php /path/to/nexum-psa/artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=120
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/nexum-psa/storage/logs/worker.log
stopwaitsecs=3600
```

Example systemd service:

```ini
[Unit]
Description=Nexum PSA Laravel queue worker
After=network.target

[Service]
WorkingDirectory=/path/to/nexum-psa
Environment=DB_QUEUE_RETRY_AFTER=2100
ExecStart=/usr/bin/php artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=120
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
```

The scheduler is separate from workers. It should run every minute:

```cron
* * * * * cd /path/to/nexum-psa && php artisan schedule:run >> /dev/null 2>&1
```

Maintenance commands below have real effects and require separate target/cohort review. They are not health checks:

```bash
php artisan queue:restart
php artisan queue:failed
php artisan queue:retry all
php artisan queue:flush
php artisan queue:clear --queue=default
```

After deployments, review the affected queues and supervision before requesting a graceful restart. A restart signal is not proof that a replacement worker started or completed work.


## API Maintenance And Honest Outcomes

System API on Dev exposes status, preview and all four maintenance actions. API
execution is disabled by default; it needs separate operational activation, current
system.queue_manage, matching scopes, explicit target, expiring confirmation and
replay key. Browser controls retain availability and share the dispatch lane.
See System Api Support for numbered steps.

Restart means signal issued; retry means re-enqueued. Neither proves worker completion
or message delivery. Clear/flush remove work and must not be routine health checks.
Omitted API retry target never means all jobs. Unknown counts/backend state stay
unknown; overview excludes raw failure text and payloads.

Unresolved dispatch retains its receipt and blocks further maintenance until reconciled.
Do not use another key to bypass it. Scheduler is separate: verify actual external
schedule:run execution every minute, not only registered schedules or Next Due.

## Shared Runtime Health (Dev, Issue 262)

This CLI contract is implemented on Dev. Production activation is a separate decision. The existing
Admin queue counts describe table contents; they do not prove worker or scheduler liveness.

1. Apply only the reviewed System heartbeat migrations. Keep `RUNTIME_HEALTH_ENABLED=false`
   until the installation's runner, queue ownership and historical side effects have been reviewed.
2. Configure a stable runtime key, explicit queue connection, required queues and a subset of approved
   probe queues. Put known operationally blocked queues in the blocked list. Unlisted queues are not
   started or discovered automatically. Each queue list is capped at 32 entries.
3. Run `php artisan system:runtime-health`. It is read-only: it never creates a heartbeat, invokes
   a business job, retries a failed job or starts a worker.
4. Inspect every component and reason code. Exit 0 means fresh scheduler evidence and acceptable
   worker completion/latency for all required queues. Exit 1 means stale or explicitly blocked.
   Exit 2 means missing, unsupported, invalid or unreadable evidence. Healthy component evidence
   does not prove business delivery or historical backlog completion.
5. The ordinary external scheduler records `system.runtime.heartbeat` once per minute.
   `schedule:list` proves registration only; read consecutive changed UTC timestamps from the
   health result to prove execution. Scheduler evidence is written inline, independently of workers.
6. Only approved asynchronous queues receive `CompleteRuntimeQueueProbe`. The durable component row
   permits one outstanding opaque probe at a time, even if a queue stops. Duplicate or old deliveries
   cannot refresh another probe. A completion delayed beyond the queue deadline remains stale.
7. Keep Email Configuration and Supplier Order Automation health as the richer domain-owned
   sources. A fresh System canary is not evidence that mail arrived or a supplier order completed.

Defaults: scheduler deadline 180 seconds; worker deadline 300 seconds; probe interval at least
60 seconds. Reviewed per-queue deadlines may override the worker deadline in configuration.
Sync, null and unsupported queue backends cannot prove asynchronous worker health. New installations
remain disabled, and an empty required-queue list remains unknown.

### Worker Timeouts And Supervision

Inventory each job's own timeout before setting a connection's retry/visibility interval.
A job-specific timeout overrides the worker's fallback timeout. The interval must exceed the
longest job timeout plus a termination margin; an OS process being present does not prove this.

The Dev review found a default-queue Lead Intelligence job with a 1,800-second timeout, along with
600-second Integration jobs and 300-second Email/BookStack work. The reviewed default/email runtime
therefore needs a visibility interval of 2,100 seconds. Supplier Order jobs have a 240-second timeout
and retain their dedicated 300-second interval. These are Dev-specific reviewed values, not a
production activation instruction.

Keep worker queue sets explicit. Never add `email-live`, `notifications`, `economy` or another
unconsumed queue to a worker until its historical effects are reviewed. A pending or failed job is
not permission to replay, delete or suppress it.

### Stop, Recovery And Rollback

- To stop only System observation, set `RUNTIME_HEALTH_ENABLED=false` and refresh only the target
  process configuration as needed. Domain workers remain independent. A pending canary cannot
  complete after disablement, and its metadata is retained.
- An enqueue exception or crash retains the pending identity; the scheduler never blindly replays it.
  Reconcile the exact queue, transport and any failed record before acknowledging non-delivery.
- After verification, use
  `php artisan system:runtime-probe-reconcile QUEUE EXPECTED_UUID --confirm-not-delivered`.
  This command requires the current approved queue and exact current UUID. It records reconciliation,
  does not claim delivery, does not delete broker jobs, and does not publish a replacement itself.
  A later tick may issue a new identity; a late old delivery remains harmless.
- Preserve the exact reviewed crontab and its checksum before cutover. Stop dispatch before stopping
  consumers. Let current work finish under its documented maximum duration; no blanket queue restart,
  retry-all, flush or clear is part of a health check.
- Forward-disable the new observation on rollback and preserve the additive table. Restore only
  reviewed runner entries. Do not blindly restore a previously unsafe timeout/visibility combination.
- Evidence older than the configured retention (30 days by default) is removed only when no probe is
  outstanding. Unresolved metadata remains until explicit reconciliation; no business row or payload
  is involved.

Main, production, autonomous AI/Automation activation and human acceptance remain separate from Dev
verification. Employee OpenWebUI retrieval is also separate from handbook publication.

### Verified Dev Ownership And Lock Contention (2026-10-28)

Dev now uses one shared scheduler and separate workers for default, email and supplier-orders.
The six duplicate direct Email/Supplier triggers were removed after scoped verification.
Default and Email workers use retry_after 2100; Supplier uses 300. The same queue set remains
active, with no forced restart. Keeping separate default and Email consumers avoids starvation
from a fixed queue-priority list.

The read-only health output exposes pending_probe_id and completed_probe_id as opaque metadata.
Use the current pending UUID when reconciling an ambiguous probe; do not guess or reuse an older ID.

A scheduled Email health check now defers only provider_work_locked contention for 30 seconds,
up to 20 attempts. Other exceptions still fail once. Every attempt rechecks the current provider
binding, and a deferred check records neither a false success nor a false account failure.
Existing failed records and old serialized jobs keep their history and are not automatically retried.

Live scheduler and all three active workers have independently completed fresh canaries.
The overall result remains blocked because economy, email-live and notifications require
separate ownership/effect review. Email's richer failed-job aggregate also remains visible.
Do not clear history or start a cold-queue worker just to make the dashboard green.

Runtime logs include scheduler, default, Email and Supplier files under the tested rotation policy.
The approved crontab and exact rollback candidates are protected under
/home/sveintore/.local/state/nexum-runtime/20261027-issue262. No environment credentials were copied.

## Runtime health availability on Main (Issue #262, 2026-10-28)

The verified runtime-health foundation is available on Dev and Main in PR #372. Production rollout,
cold-queue recovery and named operating acceptance remain separate. Deployment defaults are off
with no implicit queue allowlist.

1. An authorized installation operator can read **system:runtime-health** through the existing
   server CLI. Reading it neither writes evidence nor starts workers or consumes business work.
2. Read scheduler and each owned queue separately. Healthy, stale, blocked and unknown describe
   evidence freshness and approved scope. A healthy default worker does not make a blocked
   notification or live-Email cohort healthy. Business delivery is explicitly unverified.
3. Outstanding canaries remain bounded to one exact pending identity per owned queue. Reconcile
   an unknown probe only after exact transport/non-delivery review; observation never blindly
   republishes or clears a historical job.
4. Keep existing domain health, queue ownership, visibility/timeout alignment and protected
   stop/rollback procedures. Main availability does not repeat the already-applied Dev cutover.
   Do not enable cold queues to make the status green.
5. Web Push consumes only fresh evidence for its exact already-approved default queue. Its own
   protected installation profile, worker check and explicit activation are still required.

Fresh promotion tests passed on Dev (22 tests) and the isolated Main candidate (139 tests).
HR-2026-10-27-OPS-RULES-COORDINATOR remains In Review for named cohort/operating checks. No production
configuration, historical retry/purge, new worker scope or autonomous activation was performed.
