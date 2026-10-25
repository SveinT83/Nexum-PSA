# ADR: Data Exchange API Commands And Verified Delivery

Status: Accepted
Date: 2026-10-25
Decision Makers: Svein Tore (approved parent RFC), Codex (technical implementation)

## Context

Full browser/API support must preserve domain-owned sources and Economy exports while adding
configuration, reviewed commands, import replay, scheduled execution and trustworthy delivery.

## Decision

Use domain-owned typed routes in routes.php, retaining api.php as the compatible loader.
Share atomic profile/configuration actions across Livewire, browser and API. Current active actor,
token grants, registered source and owner-domain permissions remain separate checks.

Add data_exchange_write_mutex with one native lock row, encrypted durable command receipts keyed
by actor/operation/key hash, and unique schedule occurrence records. Profile revisions cover all
configuration children. Import review binds input checksum, normalized rows, mapping, profile and
source-owned current target revision; commit reloads and locks the preview before any target write.
Keep the existing canonical Economy command/replay path. Do not move provider credentials or
Tripletex/Workday authority into API adapters.

Delivery freezes file/checksum/target/disk revision and destination intent before external I/O.
A writing claim commits before transmission. Only exact destination byte read-back is verified
success. Unknown outcomes require read-only reconciliation and cannot be blindly retried.
Scheduled delivery intent persists in the same transaction as its completed export and can recover
from dispatch failure. API delivery is separately default-off; approved existing schedule delivery
uses the shared verified runtime without implicitly enabling new API transport.

Existing supported workflow URLs remain. Generic export API callers must now send the preview_hash
and Idempotency-Key. Import commits require current If-Match and Idempotency-Key. These intentional
validation requirements are documented rather than silently retaining unsafe blind writes.
Automatic import pickup remains unavailable and configuration refuses activation.

## Rationale

Database-backed serialized decisions work across HTTP and queue processes. Durable receipts survive
audit retention, explicit source revision avoids direct cross-domain writes, and frozen delivery
evidence distinguishes accepting an operation from verified remote bytes.

## Consequences

Additive migration and native lock/unique checks are required before new writes. Full profile
revision includes children, so concurrent or stale editors must reload. Source changes may
conservatively invalidate import review. Exact destination verification needs read access to the
configured delivery disk. An unknown outcome can remain blocked until evidence is available.
Production/Main/activation and named human review remain separate gates.

## Alternatives Considered

Read-only endpoints omit supported workflows. Reusing browser controllers would copy redirects
and weak validation into APIs. A cache-only lock or audit-only replay would lose correctness when
workers diverge or audit history expires. Treating filesystem put as completion cannot prove bytes.

## Follow-Up

Complete all six parent Feature Slices, typed contract and affected regressions. Apply only the
exact additive migration on Dev after native preflight. Verify actual BookStack content/access and
trusted HTTP/OpenAPI. HR-2026-10-25-DATA-EXCHANGE-API blocks Main/release until named human acceptance.
