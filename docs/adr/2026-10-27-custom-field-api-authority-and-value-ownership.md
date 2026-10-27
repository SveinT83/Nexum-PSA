# ADR: CustomField API Authority And Value Ownership

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore (approved 2026-10-27), Codex (proposal).
Parent: ../rfc/2026-10-27-custom-field-full-api-parity.md

## Context
Definition administration lacks API parity. Three consuming domains already enforce
record/field authority and Ticket-specific events/gates. Duplicating value transport
would create competing permissions, mutation events and lifecycle behavior.

## Decision
CustomField owns guarded definition configuration, discovery and encrypted command
receipts. Value reads/writes stay in Clients and Ticket with their native scopes,
contexts and audit boundaries. Configuration requires customfield.manage_settings
plus the operation's explicit token ability. Discovery filters actor-visible metadata
before pagination. New writes use durable idempotency and current-state preconditions;
receipt replay rechecks current authority. Existing Ticket gates stay unchanged.

## Rationale
One definition implementation serves browser and API; one owning-domain value
boundary preserves customer context, normalization, uniqueness and event semantics.
A retryable transport cannot turn an integration scope into a native admin grant.

## Consequences
Requires an additive receipt migration, typed contracts, compatibility notes for
legacy discovery tightening and cross-module regression tests. Definition key/type
changes remain existing behavior and do not convert old values or unpin rule versions.

## Alternatives Considered
Scope-only administration permits privilege escalation. Generic polymorphic value
writes duplicate object authorization. Controller-specific validation drifts from UI.
All are rejected in favor of shared native authority and owning-domain mutations.

## Follow-Up
The parent RFC is approved, Knowledge has merged in PR #331; implement the
sequential slices and retain Pending manual review until named human confirmation.
