# RFC: Full CustomField Browser/API Parity

Status: Approved
Date: 2026-10-27
Owner: Codex. Approver and intended human reviewer: Svein Tore.
Human-review checklist: HR-2026-10-27-CUSTOM-FIELD-API (Pending, planned scope).

## Context
Svein requested 100% API support for CustomField. Fresh authoritative Dev inventory
finds four definition administration routes and two definition API operations.
Two additional Client/Site browser value routes and embedded Client, Client Site
and Ticket workflows are part of the coverage boundary. The June approved core RFC
explicitly describes discovery and value integration; it does not approve this new
administration API contract. No existing full-parity RFC was found.

## Goals
Expose every supported definition read, write and action, all current field settings,
discovery and field types with typed results, native actor and token authority,
read-back, meaningful regression tests and Swagger. Prove value parity through all
three existing owning domains. Completion means workflow coverage, not route counts.

## Non-Goals
New target domains, a second generic value-write API, hard deletion, restoration,
bulk import/export, automatic type/value conversion, new default-value behavior,
Ticket Rules activation (#285), production rollout or runtime activation. A scoped tested Main promotion is authorized.
The model has default_value storage but no current admin control; this RFC does not
silently add that product behavior. Do not reopen or replace completed #231.

## Current Behavior
Definitions support 11 types: text, textarea, number, date, datetime, select,
multiselect, checkbox, email, phone and url. Admin can search/filter, create, edit,
sort, activate/deactivate and soft-delete. Settings cover key, label, model, help,
options, sort order, UI visibility/editability, API editability, searchability,
uniqueness, required, admin_only and optional view/edit permissions.
The actual native management right is customfield.manage_settings; AdminAccess is
not a hard-coded Admin-only role check. Existing API discovery checks only
custom-fields.read and has no field-aware filtering; unknown model filters are
silently ignored. OpenAPI success responses lack typed schemas.
Values belong to client, client_site and ticket; storage supports canonical classes
and legacy aliases. Clients rejects unauthorized supplied field keys. Ticket uses
its own work-context/event boundary and independent default-off write/rule gates.
A pre-existing dirty CustomFieldModuleTest file is contributor work and is preserved.

## Proposed Change
1. Keep module ownership. Register API routes in CustomField/routes.php through the
   established conditional loader; api.php remains a compatibility loader. Preserve
   existing definition URLs and operation IDs. Static routes precede {definition}.
2. Complete this seven-operation contract under /api/v1:

   | Method | Path | Purpose | Token ability |
   | --- | --- | --- | --- |
   | GET | /custom-fields | Filtered, paginated discovery/admin listing | custom-fields.read |
   | GET | /custom-fields/context | Authorized models, types, settings and input context | custom-fields.read |
   | GET | /custom-fields/{definition} | Exact definition/settings/revision | custom-fields.read |
   | POST | /custom-fields | Create definition | custom-fields.create |
   | PATCH | /custom-fields/{definition} | Edit settings or activate/deactivate | custom-fields.update |
   | DELETE | /custom-fields/{definition} | Native active=false plus soft deletion | custom-fields.delete |
   | GET | /custom-fields/operations/{operation} | Own durable command result | custom-fields.operations.read |

3. Require active native actors and customfield.manage_settings for configuration
   writes and full administrative metadata/context. Discovery for other actors must
   have corresponding owning-domain read/work-context rights and field visibility;
   filter before pagination and counts. Do not turn custom-fields.read into admin
   authority. A token never grants the native permission. Document this tightening
   of legacy scope-only discovery, without auto-granting any rights. Deleted or
   unauthorized definitions return non-disclosing errors. Revoked keys/rights must
   also fail on replay and operation read-back; check original target authority.
4. Extract shared definition validation and mutations for browser/API reuse, retaining
   existing UI payloads and routes. Reject unknown keys/model aliases, malformed options,
   invalid type/boolean/permission values and duplicates. PATCH preserves omitted
   settings. Preserve browser checkbox/full-form behavior. Target model remains fixed
   after creation, matching the disabled edit control; no silent retargeting.
   Retain current editable key/type/options behavior without converting stored values;
   pinned Ticket Rules definition/type checks must still fail closed after changes.
   Deleted keys retain existing database uniqueness/reservation semantics.
5. Use actor/token-bound encrypted durable mutation receipts and canonical request
   fingerprints, Idempotency-Key for new writes, and If-Match with an opaque revision
   for update/delete. Lock and recheck current definition state and authorization.
   Same key/different command, stale state and concurrent conflicts must not mutate.
   Soft-delete read-back proves the exact inactive/deleted definition while retaining
   values; normal GET remains 404. Recheck receipts under current native authority.
6. Keep values in Client/Site/Ticket APIs and reuse their existing normalization,
   transaction and audit boundaries. Prove read/create/update/clear/search and
   field visibility with all supported types where the owning workflow supports it.
   API editability remains distinct from UI editability. Required, unique, inactive,
   unknown, cross-model and unauthorized values fail truthfully with atomic writes.
   Fix verified value-parity gaps only through the owning domain, with regression tests.
   Preserve Ticket default-off switches, one-event actual-change/no-event no-op,
   work-context, rule pinning/redaction and complete portal exclusion.
7. Add typed Swagger for all seven operations, shared errors/headers/filters and
   domain value payloads. Scope catalog changes ship with routes and tests, not earlier.
   Track each route and embedded action against scopes, tests and exact read-back.

## Impact Analysis
CustomField owns definitions, shared normalization/presentation and new receipts.
Clients and Ticket retain value/record authority; Integration owns ability metadata
and Swagger discovery. Related rule contracts and Client/Site settings must regress.
No frontend redesign, provider/network effects, queue/scheduler activation or human
role grants. Risks: compatibility tightening for discovery, key/type/option changes
against existing values/rules, legacy aliases, concurrent unique writes and hidden
field disclosure. Preserve unrelated dirty work and all prior review history.
Knowledge parity is Done On Dev and merged in PR #331. Its prior implementation
sequencing prerequisite is satisfied. This chat owns CustomField only; shared catalog
and Swagger edits must preserve other contributors changes.

## Data And Migration Plan
Reuse existing definition/value tables without a business backfill. Plan one additive
CustomField command-receipt migration, with unique actor/token/idempotency identity and
encrypted results. Finalize its filename during approved implementation. Verify the
exact migration on Dev and native MySQL uniqueness/locking/rollback/encryption; never
run all outstanding migrations in the shared checkout. Preserve APP_KEY. Future
deployment needs the exact migration, Swagger regeneration and normal cache clearing.
No asset build, worker restart or scheduler change is expected. Forward-disable new
write entry points on failure; keep receipts and values for reconciliation.

## Testing Plan
Existing Dev baseline: CustomFieldModuleTest 9 tests / 87 assertions; Ticket Custom
Field surface/rule tests 8 / 141. Total 17 / 228 passing, no failures/skips. These
are existing behavior tests, not proof of the future contract.
Add native/token/active/revoked actor tests, field-filtered list/detail/counts,
manager context with no secret leakage, all definition settings/types, search/model
filters/pagination, invalid payloads, partial PATCH, uniqueness, preserved target,
retained-value soft deletion, repeated/stale/concurrent commands and exact receipts.
Cover all Client/Site/Ticket value boundaries listed above and pinned rule regressions.
Run affected Clients/CustomField/Ticket/Integration/catalog/Swagger tests on Dev,
syntax/Pint/whitespace, exact runtime-to-OpenAPI mapping, typed actual HTTP results,
trusted HTTPS smoke/read-back and native database contracts. Record real limitations.

## Documentation Plan
Update CustomField Knowledge source and full API guide, related Client/Site/Ticket
and API-management guidance. Explain API/UI editability, native/token authority,
11 types, null/clear, soft deletion, optimistic conflicts and Ticket activation gates.
Sync/publish affected handbook sources through supported BookStack integration and
read back actual bodies and employee access; pending_push is not publication.
Record any concrete blocker, owner and next action in TODO. Keep OpenWebUI retrieval
separate from BookStack proof. Update matrix/slices/TODO/human-review in the same
session. Completed public-safe work follows the website handoff process with Dev-only
and do-not-publish status until production verification. Planning is not publication.

## Open Questions
No unresolved product decision is needed for current browser parity. No product or sequencing decision remains for this scoped implementation.

## Approval
Approved by Svein Tore in conversation on 2026-10-27: "Godkjenner og merge til main".
This explicitly authorizes Dev implementation and a verified scoped Main merge.
Human scenario checks remain Pending and production activation is not authorized.
