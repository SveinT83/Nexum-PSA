# Feature Slice: Ticket Workflow v3 Senior Review And Customer Evidence

Status: Done
Date: 2026-07-17
Parent: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`
Owner: Codex

## Goal

Deliver durable four-eyes approval and scoped customer response/signature evidence.

## User-Visible Behavior

Juniors request review; eligible seniors approve or send back; protected steps remain blocked until
the current evidence is approved. Authorized staff can classify a specific response or attachment.

## Scope

Review request/decision/invalidation, evidence fingerprints, separation of duties, customer message
binding, attachment classification/hash, UI, API, events, and notifications.

## Out Of Scope

Inferring approval through AI or treating normal messages/attachments as approval.

## Data Touched

Workflow review/evidence tables, Ticket messages/attachments/events, permissions, routes, views,
API, and notifications.

## Permissions

Request review, senior review, evidence classify, and administrative invalidate/override.

## Tests

Eligibility, separation, approve/send-back, material-change invalidation, file/message scoping,
client isolation, permission denial, audit, and API parity.

## Documentation

Technician review/evidence, email communication, permissions, API, and human review.

## Done Criteria

- [x] Only current scoped evidence satisfies a gate.
- [x] Senior decisions are immutable and material changes invalidate approval.
- [x] View and API provide complete review/evidence workflows.
- [x] Focused tests pass on Dev.
