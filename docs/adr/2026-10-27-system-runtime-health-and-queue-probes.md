# ADR: System-Owned Runtime Health And Bounded Queue Probes

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore / Codex
Issue: #262
Parent: ../rfc/2026-10-27-shared-scheduler-queue-runtime-health.md

## Context

Scheduler registration, worker processes and queue counts do not establish completed dispatch or
worker freshness. Domain-specific Email and Storage signals remain authoritative for delivery.

## Decision

System owns one metadata-only component row per runtime/connection/queue identity. Scheduler evidence
is written inline. Allowlisted asynchronous queues receive one pending opaque canary at most.
Probe identity and compare-and-set completion prevent stale delivery from refreshing a newer probe.
Observation never creates health evidence. Missing or unreadable evidence stays unknown; explicit
operational blocks cannot be hidden by fresh timestamps. No UI/API or generic process inspection is
added. Existing System API contracts remain unchanged.

## Rationale

Durable timestamps survive process restarts and work across platforms. Queue-specific probes prove
that owned workers consumed a controlled job independently of scheduler activity. Bounded pending
identity prevents health monitoring from multiplying stalled backlog. Health is metadata, never
message content, credentials, arbitrary command text or job payloads.

## Consequences

One additive migration and an explicit reviewed queue allowlist are required. Silent unconfigured
components cannot be called healthy. A fresh canary does not certify historical delivery. Existing
domain workers and rich health remain. Unknown transport outcomes do not permit blind redispatch.
Historical backlog and production changes require separate reviewed scope.

## Alternatives Considered

Queue counts/process presence alone can report false health. A canary every minute without a durable
pending identity grows backlog. Replacing domain health loses business-specific evidence. Automatic
all-queue worker startup would release unrelated effects and is rejected.

## Follow-Up

Implement the approved three slices, record Dev-only schema/canary/tick evidence, reconcile the
coexistence decision after tests, publish the operational guide and retain human-review checklist
HR-2026-10-27-OPS-RULES-COORDINATOR.
