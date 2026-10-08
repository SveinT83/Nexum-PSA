# ADR: Bounded customer profile reconciliation with provider authority

Status: Accepted
Date: 2026-10-08
Decision makers: Svein Tore and Codex
Parent: ../rfc/2026-10-08-tripletex-customer-profiles.md

## Decision

Keep billing email and one stable Site address as the complete recurring synchronization scope.
Use encrypted per-field baselines and durable pending update evidence. Tripletex wins same-field
conflicts; independent edits merge. No timestamps-as-authority or whole-customer overwrites.
Bind the address kind and Site explicitly once; missing/reassigned Sites require operator attention.
Primary contact suggestions terminate at creation; the reconciler has no Contact dependency.
The saved customer GUI switch controls work, including the scheduled scanner and manual retry.

## Rationale and consequences

A local transaction cannot cover provider writes. Durable intent and GET-before-retry recover
ambiguous outcomes without blind overwrite. Provider versions protect updates against concurrent
changes; a local comparison under row locks prevents overwriting edits made during HTTP calls.
Partial updates exclude customer email, phone, names, contacts and unrelated addresses.
No delete propagation. First reconciliation of old links initializes from Tripletex.
No webhook endpoint is introduced; bounded polling reuses the existing scheduler and account lock.
Read-only setup cannot establish real provider PUT permissions; human acceptance remains required.
