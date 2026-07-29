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

## Invitation Sources

Admins can create invitations from the Customer Portal admin page. An authorized Contact create
form can also request an invitation when the technician leaves `Send customer portal invitation`
selected. Contact owns the configurable create-form default; CustomerPortal owns the invitation.

Every source uses `CreateCustomerPortalInvitation`, which enforces active Contact and Client state,
Contact-to-Client/Site scope, email identity, existing active access, pending-invitation replacement,
audit, and queued email delivery. Contact create uses the `Viewer` portal role. Ordinary Contact
edits never create or resend an invitation.

## First Slice Behavior

The foundation slice creates access and scope only. The portal dashboard intentionally shows no
Tickets, quotes, contracts, documents, bookings, or billing controls.
