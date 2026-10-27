# RFC: Full Relationship Browser/API Parity

Status: Approved
Date: 2026-10-27
Owner: Codex. Product approver and intended human reviewer: Svein Tore.
Implementation: Done On Dev; current Main candidate verified and scoped promotion explicitly authorized.

## Context
Svein requested "Relationship domain: Lag 100% API støtte". Fresh authoritative Dev
inspection finds ten Relationship browser route rows, five signed machine ingress
operations and five existing Ticket-owned Relationship API operations. No general
Relationship management API exists. The approved July relationship RFC establishes
the implemented product; it does not define the new full management API contract.
The exact baseline and coverage matrix are in ../plans/2026-10-27-relationship-api-*.

## Goals
Expose every implemented browser read, write, configuration and action through the
official API with native actor authority, explicit token scopes, domain validation,
typed requests/results/errors, exact durable read-back, OpenAPI/Swagger and tests.
Include form discovery, credentials setup/rotation, health, sync links/events,
Documentation and Knowledge eligibility/push and the existing Ticket workflow.
A denial, route count or published schema alone does not establish full parity.

## Non-Goals
New relationship types, pairing, generic retries, conflict resolution, deletion,
service-area default routing, or other future workflows without a supported current
browser action. No automatic provider activation, role grants, historical sends,
customer publication or production deployment. Scoped commit/push and Main promotion
are separately authorized by Svein Tore in this conversation.
No UI redesign or ad hoc Norwegian interface strings. Internal content remains local.

## Current Behavior
Admin routes expose list/stats, create/edit contexts, create/update, detail with
health and latest 25 links/events, inbound/signing-secret rotation, two eligibility
lists (latest 20), and explicit Documentation/Knowledge push. Direction links a
Client when we are provider or a Vendor when we use a provider. Active setup requires
remote URL/organization identity, inbound token, signing secret and applicable
outbound credentials. Collaboration cannot be activated. Form settings include
capabilities, service areas, queue/auto-create, status mapping, two-way content and
attachment size/type policy. Omitted form fields may reset policies; API PATCH must
instead preserve omitted fields and distinguish explicit clearing.

Web administration requires Admin access plus relationships.view, relationships.manage
or relationships.sync according to the exact action. Ticket escalation requires
relationships.escalate and a Published, eligible Ticket. Existing Ticket API owns
targets, escalation, receipt list/detail and link reads; reuse those contracts.
The shared escalation action has a pre-existing contributor change extracting
validateTarget; it must remain intact.

Five public machine endpoints receive tickets, public messages, mapped statuses,
Documentation and Knowledge. Authentication is relationship token hash plus HMAC
over timestamp/raw body (300-second window), independent of staff Sanctum tokens.
Internal content, other-client content and local divergence are rejected/conflicted
by the existing domain actions. Outbound HTTP is synchronous; a transport error can
leave remote outcome unknown even if the current sync link says failed.
Current redirects can claim content was sent regardless of the actual link outcome.
The new API must report the verified result, preserving domain actions and correcting
shared result interpretation only within this approved scope.

## Proposed Change
1. Register all Relationship routes in Relationship/routes.php with safe web/API
   loading guards. Keep api-public.php only as a compatibility loader if needed;
   preserve the five existing signed URLs, authentication and wire payloads.
   Reuse existing Ticket-owned endpoints; do not create a second escalation flow.
2. Add /api/v1/relationships reads, create/update, form context/lookups, health,
   scoped link/event history and detail, eligible Documentation/Knowledge catalogs,
   push previews, explicit push execution and command receipt list/detail.
   Create/edit pages map to JSON context, not rendered HTML. Cover all current
   settings with typed arrays/objects and strict unknown-field rejection.
3. Reuse/extract shared Relationship validation, persistence and query actions
   for browser/API so direction, active setup, capabilities, routing and policy
   rules have one owner. Preserve unchanged credentials on omission. Use bounded
   pagination/filters, deterministic ordering and safe explicit resource projections.
4. Proposed new scopes: relationships.read, relationships.manage,
   relationships.credentials.write, relationships.sync, relationships.providers.execute
   and relationships.operations.read. Retain existing read/sync catalog names.
   Each route must verify a live active actor, explicit token ability and current
   native permission. General admin API requires the same Admin boundary as web.
   View is for list/detail/health/history; manage permits narrow configuration
   context/create/update/rotation without requiring unrelated broad view permission.
   Credential fields and rotation additionally require credentials.write.
   Sync/context/eligibility/preview requires relationships.sync and the source
   domain's current record/audience/work-context authority. Native sync permission
   must not imply permission to exfiltrate any Documentation or Knowledge record.
   Ticket operations retain ticket.view, relationships.escalate and existing scopes.
5. No plaintext credentials/hashes/ciphertext in ordinary reads, logs, errors,
   Swagger examples or history. Expose configured/present metadata only. Accept
   outbound/inbound/signing material through credential-authorized writes.
   Return only a newly generated inbound token in its authorized command response;
   store any replayable sensitive result encrypted and bind it to the original
   actor/token/key with fresh credential authority on replay. Never reveal existing
   signing/outbound secrets or raw sync metadata through model serialization.
6. Use canonical request fingerprints, required Idempotency-Key for new writes,
   actor/token-bound durable encrypted receipts and opaque If-Match state versions
   for update/rotation/push. Recheck current rights on replay. Identical replay
   cannot generate another secret or send again; changed input or stale state is
   a 409/412. Persist local changes and receipts transactionally with locking.
   Reserve each outbound attempt durably before sending; include exact destination,
   source version/checksum and action. Same target/source concurrency across web/API
   must not double-send; guard at the shared action boundary.
7. Push preview has no external calls or business writes. Execute requires current
   relationships.providers.execute in addition to sync/native/source authority
   and a separate default-off API outbound-execution setting. An authorized
   explicit activation is separate from installing API support. Existing ordinary
   browser/domain transport keeps its supported policy; no broader automation
   activation is inferred. Verify trusted HTTPS/TLS and destination binding;
   never bypass certificate validation or introduce redirect-based token disclosure.
8. Receipt truth distinguishes local accepted/reserved, verified remote result,
   rejected, conflict, failed and unresolved. HTTP acceptance alone is not exact
   remote content read-back. Ambiguous timeout/malformed response remains unresolved
   and non-replayable as another send; retained receipts identify the owner and
   reconciliation action without adding a generic retry workflow. Reuse existing
   sync link/event evidence and preserve immutable audit. Do not implement direct
   Knowledge/Documentation writes or a competing revision/publication model.
9. Document and validate all five signed ingress operations in OpenAPI with exact
   headers, request/response/error types and machine authentication, not bearerAuth.
   Preserve supported public-reply/status/attachment rules and receiver idempotency.
   Add strict contracts only where compatibility permits; any required incompatible
   wire change needs explicit RFC revision and approval.
10. Maintain route-to-contract-to-test-to-read-back coverage for every operation,
    including reused Ticket APIs and embedded browser context. Prove successful
    HTTP response schemas and permission denials, not merely that routes exist.

## Impact Analysis
Relationship owns configuration, credential custody, eligibility, links/events,
transport and command storage. Ticket owns Ticket policy, public replies, escalation
receipts and status transitions. Documentation owns Vendor master data and document
record authority. Knowledge owns published revision/audience/access and #266/#276
sync rules. Client remains the external customer master. Integration owns abilities,
token discovery and Swagger. Email fallback/publication behavior must regress safely.
No domain ownership changes or permission grants are planned.
Shared dirty Dev work, especially Knowledge parity in progress and the escalation
action change, must be rechecked before edits. Coordinate exact shared Integration,
Swagger and Knowledge files; do not start conflicting implementations. UserManagement
and Signal RFC drafts remain separately awaiting approval. No prior work is cancelled.
Risks: credential disclosure/replay, cross-customer export, concurrent sends, stale
configuration and unknown remote outcomes. Tests cover positive and negative paths.

## Data And Migration Plan
Plan one additive Relationship command/attempt schema with unique scoped replay
identity, encrypted sensitive result storage, state version and durable outbound
claim. Reuse existing relationships, sync links and events. No business backfill,
credential re-encryption, role change, audit deletion or historical resend.
Record the exact migration after approval and run only that migration on Dev.
Validate native MySQL uniqueness/concurrency/rollback, not only SQLite behavior.
Future deploy: exact additive migration, Swagger regeneration and normal cache clear;
no frontend build or new scheduler/worker expected. Record any discovered dependency
before changing it. Recovery disables the new API entry points/execution switch while
retaining receipts and history; reconcile unknown external effects before resend.

## Testing Plan
Existing Relationship baseline passes 10 tests / 37 assertions on Dev.
Existing Ticket Relationship API tests are checked separately in the baseline.
Add route, native/token/admin/source authority, revoked actor/key, safe projection,
every setting, validation, omission/clear, state precondition, credential custody,
rotation/replay, local transaction, concurrent send, remote conflict/failure/unknown,
disabled outbound and positive success/read-back coverage.
Regress the five signed webhooks, public/private replies, status transitions,
attachments, Client/Vendor scope, Documentation divergence and Knowledge revision/
publication. Run affected Relationship/Ticket/Documentation/Knowledge/Integration,
API catalog/Swagger tests, syntax/Pint/whitespace and trusted HTTPS probes.
Exact runtime/Swagger sets and successful HTTP responses must match the matrix.
Human two-instance verification remains separate from fake-provider tests.

## Documentation Plan
Update Relationship README, nexum-relationships.md, the two-instance test guide and
a complete API guide; update Integration API management and affected domain guidance.
Sync affected authoritative Knowledge articles through the supported BookStack
integration and read back actual content/access without widening permissions.
If blocked, record concrete blocker, owner and next action in TODO; queued sync or
pending_push is not publication. Employee OpenWebUI retrieval is a separate claim.
Keep matrix/slices/TODO and a human-review checklist current. At implementation handoff,
create HR-2026-10-27-RELATIONSHIP-API (Pending), covering admin, credentials, scope,
real two-instance results/conflicts and unknown-outcome handling. It gates future
Main promotion and production release/deployment until named human acceptance.
Website handoff must be public-safe and Dev-only / do not publish until production
availability is independently verified. Planning does not require handbook publication.

## Open Questions
No unresolved product decision prevents drafting full current-browser API coverage.
Explicit RFC approval is required before Level 3 implementation. Shared Knowledge/
Integration overlap must be reconciled before the corresponding implementation slice.

## Approval
Approved by Svein Tore in conversation on 2026-10-27: "Godkjenner og merge til main".
This authorizes sequential Dev implementation and tested scoped Main promotion.
It does not assert execution of human scenarios or authorize production deployment.
Pending human scenarios remain a production release gate; Main promotion is expressly
authorized with that gate visible. Native AdminAccess is permission-based, not a fixed role.


## Main dependency inventory

Main lacked the five Ticket Relationship adapters already implemented on Dev. RL04
therefore promotes exactly those existing controllers/actions/resources/tests, their
shared input/envelope dependencies, two existing scopes and a guarded Ticket route
compatibility loader. It does not promote the rest of Ticket/Task API parity. The
pre-existing EscalateTicketToRelationship validateTarget extraction is included
byte-for-byte as a required dependency and remains untouched on shared Dev.
