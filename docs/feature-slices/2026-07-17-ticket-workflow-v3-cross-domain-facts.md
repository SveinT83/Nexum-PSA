# Feature Slice: Ticket Workflow v3 Cross-Domain Facts

Status: Done
Date: 2026-07-17
Parent: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`
Owner: Codex

## Goal

Expose whitelisted, organization-scoped requirement facts from existing Nexum modules.

## User-Visible Behavior

Admins select Asset, contract, Task, Knowledge, Email/customer, user, Ticket, Storage, Sales, and
Economy facts in the same requirement builder and receive readable results.

## Scope

Provider implementations, operator/value schemas, batching, event invalidation, builder reference,
simulation, API metadata, and cross-module tests.

## Out Of Scope

Moving any source data into Ticket or allowing arbitrary model queries.

## Data Touched

Provider registrations and read paths in participating modules; no duplicated domain records.

## Permissions

Fact metadata follows workflow settings permission; simulated evidence also requires source-record
view permission and organization scope.

## Tests

Every provider/operator, missing records, validity dates, client/work-context isolation, provider
failure, batching/query counts, simulation, and API metadata.

## Documentation

Provider reference, affected module Knowledge, API metadata, and human review.

## Done Criteria

- [x] All RFC-listed initial facts are selectable and evaluated.
- [x] No cross-client fact can satisfy a requirement.
- [x] Provider errors fail closed and remain understandable.
- [x] Focused cross-module tests pass on Dev.
