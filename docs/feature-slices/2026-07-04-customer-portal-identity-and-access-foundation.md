# Feature Slice: Customer Portal Identity And Access Foundation

Status: Done
Date: 2026-07-04
Parent: `docs/rfc/2026-07-04-customer-portal-foundation.md`
Owner: Codex

## Goal

Create the CustomerPortal identity, membership, invitation, scope, and route foundation before any
domain records are exposed to customers.

## User-Visible Behavior

Internal admins can manage portal access for existing Contacts. Invited customer contacts can accept
portal access and open a small `/portal` dashboard showing their active Client/Site scope.

Customers do not see Tickets, quotes, contracts, invoices, bookings, documents, Knowledge articles,
or payment controls in this slice.

## Scope

- Create `app/Modules/CustomerPortal`.
- Add module routes, controllers, views, actions, support classes, tests, and module README.
- Add CustomerPortal view namespace registration.
- Add CustomerPortal admin permission names to the permission seeders.
- Add CustomerPortal admin route permission mapping for implemented admin pages.
- Add portal account, membership, invitation, and audit event tables/models.
- Add portal account and membership resolver tied to existing `user_management` users and canonical
  Contacts.
- Add portal middleware for authenticated `/portal` routes.
- Update root/auth routing only as needed so portal-only users go to the portal instead of the
  technician dashboard.
- Add an admin access management page under `/tech/admin/system/customer-portal`.
- Add invitation acceptance flow with safe create/reuse behavior for `user_management` users.
- Add a minimal portal dashboard and membership switcher.
- Add Knowledge documentation for the foundation behavior.
- Update TODO status after implementation.

## Out Of Scope

- Customer-visible Ticket list/detail/reply behavior.
- Quote or contract portal identity binding.
- Booking, ServiceVisit, online scheduling, and upcoming work.
- Economy invoice/payment status.
- Customer-visible Documentation or Knowledge content.
- Customer-managed invitations.
- Separate portal API tokens.
- Nextcloud-backed login or external IdP implementation.
- SMS notifications.
- PWA install prompts or offline behavior.

## Data Touched

- New CustomerPortal tables only:
  - `customer_portal_accounts`
  - `customer_portal_memberships`
  - `customer_portal_invitations`
  - `customer_portal_audit_events`
- Existing `user_management` rows may be created or linked during invitation acceptance.
- Existing `contacts`, `clients`, and `client_sites` are read for scope validation.
- Existing `client_users` may be read only for compatibility checks; new long-term portal logic must
  use Contact records.

## Permissions

Internal permissions:

- `customer_portal.view`
- `customer_portal.manage`
- `customer_portal.invite`

Portal customer roles are not Spatie permissions. They are CustomerPortal membership roles.

Recommended membership roles:

- `customer_admin`
- `site_admin`
- `viewer`

Portal routes require active portal account and membership state, not internal role permissions.

## Tests

- Permission seeding includes CustomerPortal internal permissions.
- Superuser/Admin receive CustomerPortal management permissions according to existing role seeding
  conventions.
- CustomerPortal admin routes require internal permissions.
- Portal-only users can open `/portal`.
- Portal-only users cannot open `/tech`, `/tech/admin`, or a representative tech domain route.
- Internal users without portal membership cannot open authenticated portal pages.
- Dual internal/portal users can open both surfaces when properly authorized.
- Invitation acceptance creates or reuses a `user_management` user without assigning internal
  Spatie roles.
- Invitation acceptance links user, Contact, Client, optional Site, account, and membership.
- Portal resolver rejects inactive users, disabled accounts, disabled memberships, inactive Clients,
  invalid Site/Client pairs, and User/Contact mismatches.
- Portal dashboard renders implemented scope information and no unfinished controls.
- Audit events are written for invitation creation, acceptance, disabling membership, and membership
  switch where applicable.

## Documentation

- Add CustomerPortal README.
- Add CustomerPortal Knowledge overview.
- Update Contact Knowledge docs for portal identity links.
- Update Clients Knowledge docs for portal memberships.
- Update UserManagement Knowledge docs to distinguish internal users from portal-only users.
- Keep the parent RFC and ADR linked from module docs.

## Done Criteria

- CustomerPortal module exists and follows the module architecture rules.
- Portal identity uses existing `user_management` users and Contact links.
- Portal roles/memberships are separate from internal Spatie roles.
- Portal-only user access to technician/admin routes is blocked by tests.
- Admins can create, view, and disable portal memberships for existing Contacts.
- Customers can accept an invitation and open an honest portal dashboard.
- No customer-visible domain data is exposed before matching domain slices exist.
- Narrow CustomerPortal feature tests pass on the Dev server before code is pushed.

## Verification

- Dev migration ran for `2026_07_04_120000_create_customer_portal_foundation_tables`.
- Dev seeders ran for permissions, roles, and default email templates.
- Dev Knowledge sync ran for `CustomerPortal`.
- `HOME=/tmp php artisan test app/Modules/CustomerPortal/Tests/Feature/CustomerPortalFoundationTest.php`
  passed with 9 tests and 53 assertions.
- `HOME=/tmp php artisan test app/Modules/UserManagement/Tests/Feature/UserInviteTest.php` passed with
  8 tests and 37 assertions.
- `HOME=/tmp php artisan test app/Modules/Email/Tests/Feature/EmailModuleTest.php --filter
  'email_accounts_can_be_marked_as_marketing_default_sender|default_email_templates_are_seeded'`
  passed with 2 tests and 17 assertions.
