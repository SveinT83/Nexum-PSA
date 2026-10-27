# ADR: Booking API Authority And Serialized Confirmation

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore (approved 2026-10-27); Codex (implementation).
Parent: ../rfc/2026-10-27-booking-full-api-parity.md

## Context

Booking has anonymous intake and native permission-guarded staff review/configuration,
but no API. Existing pre-transaction availability/status checks cannot alone prove
retry/concurrency safety. Notification timestamps do not prove inbox receipt.

## Decision

Use module-owned public-safe JSON and scoped staff endpoints through shared native
actions. Fresh native rights intersect explicit token abilities; preserve the actual
permission-based actor model. Keep public and staff projections/receipts distinct.
Add encrypted allowlisted command results and durable notification dispatch claims.
Serialize terminal decisions and Calendar final availability/create across web/API,
while retaining ordinary Calendar overlap semantics. Reconcile uncertain delivery
before retry. New API effect gates default off pending operational activation.

## Rationale

Shared actions prevent divergent routing/hours/settings. Live response authority
prevents privileged receipt replay. Terminal locking and Calendar serialization
protect retries and competing decisions. Minimal public acknowledgement preserves
anonymous intake without introducing customer history or private data lookup.

## Consequences

Additive schema/exact migration, native MySQL probes, Calendar and notification
regressions, typed Swagger and handbook publication are required. Broad Calendar
workflow changes or new Booking operations need an approved RFC amendment.

## Alternatives Considered

Browser controller aliases leave redirects and missing bearer/JSON contracts.
Separate API business logic diverges from web. An API-only lock misses web races.
Database rollback cannot unsend mail. Public request-by-key GET expands private
record exposure beyond the existing thanks page.

## Follow-Up

Implement four sequential slices after RFC approval; verify each matrix row,
current authority, native concurrency, typed read-back and BookStack access.
HR-2026-10-27-BOOKING-API remains Pending until named review; Main/production separate.

Authorization: Svein Tore, 2026-10-27: "Godkjenner og merge til main".
Approved Dev implementation and scoped Main promotion; production and named manual
scenario acceptance remain separate.
