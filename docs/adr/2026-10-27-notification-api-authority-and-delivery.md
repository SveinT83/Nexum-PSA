# ADR: Notification API Authority And Truthful Delivery Evidence

Status: Accepted
Date: 2026-10-27
Decision Makers: Svein Tore, 2026-10-27, approved parent RFC; Codex.
Parent: ../rfc/2026-10-27-notification-full-api-parity.md

## Context

Notification currently has three scoped own-copy endpoints while settings, devices,
tests, portal context and administration remain browser-only. Workday already owns
reminder operations. Queue admission and generic webhook HTTP success do not prove
delivery. Endpoint/key/webhook/driver secrets must not enter API read-back or receipts.

## Decision

Use shared Notification actions/queries for browser and API, with explicit token
abilities intersected with current native identity/record/field rights. Retain
CustomerPortal and Workday authority in their existing modules. Register domain API
routes in each owning routes.php; maintain legacy loaders/URLs when needed.

New sensitive admin writes and externally effective tests use actor/token/operation
bound command claims, current-state guards, keyed fingerprints and encrypted sanitized
receipts. Provider execution follows a durable claim and remains separate from local
transactions. Unknown outcomes cannot be blindly retried. Natural read acknowledgements
and Workday generation retries keep their existing semantics.

Return typed allowlisted summaries. Credential fields are write-only, with configured
flags and retain/replace/clear semantics. Tests distinguish dry_run, queued, accepted,
confirmed, blocked, failed and unknown; no HTTP 2xx or enqueue becomes delivery proof.
Keep native global channel/device consent switches; new real-provider API test execution
has an additional default-off gate and explicit scope.

## Rationale

This provides operational parity without duplicating notification pipelines, loosening
portal/reminder policy or allowing retries to send twice. It preserves device privacy
and truthful operational results while leaving future Talk/VAPID/runtime design separate.

## Consequences

Additive receipt schema and narrowly scoped API abilities require tests, documentation
and native database verification. Existing web count/result wording may need narrow
alignment. Clients must handle accepted/unknown outcomes and retain request keys.
Administrator rights do not grant device subscription or preferences for another user.
No general broadcast, delivery guarantee, role grant or secret export is introduced.

## Alternatives Considered

Controller aliases alone do not supply typed contracts, explicit bearer authorization,
shared semantics or reliable retry handling. A separate Notification integration engine
would duplicate domain pipelines. Treating every HTTP 2xx as delivered contradicts #205.
Reimplementing Workday or portal context would create conflicting authority.

## Follow-Up

Implement the five sequential slices only after RFC approval. Finalize additive schema,
run relevant regressions/native probes and actual BookStack read-back, and retain
HR-2026-10-27-NOTIFICATION-API for named human review. Main/production remain separate.
