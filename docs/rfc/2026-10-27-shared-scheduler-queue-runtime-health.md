# RFC: Shared Scheduler And Queue Runtime Health

Status: Approved
Date: 2026-10-27
Owner: Codex / Svein Tore
Issue: #262
Human review: HR-2026-10-27-OPS-RULES-COORDINATOR
Level: 3

## Context

Issue #262 requires runtime reconciliation and a truthful shared health contract. The minute
scheduler exists. Read-only inspection on 2026-10-27 confirms current-user cron with the correct
Dev path, PHP 8.3, umask 0002, flock, and scheduler log, and cron active since the 2026-10-25 reboot.
Repeated scheduler log updates were observed during this inspection. This proves external execution,
not that every queued domain is healthy.

Dev has about 74,631 email-live and 69,033 notifications jobs, predominantly live-authority
maintenance and inbound-notification dispatchers. These counts are sequential observations of a
moving queue, not one transactionally consistent total. One failed ProcessScheduledTickets job
dates from 2026-09-03. Current direct workers cover email/default and supplier-orders. Other PHP
Redis workers belong to potentially different applications; ownership must be verified by process
working directory before attributing them to Nexum. Root crontab was not readable with passwordless
sudo, so the host-wide runner inventory is incomplete.

Production is outside this Issue's activation scope. A separate read-only check found a minute
worker without observed flock, timeout 300, effective database retry_after 90, and only
default,economy,email coverage. This is a duplicate-reservation risk for long-running work, not proof
that a customer action has been duplicated. Table row estimates are not an exact cohort inventory.
Do not fix production cron as an incidental effect of this Dev RFC.

## Goals

- One explicit runtime owner per scheduler entry and queue, with trustworthy four-state health.
- Reconcile direct and shared triggers without releasing historical work or changing delivery policy.
- Prove repeated external execution and controlled queue processing independently.
- Document supervision, timeout/retry alignment, stop, rollback, restart and recovery.
- Keep Email and Storage domain health authoritative for their own delivery details.

## Non-Goals

Agent Heartbeat, autonomous models/Automation, blanket queue restart, historical retry/deletion,
Main promotion, production deployment/activation, new UI or a second System maintenance API.

## Current Behavior

routes/console.php registers 35 events, including Email, Notification, Signal, Ticket, Task, Storage,
Integration/BookStack, Economy, Marketing, Workday, LeadIntelligence, DataExchange, Tripletex and
Nextcloud. Direct Email poll and five Supplier Order cron operations overlap the shared scheduler.
Storage claims due imports transactionally; Email rechecks account eligibility inside the polling
lock. These are existing safeguards, not yet complete concurrent-dispatch acceptance evidence.
A completed scheduled dispatch does not prove its named queue was consumed.

## Proposed Change

Implement the contract inside the existing System module, coordinated with the active System API
parity owner. Keep transport-independent queries/services reusable without modifying its in-flight
API contracts or shared Integration/Swagger files.

1. Store bounded, metadata-only scheduler and queue heartbeat rows: installation/runtime key,
   named component, driver/queue, scheduled/observed/completed UTC timestamps and opaque probe ID.
   Enforce one row per owned component. No payload, exception text, customer ID, credentials, or
   arbitrary process command is stored or exposed.
2. Record scheduler execution inline, independent of workers. Schedule bounded canary jobs only for
   an explicit reviewed queue allowlist. Use unique pending probe identities so stalled queues
   cannot accumulate one canary per minute. No business job is executed by the health reader.
3. Expose a read-only JSON CLI health command, with timestamp, source availability, configured
   thresholds, per-component reason codes, and overall state. Healthy means fresh scheduler and
   completed canaries for all required queues with no recorded operational block; stale means
   previously observed evidence is older than its threshold; blocked means a known operator/policy,
   connection, or backlog restriction prevents the required work; unknown means missing/unreadable/
   unsupported evidence. Missing data is never zero or healthy.
4. Default to a 180-second scheduler freshness threshold and 300-second worker threshold, configurable
   per owned queue. The reader is observational: reading health never creates a heartbeat or drains
   work. A fresh probe at the tail does not certify historical delivery or processed backlog.
5. Recommended coexistence decision: retain existing domain workers. Preserve direct Email/Storage
   triggers until concurrent tests prove the shared path, then disable only the redundant direct
   dispatch/heartbeat/maintenance entries in one reviewed Dev cutover. Keep a saved exact crontab and
   explicit restoration commands. Do not run retention/digest twice during the transition.
6. Keep long-worker timeout below the queue visibility/retry interval with an explicit margin.
   Inventory job-specific timeouts and process termination grace before choosing final values.
   Use one overlap-protected/supervised worker per queue/role, consistent PHP and umask, bounded log
   rotation, and verified restart behavior. Do not add an unbounded all-queue worker.
7. Classify existing queues into domain/age/class/side-effect cohorts using sanitized metadata.
   No restart of an existing consumer, queue reassignment, retry, purge or suppression before the
   cohort's owner and effect policy are reviewed. Canary verification uses an isolated synthetic
   queue first; adding a historical queue to a worker requires a separate bounded recovery plan.

## Impact Analysis

System owns the shared contract. Email, Notification, Signal, Ticket, Task, Storage, Integration
and BookStack supply domain-owned idempotency/health evidence. Additional registered domains
(Economy, Marketing, Workday, DataExchange, LeadIntelligence, Tripletex, Nextcloud) must be included
in the inventory so a default worker change cannot silently trigger their side effects.
No new domain routes, grants, provider activation, or notification recipients are introduced.
Logs expose only metadata. System API parity is active concurrently; do not edit its shared paths
until exact ownership is reconciled.

## Data And Migration Plan

One additive System-owned heartbeat table with bounded columns and a unique component key.
No business backfill. Migration runs only on Dev after approval and exact schema preflight.
Forward-disable heartbeat scheduling/canaries on rollback, preserving evidence and domain workers.
Keep health data retention bounded with an owned cleanup operation; no historical business queue
deletion. Production migration/deployment remains a separate explicit decision.

## Testing Plan

- Time-controlled healthy/stale/blocked/unknown tests, missing schema/backend and unreadable source.
- Duplicate and out-of-order canary completion, bounded pending probe and no payload exposure.
- Concurrent direct/shared dispatch for Email/Storage; idempotency evidence for Notification, Signal,
  Ticket, Task, Integration and BookStack. Include publish/dispatch versus consume/effect separation.
- Controlled isolated non-production jobs, no historical queue consumption; current guards remain.
- External scheduler log/heartbeat read-back across consecutive ticks after existing reboot or
  deliberately reviewed restart, plus independent queue completion read-back.
- Domain health remains correct, logs/ACLs/umask verified, rollback restores saved crontab exactly.
- Relevant Dev tests and named human runtime review before autonomous activation.

## Documentation Plan

Update System queues/workers source, domain operational guides where ownership changes,
docs/TODO.md, this RFC, execution evidence and the linked human-review entry.
Publish affected employee articles to BookStack and read back content/access.

## Feature Slices

1. Runtime/cohort inventory and coexistence proof; complete missing privileged runner inventory.
2. Metadata-only health storage, observation, bounded canaries and CLI contract with tests.
3. Reviewed Dev runtime cutover, isolated queue verification, stop/recovery drills and handbook.
Each slice has a concrete verification gate before the next. No production activation is implied.

## Open Questions

The design and Dev-only implementation scope are approved. Operator access to the remaining
host-level runner inventory is required before claiming a complete inventory. Historical backlog
effect decisions remain separate and cannot be inferred from RFC approval.

## Approval

Approved by Svein Tore on 2026-10-27 in this conversation: "Ja godkjenner" in direct response to the RFC approval question. Approval covers documented Dev implementation and controlled reconciliation; historical backlog effects, Main, production and human runtime acceptance remain separate.

## Implementation Status (2026-10-28)

Implemented and verified on authoritative Dev under the explicit approval above. Detailed sequential slice status: ../feature-slices/2026-10-28-shared-runtime-health-reconciliation.md. Verification: ../plans/2026-10-28-runtime-health-verification.md. The earlier privileged inventory gap is resolved. Two forward migrations create the metadata table and add queue latency; deployment defaults remain off, while the approved Dev profile is active for existing owned queues only. Final cold/failed-cohort recovery and named human acceptance remain outstanding; no Main, production or autonomous activation is implied.


## Authorized Main Promotion (2026-10-28)

Svein separately requested "Fix alt og merge til main". The source-only promotion extends the
earlier Dev approval without approving cold/failed cohorts or production.

Verified Main: PR #372, a5ad470991d84a487e574fb901bba910c11c8d84; independent exact tested tree
6c1695a0107aa8e1a76c21af2f359d0d0e7ea70e. Dev 22/169; latest-Main matrix 139/7321, all passing.
Nineteen PHP syntax checks, XML and scoped whitespace pass. No full application suite was run.
All promoted runtime/Ticket/Email code matched the completed owner's final snapshot; Dev branch,
index and unrelated staged Storage work were preserved.
Actual BookStack Main sections 367/12 and 424/35 were independently read back with inherited access
preserved. System Knowledge 79 revision 214 matches source/access; native sync remains pending_push.
No new Ticket Knowledge article was invented; its authoritative source guide and actual BookStack
runtime section were updated. Unrelated older Ticket-handbook paragraphs remain a #265 follow-up.
Issue #262 stays open: named cold/failed-cohort disposition, duplicate-path operating acceptance
and HR-2026-10-27-OPS-RULES-COORDINATOR (In Review) remain. Comment 6460873462 and Issue body Main
status were independently read back. No cron/configuration/worker scope, historical job or
production runtime changed in this promotion. Deployment defaults remain disabled/empty-scoped.
