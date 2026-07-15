# Feature Slice: Customer Portal Quote And Contract Acceptance

Status: Done
Date: 2026-07-04
Parent: `docs/rfc/2026-07-04-customer-portal-foundation.md`
Owner: Codex

## Goal

Bind existing Sales quote acceptance and Commercial contract acceptance to authenticated Customer Portal identity.

## User-Visible Behavior

Client-scoped portal users can open Quotes and Contracts from the portal. They can accept sent Sales
quotes and sent Commercial contracts as the authenticated portal contact. They can also send a
question on a sent Sales quote.

## Scope

- Add portal Sales quote list/detail pages for sent and accepted quote versions.
- Add portal Sales quote acceptance and quote question actions.
- Extend portal Commercial contract visibility to sent quotes/contracts in addition to approved and accepted contracts.
- Add portal Commercial contract acceptance action.
- Store portal account, membership, and contact IDs on accepted quote versions and contracts.
- Record CustomerPortal audit events for quote acceptance, quote questions, and contract acceptance.
- Add tests for scope enforcement, portal identity binding, acceptance state changes, and audit events.

## Out Of Scope

- Full Sales CPQ rebuild.
- Quote option groups, add-ons, immutable CPQ snapshot tables, or downstream conversion.
- Payment collection.
- Invoice generation.
- Replacing public quote or contract links.
- Site-level Sales/Commercial records before those domains own site-specific splitting.

## Data Touched

- `sales_quote_versions.portal_accepted_account_id`
- `sales_quote_versions.portal_accepted_membership_id`
- `sales_quote_versions.portal_accepted_contact_id`
- `contracts.portal_accepted_account_id`
- `contracts.portal_accepted_membership_id`
- `contracts.portal_accepted_contact_id`
- Existing quote and contract acceptance fields
- `sales_activities`
- `customer_portal_audit_events`

## Permissions

Portal routes use authenticated CustomerPortal middleware. Public quote and contract links keep their existing token-based behavior.

## Tests

- Customer portal quote acceptance feature test.
- Customer portal contract acceptance feature test.
- Existing Sales, Commercial, and CustomerPortal regression tests after implementation.

## Documentation

- Update CustomerPortal Knowledge documentation.
- Update Sales Knowledge documentation.
- Update Commercial contract Knowledge documentation.
- Mark TODO item done after Dev verification.

## Done Criteria

- Migration runs on Dev.
- Portal quote routes are registered.
- Portal contract accept route is registered.
- Client-scoped portal users can accept their own sent quote/contract.
- Site-scoped portal users cannot see client-level quote/contract acceptance surfaces.
- Other-client portal users receive 404.
- Public token routes still pass existing tests.
- Narrow feature and regression tests pass on Dev.
