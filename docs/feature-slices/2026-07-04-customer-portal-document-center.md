# Feature Slice: Customer Portal Document Center

Status: Done
Date: 2026-07-04
Parent: `docs/rfc/2026-07-04-customer-portal-foundation.md`
Owner: Codex

## Goal

Expose customer-safe Documentation records and Knowledge articles inside the authenticated Customer Portal.

## User-Visible Behavior

Portal users can open Documents and Knowledge from the portal dashboard. They only see records that
match their active Client/Site membership and are explicitly safe for customer access.

## Scope

- Add explicit portal visibility to Documentation records.
- Add portal Documentation list/detail pages.
- Add portal Knowledge list/detail pages for `published` articles with `public` or matching
  `client-wide` visibility.
- Add technician publish/hide action for client-scoped Documentation.
- Add tests for scope enforcement and hidden internal content.

## Out Of Scope

- Customer editing of documents.
- Public anonymous document links.
- File attachment libraries.
- Payment, invoice, quote, contract, booking, or ServiceVisit behavior.

## Data Touched

- `documentations.portal_visible_at`
- `documentations.portal_visible_by`
- Existing `articles.visibility`, `articles.status`, and `articles.client_scope_id`
- `customer_portal_audit_events`

## Permissions

Portal routes use authenticated CustomerPortal middleware. Technician Documentation publish/hide
uses existing Documentation update permission mapping.

## Tests

- Customer portal document/knowledge scope test.
- Documentation publish/hide test.
- Existing Documentation, Knowledge, and CustomerPortal tests after implementation.

## Documentation

- Update CustomerPortal, Documentation, and Knowledge Knowledge docs.
- Mark TODO item done after Dev verification.

## Done Criteria

- Migration runs on Dev.
- Portal document and knowledge routes are registered.
- Internal, draft, unscoped, hidden, and other-client records stay hidden.
- Narrow feature tests pass on Dev.
