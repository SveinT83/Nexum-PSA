# RFC: Domain API Foundation

Status: Approved
Date: 2026-06-01
Owner: Codex

## Context

Nexum PSA already exposes a small Sanctum-protected API for Clients and Assets. The API management
UI could create Sanctum tokens, but tokens were created with broad access and the UI still advertised
scopes as future work.

External automation, n8n workflows, mobile clients, and future AI tooling need stable APIs with clear
scope ownership before more domains add endpoints.

## Decision

Build the API foundation in small slices.

The first slice implements:

- A central API ability catalog owned by the Integration module.
- Scoped API key creation in API Management.
- Sanctum ability enforcement on implemented Client and Asset read APIs.
- Documentation that only lists implemented API scopes.

Implemented abilities:

- `clients.read`
- `assets.read`

## Rules

- API entry routes remain under `routes/api.php`, but domain API routes must live in module route files.
- Domains that already have only API routes may use `app/Modules/{Domain}/api.php`.
- Domains that need both Tech and API routes may keep a guarded API branch in `routes.php`.
- API routes must use Sanctum authentication and explicit abilities.
- API keys must not default to hidden full access unless the admin explicitly selects full access.
- Do not add API abilities for domains before the matching routes and tests exist.

## Impact

- Integration owns API key management and ability catalog.
- Client and Asset own their API controllers/resources and route registrations.
- Tests must verify token abilities are persisted and enforced.

## Follow-Up

Add domain API slices one at a time for Tickets, Contacts, Knowledge, Storage, Calendar, and other
domains after their read/write contracts are documented.
