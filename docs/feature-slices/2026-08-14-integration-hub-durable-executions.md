# Feature Slice: Integration Hub Durable Executions

Status: Ready for Human Review
Date: 2026-08-14
Parent: GitHub #215
Owner: Svein / Codex

## Goal

Persist protocol-neutral Execution, Step, Approval, Decision, and Audit state before provider
mutation is introduced.

## Routes And Behavior

Bounded list/get routes expose only executions and approvals in the effective grant scope. The first
slice records read-only inspection executions; it does not invent a provider-success path.

## Data Touched

`integration_hub_executions`, steps, approval requests/decisions, and audit events. UUID identity,
correlation, scoped idempotency digest, immutable plan/policy digests, timestamps, retention, and
indexes are included. Rollback drops only new Hub tables.

## Permissions And Isolation

`integration-hub.executions.read`; all queries filter installation and effective Client/Site/
Integration scope before pagination. Approval decisions additionally require future explicit write
policy and are not enabled by this read-only slice.

## Tests

State transitions, duplicate idempotency, checkpoints, approval expiry/separation of duties,
cancellation semantics, isolation, pagination, recovery, pruning, and secret/payload exclusion.

## Documentation

Execution ADR, API/state/retention/operations docs, and human review.

## Done Criteria

- [x] Durable state survives request boundaries.
- [x] Lifecycle and audit classifications are explicit.
- [x] Isolation, idempotency, retention, and redaction tests pass.

Approval creation now serializes on the Execution, binds the immutable plan digest, rejects a
second pending request, and enforces expiry and separation of duties. Human review remains
`HR-2026-08-15-001`.
