# Feature Slices: Shared Runtime Health And Reconciliation

Status: Done On Dev / Operational Acceptance Blocked On Named Cohort Review
Date: 2026-10-28
Parent: ../rfc/2026-10-27-shared-scheduler-queue-runtime-health.md (Approved)
ADR: ../adr/2026-10-27-system-runtime-health-and-queue-probes.md (Accepted)
Owner: Codex / Svein Tore
Human review: HR-2026-10-27-OPS-RULES-COORDINATOR (In Review)
Issue: #262

## Slice 1: Inventory And Duplicate Boundaries

Status: Done On Dev.

Goal: establish actual runner/worker ownership, queue effects and duplicate protection before cutover.
Scope: privileged crontab/systemd/process inventory, all 35 original schedule registrations, named
pending/failed cohorts, job timeout inventory and relevant domain regression contracts.
Data: metadata-only observations, plus a regression fix in the existing scheduled Ticket boundary.
Permissions: existing approved SSH/sudo path; no role, token or provider-policy changes.

The privileged inventory resolved the earlier root-read blocker. Only the current user's Nexum
runner and domain workers were found for the authoritative path; unrelated Redis workers have a
different working directory. Original direct Email and five Supplier commands duplicated shared
entry paths. The longest default job timeout is 1,800 seconds, above the old consumer visibility.

Repeated StoreScheduledTicketOccurrence calls created duplicate Tickets. A failing regression
proved this before the fix. Parent Ticket locking plus exact occurrence-time identity now returns
the existing occurrence, including a deliberately removed one, without cloning messages/attachments/
Task groups again. One-time activation also locks and rechecks current due state.
A native MySQL two-process check returned one occurrence and one cloned note; owned fixtures were
removed and no notification/provider delivery was performed.

Tests: affected 117-test matrix and native evidence in the linked verification record.
Done criteria: actual inventory, classified effects, coherent locks and source-owned dedup evidence
are recorded. Historical payloads, exceptions, credentials and message bodies are excluded.

## Slice 2: Durable Read-Only Health And Bounded Probes

Status: Done On Dev.

Goal: distinguish healthy/stale/blocked/unknown without confusing registration or publication with
worker completion. System owns one metadata-only component row. Probe claims are conditional SQL;
only one pending identity exists, even if the queue stops. Completion is compare-and-set and records
actual queue latency; a late completion cannot produce a false green.

Commands: system:runtime-health (read-only JSON), system:runtime-heartbeat (owned writes/probes),
system:runtime-probe-reconcile (explicit queue/UUID/non-delivery attestation; never delivers/retries).
Configuration: default-off, explicit required/probe/blocked queue lists capped at 32; scheduler and
worker deadlines with reviewed per-queue overrides. Unsupported or sync backends remain unknown.
One extra module provider registers a gated schedule; existing System API contracts are unchanged.
PHPUnit forces the deployment switch off unless a test explicitly configures the feature.

Data: one additive table, two forward migrations; exact Dev batches 45 and 46. No business backfill.
Retention removes obsolete completed metadata after 30 days; unresolved probes remain for review.
Permissions: no new browser/API routes, scopes or grants. CLI requires trusted server access.

Tests: final 15 / 96, including failure/unknown/future/missing evidence, bounded stalled probes,
ambiguous publication, stale/wrong/duplicate completion, independent deadlines, latency, retention,
non-writing observation and exact reconciliation. Native concurrent publishers emitted one actual
database-queue canary, consumed only on the isolated acceptance queue.
Done criteria: tested states, no payload retention/exposure, independent native completion proof.

## Slice 3: Reviewed Dev Runtime And Handbook

Status: Implemented And Verified On Dev / Final Operational Acceptance Blocked.

The approved Dev cutover removed the six redundant direct triggers, retained ordinary consumers and
kept the single flock-protected shared scheduler. Read-back confirmed exact crontab hashes.
Default/email visibility was raised to 2,100 seconds (1,800-second job plus margin); Supplier remains
300 versus a 240-second job. Processes retained umask 0002 and naturally recycled; no forced restart.

Live probes exposed starvation under email,default priority. The default cohort was reviewed first:
current scheduler dispatchers/telemetry, empty campaigns/Ticket schedules/webhook outbox,
CloudFactory disabled and no BookStack integration. Separate default and Email consumers now retain
the same queue set and prevent starvation. Cold economy/email-live/notifications queues remain
blocked with no new consumers or canaries. Their historical business effects require separate review.

Four runtime logs have a tested daily/20M/14-rotation policy; the OS logrotate timer is active.
Stop/recovery/forward-disable and exact protected crontab backups are documented.
Source and actual BookStack guides are updated/read back without expanding access.

Remaining acceptance: human runtime review; controlled recovery/disposition of failed cohorts;
explicit ownership/effect policy for cold queues before their workers or autonomous AI/Automation
can be activated. Email polling/fetch are current, but its richer health still reports the existing
failed-job cohort; this has not been hidden or blindly replayed. #262 remains open for these criteria.
No Main commit/push/merge, production deployment, provider activation or shared-token change.

## Documentation And Evidence

- ../plans/2026-10-28-runtime-health-verification.md and its JSON companion.
- System queues-and-workers and Ticket lifecycle Knowledge source.
- Existing HR-2026-10-27-OPS-RULES-COORDINATOR retains all unrelated pending #285/#290 checks.

Additional verified runtime regression: Email health checks now defer only transient provider_work_locked contention (30 seconds, 20 attempts; other exceptions fail once). No false account check is recorded. The regression failed before and the final Email matrix passes 16 / 117; existing failed history is preserved. Exact probe UUID discovery/reconciliation passes the final 1 / 14 CLI regression.
