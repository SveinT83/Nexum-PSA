# RFC: Ticket API Customer Completion Flow

Status: Implemented
Approved: 2026-07-29 by Svein Tore
Implemented: 2026-07-29
Human review: `HR-2026-07-29-002` (Pending)
Date: 2026-07-29
Owner: Svein Tore / Codex
Change level: Level 3 API, authorization, database, queue-side-effect, and cross-module workflow change
GitHub issue: #194
Related RFCs: `docs/rfc/2026-06-01-domain-api-foundation.md`,
`docs/rfc/2026-07-04-customer-portal-foundation.md`, and
`docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`
Proposed ADR: `docs/adr/2026-07-29-ticket-api-message-idempotency.md`

## Context

The Ticket API can create and update Tickets, register time and cost, inspect workflow decisions,
execute transitions, and close Tickets. It cannot complete the normal customer-facing technician
flow because no official API endpoint publishes a Ticket to the Customer Portal or creates a real
outbound technician reply.

`POST /api/v1/tickets/{ticket}/external-messages` is intentionally an inbound synchronization
boundary. It stores `author_type = external`, suppresses the normal outbound customer email, strips
workflow-driving solution metadata, and must not be expanded into a technician-reply shortcut.

Issue #194 requests an official, idempotent API path that uses the same Ticket actions, permissions,
workflow policy, contact validation, notifications, and email queue as the technician UI. This is a
Level 3 change because it adds public API contracts and Sanctum abilities, changes the Ticket message
schema, and controls customer email and workflow side effects across Ticket, Integration, Email,
Notification, and Customer Portal.

## Goals

- Add an API action that publishes an eligible client Ticket through the established one-way
  Customer Portal visibility flow.
- Add an API action that creates a real public technician customer reply and queues the normal Ticket
  email exactly once.
- Support `reply_intent = send_solution` so one valid reply can satisfy technician-response and
  solution workflow facts through existing Ticket behavior.
- Make customer-reply retries concurrency-safe and idempotent without duplicate messages, email jobs,
  portal notifications, Ticket events, or workflow triggers.
- Enforce explicit least-privilege API abilities and the authenticated user's existing Ticket domain
  permissions.
- Validate Client, Contact, email, portal visibility, message ownership, and workflow action policy on
  the server.
- Return enough message, Ticket, portal-visibility, and workflow-link context for a coordinator to
  continue through existing decisions, transition, and close endpoints.
- Keep external-message synchronization unable to simulate a technician reply or solution.
- Document the OpenAPI contract, abilities, idempotency semantics, side effects, and failure cases.

## Non-Goals

- Do not add a general-purpose API for internal notes, arbitrary message types, message editing,
  message deletion, or attachments in this slice.
- Do not add a separate solution-marking endpoint when `reply_intent = send_solution` already covers
  the approved end-to-end flow.
- Do not change the meaning or abilities of `external-messages`.
- Do not expose customer-message bodies through new read-list endpoints.
- Do not allow API unpublishing or change the existing one-way portal visibility rule.
- Do not bypass Ticket workflow decisions, action guards, contact scope, email templates, or queue
  behavior.
- Do not auto-transition or auto-close beyond triggers already configured in the Ticket's pinned
  workflow.
- Do not change API Ticket creation defaults or silently publish a Ticket during `POST /tickets`.
- Do not add a new mail provider, notification channel, queue topology, scheduler task, or frontend
  UI.

## Current Behavior

- `app/Modules/Ticket/api.php` exposes Ticket CRUD, external-message sync, workflow decisions,
  transitions, escalation, close, time, costs, scope, quote, review, and evidence routes.
- `TicketController::storeExternalMessage()` uses `SyncExternalTicketMessage`, stores an external
  author, strips reply intent and solution markers, and never queues `SendTicketReplyEmail`.
- The technician web message flow validates the selected client Contact, checks
  `TicketActionGuard::CUSTOMER_REPLY`, forces public visibility for customer replies, and calls
  `AddTicketMessage`.
- `AddTicketMessage` stores `author_type = user`, queues `SendTicketReplyEmail` after commit, emits the
  established Customer Portal notification for visible client Tickets, syncs eligible relationship
  messages, evaluates workflow triggers, and records Ticket activity.
- `reply_intent = send_solution` already writes the established solution metadata and exposes both
  technician-response and solution facts to workflow evaluation.
- Technician portal publication sets `portal_visible_at` and `portal_visible_by`, adds a Ticket event,
  and emits the existing `portal_ticket_created` notification, but its helper is private to the web
  controller and is not concurrency-safe across simultaneous publication requests.
- Sanctum abilities are centrally listed by the Integration module. Existing `tickets.actions` is
  intentionally broad and there is no customer-reply or portal-publication-specific ability.
- Ticket messages have no unique idempotency key. JSON metadata lookups alone cannot prevent two
  concurrent retry requests from creating and mailing duplicate replies.
- `TicketResource` does not currently expose the portal visibility timestamp or actor.

## Proposed Change

### 1. Shared One-Way Portal Publication Action

Extract portal publication from the technician controller into a Ticket-owned
`PublishTicketToCustomerPortal` action. Both existing web callers and the new API endpoint use this
action.

The action will:

- require an active authenticated internal user with the existing `ticket.update` domain permission;
- lock the Ticket row inside the transaction;
- reject Tickets without a Client;
- return the already-published Ticket without repeating side effects;
- set `portal_visible_at` once and `portal_visible_by` to the publishing actor;
- create the existing `portal_visibility_enabled` Ticket event once;
- emit the existing `portal_ticket_created` Customer Portal notification once; and
- return whether this request performed the state change.

This makes publication naturally idempotent through the persisted one-way state and the row lock. No
separate publication idempotency ledger is required.

Add:

```text
POST /api/v1/tickets/{ticket}/portal-visibility
Ability: tickets.portal.publish
Body: { "portal_visible": true }
```

The API endpoint rejects `false`, because normal Ticket handling cannot unpublish. In addition to the
shared Client gate, the API contract requires an active Ticket Contact with an email address that
belongs to the Ticket's Client. This guarantees the published Ticket is ready for the requested
end-to-end customer-reply flow. Existing web publication retains its established Client-only
eligibility and does not gain a new Contact requirement.

The response returns the updated `TicketResource` plus `published_now`. Repeating the request returns
HTTP 200 with `published_now = false` and does not emit another event or notification.

### 2. Idempotent Customer Reply Endpoint

Add:

```text
POST /api/v1/tickets/{ticket}/messages
Ability: tickets.reply_customer
```

Supported JSON payload:

```json
{
  "type": "customer_reply",
  "body": "Arbeidet er utført.",
  "reply_intent": "send_solution",
  "reply_contact_id": 123,
  "cc": null,
  "idempotency_key": "stable-client-key"
}
```

For this slice, `type` is required and only `customer_reply` is accepted. `body` and
`idempotency_key` are required. `reply_intent` accepts the existing `customer_update`,
`request_customer_input`, and `send_solution` values. `reply_contact_id` may be omitted to use the
Ticket's selected Contact, and `cc` uses the existing normalized email parsing.

Before a new message is created, the endpoint will:

- require an active authenticated internal user with the existing `ticket.reply_customer` domain
  permission;
- require the Ticket to be Published and linked to a Client;
- run the existing `TicketActionGuard` customer-reply decision;
- resolve an active Contact with an email address inside the Ticket Client; and
- force `author_type = user`, `type = customer_reply`, and `visibility = public` regardless of
  untrusted extra fields.

The endpoint then calls the existing `AddTicketMessage` action. The existing action remains the only
owner of Ticket message persistence, events, assignment claim, first-response timestamp, outbound
email job, relationship synchronization, Customer Portal notification, owner notification, solution
metadata, and workflow triggers.

`reply_intent = send_solution` uses the same public technician-reply behavior as the web UI. No new
solution policy is invented. After the response, the coordinator can read the existing
`workflow-decisions` endpoint, perform an allowed transition, and close through the existing close
endpoint.

### 3. Concurrency-Safe Message Idempotency

Add nullable `idempotency_key` and `idempotency_fingerprint` columns to `ticket_messages`, with a
unique index on `ticket_id + idempotency_key`. Existing and non-API messages keep both values null.
The idempotency key is therefore scoped to one Ticket and remains reserved even if the message is
soft-deleted.

A Ticket-owned idempotent customer-reply action will normalize the side-effect-driving payload and
store its SHA-256 fingerprint together with the key. It will apply these rules:

- first valid request creates the message and returns HTTP 201 with `created = true`;
- same key, same authenticated actor, and same normalized payload returns the original message with
  HTTP 200 and `created = false`;
- same key with a different actor or payload returns HTTP 409 and performs no side effect;
- a key belonging to a soft-deleted message returns HTTP 409 and never recreates or resends it; and
- the unique database index is the final concurrency guard. A racing loser reloads and returns the
  winner only when actor and fingerprint match.

Idempotency lookup never bypasses token ability or current user status/domain permission. A valid
replay does not re-run current workflow/contact/portal checks that may have changed because of the
original successful request; it returns the original result without repeating side effects.

Do not implement this as a JSON-metadata-only lookup. Without a unique database constraint, parallel
requests could both pass the read check and queue duplicate email.

### 4. Response Contract

Add a small `TicketMessageResource` for the created/replayed reply. The response contains:

- message identity, Ticket identity, author, type, visibility, body, reply intent, solution flag,
  selected reply Contact, normalized CC, timestamps, and `created`;
- the refreshed `TicketResource`; and
- existing links, including `workflow_decisions`.

Extend `TicketResource` with `portal_visible_at` and `portal_visible_by` so publication results are
observable without reading internal database fields. Do not add a general conversation transcript to
Ticket responses.

### 5. Abilities And Authorization

Add two implemented abilities to the Integration-owned API ability catalog and API Management
Knowledge documentation:

- `tickets.portal.publish`: publish an eligible client Ticket to the Customer Portal;
- `tickets.reply_customer`: send a workflow-governed customer reply through the normal Ticket email
  flow.

Do not reuse `tickets.update` for outbound customer email, and do not require the broad
`tickets.actions` ability for these two narrowly defined operations. Token ability and the
authenticated user's existing Spatie domain permission must both pass. Reading workflow decisions or
performing transitions/close continues to require the existing abilities.

### 6. External Message Isolation

Leave `SyncExternalTicketMessage` and its route unchanged. Retain and extend negative tests proving
that free-form external metadata cannot set reply intent, solution markers, technician authorship, or
outbound email behavior.

### 7. Feature Slices

Implement the approved RFC in three reviewable slices:

1. shared lock-safe portal publication action, portal ability, endpoint, resource fields, and tests;
2. Ticket message idempotency migration/action, customer-reply ability/endpoint/resource, and
   negative tests; and
3. end-to-end workflow completion, OpenAPI generation, Knowledge/API Management docs, deployment
   verification, and human review.

All three slices are required before GitHub issue #194 is complete.

## Impact Analysis

### Ticket

- API routes and controller actions.
- Shared portal publication action used by web and API.
- Contact resolver reuse between web and API.
- Idempotent customer-reply action and Ticket message resource.
- Ticket message model/schema and Ticket resource fields.
- Ticket action guard, workflow decisions, events, and end-to-end feature tests.

### Integration And Authorization

- Two new least-privilege Sanctum abilities in `ApiAbilityCatalog`.
- API Management UI automatically exposes the catalog entries through its existing behavior.
- Existing tokens receive no new ability automatically; an administrator must deliberately issue or
  update a token with the required abilities.
- Existing internal Ticket permissions remain the second authorization layer.

### Email, Notification, Customer Portal, And Relationship

- No new email or notification implementation is introduced. The existing Ticket reply job and
  Customer Portal notification action are reused.
- Portal publication and customer reply must emit existing notifications at most once.
- Relationship message synchronization continues only through `AddTicketMessage` after commit.
- Queue workers keep processing the existing `SendTicketReplyEmail` job.

### API And Compatibility

- Two additive endpoints and two additive abilities.
- `TicketResource` gains additive portal fields.
- Existing Ticket, workflow, external-message, and API-key contracts remain compatible.
- Existing broad API tokens do not silently gain the new side effects unless they use explicit full
  access (`*`) or are updated by an administrator.

### Risks And Side Effects

- A duplicate API request could send duplicate customer email unless the database uniqueness and
  transaction boundary are correct.
- Returning an idempotent replay must not re-run email, notification, relationship sync, workflow
  triggers, or Ticket events.
- Reusing a key with changed content must not silently return or overwrite a different reply.
- A coordinator must not reply to an Unpublished, closed, cross-Client, missing-email, or
  workflow-blocked Ticket.
- Portal publication must not emit duplicate notifications under concurrent requests.
- `send_solution` can unlock workflow transitions; it must remain tied to an authenticated public
  technician reply and cannot be injected through external messages.
- API tokens with `*` can use the new routes by design; API Management must clearly describe the
  customer-facing side effects.

## Data And Migration Plan

Create one additive migration for `ticket_messages`:

- nullable `idempotency_key` string limited to 100 characters;
- nullable `idempotency_fingerprint` fixed 64-character hash; and
- unique composite index on `ticket_id` and `idempotency_key`.

No backfill is required. Existing rows remain null and compatible. Apply the migration before the new
customer-reply endpoint receives traffic.

Rollback removes the unique index and both columns. Messages, emails, notifications, and workflow
changes already produced while the endpoint was active are historical records and must not be
reversed automatically.

Deployment requires:

- database migration;
- `php artisan optimize:clear`;
- OpenAPI regeneration with the repository's L5 Swagger command; and
- Ticket and Integration Knowledge synchronization.

No permission seeder is required because API abilities are catalog entries rather than Spatie
permissions. No frontend build, scheduler change, or new queue worker is expected.

## Testing Plan

### Portal Publication

- Correct ability plus domain permission publishes an eligible client Ticket and returns portal
  timestamp, actor, and `published_now = true`.
- Repeating or racing publication returns the same Ticket, `published_now = false`, one event, and one
  portal notification.
- Missing ability is 403.
- Missing domain permission, Client, valid Contact, Contact email, or Client scope is rejected with no
  visibility or notification side effect.
- `portal_visible = false` is rejected and cannot unpublish.
- Existing web publication still uses the same action and retains its current Client-only behavior.

### Customer Reply And Solution

- A valid Published Ticket reply is stored once as a public `customer_reply` with
  `author_type = user` and the authenticated user as author.
- The existing reply email job, portal notification, event, relationship sync boundary, owner
  notification, first-response timestamp, and workflow trigger remain correct.
- The chosen Contact belongs to the Ticket Client and has an email address; cross-Client, inactive,
  missing, and blank-email Contacts are rejected.
- Unpublished, internal, closed, or workflow-blocked Tickets are rejected.
- Missing `tickets.reply_customer` ability or `ticket.reply_customer` domain permission is rejected.
- `send_solution` records established solution metadata and makes the configured Resolved transition
  available when the workflow's response and solution requirements are otherwise satisfied.
- The existing transition and close endpoints complete the Ticket as `completed` after the reply.

### Idempotency And Isolation

- Same actor/key/payload returns one message and one email job across retries.
- Conflicting payload, different actor, and soft-deleted result return 409 without side effects.
- A concurrency-oriented test verifies the unique constraint and recovery path where practical.
- External messages still cannot become technician replies, queue outbound email, or inject solution
  and reply-intent metadata.

### Regression And Verification

- Ticket API and route ownership tests cover the new endpoints.
- Integration API ability catalog and API-key UI tests cover both abilities.
- OpenAPI generation succeeds and includes payloads, responses, abilities, 409, and 422 behavior.
- Run focused end-to-end tests, the complete Ticket feature suite, Customer Portal and Notification
  regressions, Integration API Management tests, migration status, PHP syntax, Pint, Blade
  compilation where affected, Knowledge sync, and authenticated or permission-safe Dev API smoke
  checks without sending a real customer email.

## Documentation Plan

- Add the proposed idempotency/side-effect ADR and mark it Accepted only after RFC approval.
- Update `app/Modules/Ticket/Docs/knowledge/ticket-technical-operations.md`.
- Update `app/Modules/Ticket/Docs/knowledge/ticket-email-communication.md`.
- Update `app/Modules/Ticket/Docs/knowledge/ticket-overview.md`.
- Update `app/Modules/Ticket/Docs/knowledge/ticket-workflow-v3.md`.
- Update `app/Modules/Integration/Docs/knowledge/api-management.md`.
- Add OpenAPI attributes and regenerate the API document.
- Add three Feature Slice records under `docs/feature-slices/` after approval.
- Update `docs/TODO.md`, the RFC index, migration/deploy notes, and `docs/human-review.md`.
- Sync Ticket and Integration Knowledge through the repository commands.
- Add a public-safe website handoff only after all slices and automated verification are complete;
  keep it unpublished while human review remains open.

## Open Questions

None. The issue defines the required end-to-end behavior. This RFC chooses dedicated least-privilege
abilities, a dedicated one-way publication endpoint, a customer-reply-only message endpoint,
`send_solution` at creation instead of a separate solution endpoint, and database-enforced message
idempotency so implementation does not invent parallel Ticket workflows.

## Approval

Approved by Svein Tore on 2026-07-29.

## Implementation Result

- Added lock-safe shared portal publication for the technician UI and API.
- Added `tickets.portal.publish` and `tickets.reply_customer` least-privilege abilities.
- Added Ticket-scoped database idempotency for public technician customer replies.
- Reused `AddTicketMessage` for outbound email, portal notification, relationship sync, events,
  first response, and workflow triggers.
- Added the `send_solution` end-to-end API path through workflow decisions, Resolved, and Close.
- Applied migration `2026_07_29_120000_add_api_idempotency_to_ticket_messages` on Dev.
- Regenerated OpenAPI and completed the three Feature Slices linked from the index.
