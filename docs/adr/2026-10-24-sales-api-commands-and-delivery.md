# ADR: Sales API Commands And Frozen Delivery

Status: Accepted
Date: 2026-10-24
Decision Makers: Svein Tore (RFC approved in chat), Codex (technical defaults within approved scope).

## Context

Full browser/API parity needs current-actor authorization, pure reads, exact version/parent identity, safe retry and frozen outbound communication. Current quote/activity jobs can resolve mutable recipients or data at execution time.

## Decision

Sales owns durable command receipts and delivery-attempt metadata. Receipt identity is independent of activity retention, keyed by actor/capability, operation and request key with canonical fingerprint. Commands use ordered row locks and strong content revisions. API and browser share business actions and validation; separate typed projections serve staff versus public/portal customers.

Outbound previews freeze reviewed content, version, recipient/CC and Email provider binding. Execution revalidates rights/state, creates a unique durable attempt and dispatches after commit. Jobs claim once; a started ambiguous attempt cannot auto-resend. Queued, failed, unknown and SMTP-accepted evidence are distinct from inbox receipt. Recovery may only redispatch never-started attempts; no default-on runtime or provider change.

## Rationale

A token alone cannot grant actor or customer identity. Mutable job inputs and ephemeral audit-based replay cannot provide safe operational API retries. Existing Sales, Ticket, Storage, Client/Contact, Email, Calendar and portal owners remain authoritative.

## Consequences

Additive migration and retained receipts/attempts are required. Operational rollback disables entry/dispatch without dropping evidence. Staff cannot use these APIs to impersonate customer acceptance. Compatible old paths are retained; new effectful calls require explicit keys and revisions. No claim of exactly-once SMTP delivery is made.

## Alternatives Considered

Controller/HTML wrapping duplicates transport behavior and cannot provide typed contracts. A generic model dispatcher bypasses domain rules. Reusing activity logs for idempotency breaks after retention. Blindly retrying uncertain SMTP delivery may duplicate customer mail.

## Follow-Up

Implement/test the six approved Sales API slices; native DB concurrency and human review HR-2026-10-24-SALES-API, complete Swagger and verified BookStack publication before completion handoff. Main/production remain separately gated.
