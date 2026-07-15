# CustomerPortal Module

CustomerPortal owns authenticated customer portal access for Contacts tied to Clients and optional
Sites.

## Ownership

CustomerPortal owns:

- portal accounts linked to `user_management` and canonical `contacts`
- portal memberships scoped to Clients and optional Sites
- portal invitations and acceptance
- portal middleware and active membership resolution
- portal audit events
- portal navigation shell

Domain modules own their own customer-visible records. Tickets, quotes, contracts, documents,
bookings, and billing data stay hidden until their owning modules implement explicit portal
visibility slices.

## Identity Rule

Portal users authenticate through the existing `web` guard and `user_management` table. Portal
roles are not Spatie roles. A portal-only user must not receive internal Spatie roles or direct
permissions.

## Routes

Public/authenticated portal routes are loaded from this module when `routes/web.php` sets
`$customerPortalPublicRoutes = true`.

- `/portal/invitations/{token}`
- `/portal`

Admin routes are loaded through the normal `/tech` module route glob:

- `/tech/admin/system/customer-portal`

## First Slice Behavior

The foundation slice creates access and scope only. The portal dashboard intentionally shows no
Tickets, quotes, contracts, documents, bookings, or billing controls.
