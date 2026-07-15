# Feature Slice: Customer Portal Commercial And Economy Summary

Status: Done
Date: 2026-07-04
Parent: `docs/rfc/2026-07-04-customer-portal-foundation.md`
Owner: Codex

## Goal

Expose customer-safe contract summaries and explicitly published economy order summaries inside the authenticated Customer Portal.

## User-Visible Behavior

Portal users can open Contracts and Orders from the portal dashboard. Client-scoped portal users see approved or accepted contracts for their Client and economy orders that technicians have explicitly published. Site-scoped portal users do not see Commercial/Economy summaries because those records are currently client-level only.

## Scope

- Add portal contract list/detail pages for `approved` and `won` contracts.
- Add explicit portal visibility to Economy orders.
- Add portal order list/detail pages for explicitly published orders.
- Add a technician publish/hide action for Economy orders.
- Add CustomerPortal audit events for Economy order visibility changes.
- Add tests for contract visibility, order visibility, customer scope, site-scope denial, and audit events.

## Out Of Scope

- Contract acceptance or signing inside the authenticated portal.
- Sales quote portal identity binding.
- Invoice/payment provider integration.
- Customer-side payment, receipts, accounting ledger, or downloadable invoice PDFs.
- Site-level splitting for Commercial contracts or Economy orders before those domains own site-specific records.

## Data Touched

- `economy_orders.portal_visible_at`
- `economy_orders.portal_visible_by`
- Existing `contracts.approval_status`
- Existing contract line and economy order line display data
- `customer_portal_audit_events`

## Permissions

Portal routes use authenticated CustomerPortal middleware. Technician Economy publish/hide uses the existing `economy.order_manage` permission mapping.

## Tests

- Customer portal Commercial/Economy feature test.
- Economy module regression test after adding the tech visibility action.
- CustomerPortal foundation/doc/ticket tests after route additions.

## Documentation

- Update CustomerPortal Knowledge documentation.
- Update Commercial contract Knowledge documentation.
- Update Economy order Knowledge documentation.
- Mark this TODO item done after Dev verification.

## Done Criteria

- Migration runs on Dev.
- Portal contract and order routes are registered.
- Contracts are client-scoped and limited to approved/accepted statuses.
- Economy orders are hidden until explicitly published.
- Site-scoped memberships cannot see client-level contract/order summaries.
- Narrow feature tests pass on Dev.
