# ADR: Integration Hub Durable Execution, Approval, And Audit

Status: Accepted
Date: 2026-08-14
Decision Makers: Svein / Codex
Related: GitHub #212, #215, #216

## Context

An MCP connection, model transcript, queue attempt, or provider response cannot be the source of
truth for operational work. Nexum needs protocol-neutral state shared by UI, API, MCP, Agents, and
scheduled workloads. Read attempts and emergency control changes also need sanitized evidence.

## Decision

Integration owns durable Executions, ordered Execution Steps, Approval Requests, Approval Decisions,
and immutable Audit Events. Executions use UUIDs, stable correlation IDs, scoped idempotency keys,
policy/plan digests, actor/workload/service identity, installation/Client/Site/Integration target,
capability/version, sanitized request/outcome summaries, verification metadata, and retention dates.

Lifecycle states are `queued`, `running`, `input_required`, `partial`, `failed`, `unknown`,
`completed`, and `cancelled`. Cancellation records intent and evidence; it never claims external
compensation. A unique scoped idempotency digest prevents duplicate durable work. Steps provide
checkpoints and retry metadata without storing arbitrary payloads.

Approvals bind an immutable plan digest, scope, risk, expiry, requester, and decision actor. The
requester, service actor, workload system actor, and AI cannot approve their own work. A material
plan change invalidates approval.

Audit events are append-only metadata. They include correlation, decision/result classification,
scope identifiers, capability/version, source/freshness, duration, and safe reason code. Full
request/response bodies, credentials, authorization headers, and raw exceptions are excluded.
Retention and pruning are settings-led.

Emergency controls exist at global, Integration, capability/version, and optional Client/Site
binding scope. Disablement is checked centrally before an adapter call and is not cached in the
first slice, so maximum propagation delay is one database read plus an in-flight request timeout.
Re-enablement requires an authorized operator and creates a distinct audit event.

## Consequences

- Read-only operations can produce completed or denied durable evidence without fake provider work.
- Future mutations can reuse the same execution and approval state machine.
- APIs paginate and scope every execution, approval, and audit query.
- Retention pruning can remove expired operational metadata without touching provider systems.

## Alternatives Considered

- Use MCP Tasks as storage. Rejected because task state would disappear with the protocol service.
- Reuse application logs as audit. Rejected because logs are not scoped durable evidence.
- Cache disablement. Deferred until deterministic invalidation and bounded propagation are needed.

## Follow-Up

Implement the durable model, read APIs, pruning command, emergency runbook, tests, and human review.
