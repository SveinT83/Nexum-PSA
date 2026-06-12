# RFC: Contact Ownership Repair API

Status: Approved
Date: 2026-06-12
Owner: Codex

## Context

Production cleanup sometimes needs to confirm whether a Contact belongs to the expected Client and
move the Contact when it is linked to the wrong Client. The current Contact API can create and update
Contacts, but it does not expose a safe repair workflow for canonical `contact_relations` and the
legacy `client_users` bridge together.

This matters during the Contact Domain transition because older Ticket, Client, Sales, Asset, and
integration workflows still read `client_users` while new workflows use Contacts.

## Goals

- Inspect one Client's Contact ownership using either internal Client ID or `client_number`.
- Return both canonical Contacts/relations and legacy `client_users`.
- Move a Contact to a target Client using either internal Client ID or `client_number`.
- Move canonical Contact relations and the legacy `client_users` bridge in one transaction.
- Support `dry_run` so cleanup can be previewed before mutation.
- Support bulk repair for a known list of Contact IDs.
- Detach a Contact from one Client without hard-deleting by default.
- Audit ownership repair API calls.
- Protect mutating routes with an explicit API scope.

## Non-Goals

- Replacing all old `client_users` reads.
- Building a Tech UI for ownership cleanup.
- Merging duplicate Contacts or duplicate legacy Client Users.
- Guessing ambiguous multi-client ownership during bulk cleanup.
- Creating Clients or Sites from the repair API.

## Current Behavior

`GET /api/v1/contacts`, `POST /api/v1/contacts`, `PUT /api/v1/contacts/{contact}`, and
`PATCH /api/v1/contacts/{contact}` exist. They can create or update Contact data and keep the
legacy bridge populated when normal create/update context includes a Site.

There is no API route that answers "which Client does this Contact actually belong to" across both
canonical and legacy data, and no route that moves both layers in a purpose-built repair operation.

## Proposed Change

Add these Contact-owned API routes:

- `GET /api/v1/clients/{client}/contacts`
- `POST /api/v1/contacts/{contact}/move`
- `POST /api/v1/clients/{client}/contacts/bulk-fix`
- `DELETE /api/v1/clients/{client}/contacts/{contact}`

`{client}` accepts internal Client ID or `client_number`. If one value resolves to multiple Clients,
the request fails instead of choosing one.

Mutating endpoints support `dry_run`. Actual mutations are transactional and record an activity-log
audit event with actor, API token ID when available, reason, before state, result, and after state.

Bulk repair is intentionally conservative. Contacts with multiple non-target Client owners or
multiple linked legacy rows are reported as conflicts so a person can review them before a direct
move.

## Impact Analysis

- Contact module owns the controller, routes, action, and tests.
- Integration module owns the new `contacts.ownership_manage` API ability in the central catalog.
- Client records are read by ID and `client_number`.
- Client Sites are used when moving legacy `client_users`.
- Activity log receives Contact ownership repair entries.
- No queues, scheduler changes, or UI changes are required.

## Data And Migration Plan

No schema migration is required. The implementation uses existing tables:

- `contacts`
- `contact_relations`
- `client_users`
- `client_sites`
- `activity_log`

Rollback is code-only. Existing data is not changed unless an actual non-dry-run repair endpoint is
called.

## Testing Plan

- Feature test Client Contact inspection by `client_number`.
- Feature test dry-run move does not mutate data.
- Feature test actual move updates `contact_relations` and `client_users`.
- Feature test bulk dry-run reports missing, no-change, and move candidates.
- Feature test detach removes ownership but keeps the Contact.
- Feature test API ability enforcement for mutating ownership endpoints.

## Documentation Plan

- Update Contact README.
- Update Contact Knowledge documentation.
- Update Integration API Management Knowledge documentation with the new scope and routes.

## Open Questions

None for this first repair slice. Duplicate merge workflows and a UI can be planned separately.

## Approval

Approved by Svein Tore Ramstad in conversation on 2026-06-12 after requesting implementation with
"Kjor pa".
