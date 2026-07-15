# RFC: Customer Portal Foundation

Status: Approved
Date: 2026-07-04
Owner: Codex

## Context

GitHub Discussion #165 and the Between parity audit define the need for an authenticated
customer-facing portal in Nexum PSA. Nexum already has public quote and contract links, customer
reply handling on Tickets, Contacts, Clients, Sites, Commercial contracts, Sales quotes,
Documentation, Knowledge, Calendar, Economy orders, Notification, and company branding. What is
missing is a safe authenticated surface where customer contacts can see and act on records that are
explicitly made visible to them.

This is a Level 3 change because it introduces a new customer-facing domain, authentication and
authorization decisions, database tables, public routes, admin workflows, and future cross-module
visibility contracts.

The portal must build on current Nexum ownership rules:

- Contact is the long-term canonical identity layer for external people.
- `client_users` remains a compatibility bridge while older modules migrate.
- UserManagement owns login accounts, invites, passwords, two-factor authentication, and internal
  user lifecycle.
- Client owns customer organizations and Sites.
- Domain modules own their own records and must explicitly decide what becomes customer-visible.

## Goals

- Create a singular `CustomerPortal` module as the owner of customer portal account state, portal
  memberships, portal invitations, portal navigation, portal middleware, and portal audit events.
- Use existing `user_management` users for authentication so the portal reuses Fortify, password
  hashing, status handling, and future SSO work.
- Keep portal roles and portal memberships separate from internal Spatie roles and permissions so a
  portal-only customer cannot enter the technician workspace.
- Tie portal accounts to canonical `contacts` records, not to long-term `client_users` workflows.
- Scope every portal session through explicit Client/Site memberships.
- Provide an admin-managed invitation and access workflow before any customer-visible Tickets,
  contracts, quotes, orders, documents, or payments are exposed.
- Establish a reusable portal visibility contract that domain modules can adopt one slice at a time.
- Make the first customer portal page honest: it may show account scope and empty states, but it must
  not expose controls for workflows that are not implemented.
- Prepare for mobile-friendly PWA behavior without requiring a native app or separate frontend.

## Non-Goals

- Do not expose Tickets, quotes, contracts, invoices, payments, bookings, ServiceVisits,
  Documentation, or Knowledge articles in the first foundation slice.
- Do not create a second user/password table.
- Do not use internal Spatie roles such as `Viewer`, `Tech`, `Admin`, or `Superuser` for portal
  customer permissions.
- Do not make all records with a matching Client automatically visible in the portal.
- Do not expose internal notes, private tasks, private documents, internal billing data, margin data,
  provider credentials, assignment logic, SLA internals, or technician-only audit details.
- Do not replace public quote or contract links in this RFC. They can later be connected to portal
  identity after their own slices.
- Do not build Booking, ServiceVisit, SMS, payments, or accounting integration behavior in this RFC.
- Do not hardcode Nextcloud or any external IdP as the portal login provider.

## Current Behavior

Nexum has no authenticated customer portal. The root login path is oriented toward internal users and
redirects active users with roles or direct permissions toward the technician dashboard.

Contacts are canonical external identities, but older workflows still use `client_users`. The
`user_management` table already has `contact_id`, and the `User` model has a `contact()` relation.
`client_users` also has `contact_id` and `user_id` compatibility links.

Public customer-facing surfaces exist in specific domains, such as quote and contract links, but
they are token/public-link style surfaces rather than an authenticated customer hub. Tickets can
receive customer replies, but there is no authenticated customer ticket list or ticket detail page.

## Proposed Change

Create `app/Modules/CustomerPortal`.

The CustomerPortal module owns:

- portal account records linked to `user_management` and `contacts`,
- portal memberships that scope a portal account to Clients and optionally Sites,
- portal-specific roles and capabilities,
- customer portal invitations and acceptance,
- portal route middleware and active membership resolution,
- portal dashboard/navigation shell,
- admin portal access management,
- portal audit events,
- the shared portal visibility contract consumed by other modules later.

### Identity And Authentication

Portal users authenticate through the existing `web` guard and `user_management` provider.

The portal does not create a parallel password table. A portal account points at one active
`user_management` user and one canonical `contacts` record. The matching `user_management.contact_id`
must point to the same Contact.

Portal-only access must not depend on Spatie roles or internal permissions. CustomerPortal should use
module-owned account and membership tables for customer roles.

Recommended portal role values:

- `customer_admin`: can see the Client-level portal scope and manage future customer-side portal
  preferences for that Client when implemented.
- `site_admin`: can see one Site scope and future Site-specific records.
- `viewer`: can see explicitly visible records inside assigned Client/Site scope.

The exact role names can be refined during implementation, but they must remain separate from
internal Spatie roles.

### Workspace Separation

Technician/admin routes must continue to require internal roles or direct internal permissions.
Portal memberships alone must never satisfy tech/admin middleware.

The root route should eventually route active portal-only users to the portal dashboard instead of
the technician dashboard. Users with both internal access and portal membership should enter the
internal dashboard by default unless the request explicitly targets `/portal`.

### Scope Resolution

Every portal request must resolve an active portal membership:

- account is active,
- user is active,
- user is linked to the account Contact,
- membership is active,
- membership Client is active,
- optional membership Site belongs to that Client,
- Contact still has an active relation or explicit portal membership for the Client/Site.

The resolver should produce a small portal context object with:

- portal account,
- Contact,
- active Client,
- optional active Site,
- portal role,
- allowed customer-facing capabilities.

Domain modules must consume this context instead of reading arbitrary `client_id` request values.

### Visibility Contract

CustomerPortal owns the shared vocabulary for portal visibility, but each domain owns the decision
for its own records.

Initial contract:

- records are hidden from the portal by default,
- portal visibility must be explicit,
- visibility must be scoped by Contact/Client/Site membership,
- internal notes and internal attachments remain hidden,
- customer-visible events must be auditable.

Later domain slices can implement the contract for their own records:

- Ticket owns customer-visible ticket list, detail, messages, attachments, and safe status labels.
- Sales owns quote portal identity binding and quote question/acceptance behavior.
- Commercial owns contract portal identity binding and contract acceptance behavior.
- Documentation and Knowledge own selected customer-safe document/article visibility.
- Calendar, Booking, and future ServiceVisit own upcoming work and appointment visibility.
- Economy owns customer-visible order, invoice, payment, and receipt status when provider-backed
  billing exists.
- Notification owns portal notifications and transactional messages.

### Routes

All routes must be registered from `app/Modules/CustomerPortal/routes.php`.

Recommended route families:

- public/guest invitation routes under `/portal/invitations/{token}`,
- authenticated portal routes under `/portal`,
- internal admin routes under `/tech/admin/system/customer-portal`.

Do not create `routes/client.php` or other route files in the Laravel `routes/` directory.

### User Interface

Portal views belong in `app/Modules/CustomerPortal/Views`.

The first foundation UI should be small and honest:

- portal login uses the existing login flow,
- portal dashboard shows the active Client/Site scope,
- portal switcher appears only when the account has more than one active membership,
- empty states must not advertise unfinished actions,
- admin access management shows implemented invitation/account/membership behavior only.

The customer-facing visual language should use Bootstrap and the company branding/theme system. It
should be calmer and more public-facing than the technician workspace, but still be part of the same
Nexum application.

## Impact Analysis

- **Architecture:** new singular `CustomerPortal` module.
- **Authentication:** existing `web` guard and UserManagement login are reused.
- **Authorization:** portal roles and memberships are module-owned and separate from internal
  Spatie roles/permissions.
- **Middleware:** new portal middleware/context resolver is required; tech/admin middleware must not
  treat portal memberships as internal access.
- **Routes:** new module-owned `/portal` and `/tech/admin/system/customer-portal` routes.
- **Database:** new CustomerPortal tables for accounts, memberships, invitations, and audit events.
- **UserManagement:** portal accounts create or reuse `user_management` users linked to Contacts.
- **Contact:** portal identity uses canonical Contact records and should keep `client_users` only as
  compatibility data.
- **Clients/Sites:** memberships scope portal access to active Clients and optional Sites.
- **Ticket/Sales/Commercial/Documentation/Knowledge/Calendar/Economy/Notification:** no data is
  exposed in the first slice; later slices must opt in explicitly.
- **Security:** portal-only users must not be able to reach technician/admin routes, internal API
  surfaces, private notes, internal documents, margin data, or credentials.
- **API:** no public portal API is required in the first slice; future mobile/PWA API endpoints need
  a separate explicit ability and CSRF/session decision.
- **Queues/Scheduler:** invitation notifications may use mail/notifications; no scheduler is
  required in the first slice.
- **Documentation:** module README and Knowledge docs must explain portal access, scope, and what is
  not yet visible.

## Data And Migration Plan

First foundation tables:

- `customer_portal_accounts`
- `customer_portal_memberships`
- `customer_portal_invitations`
- `customer_portal_audit_events`

Expected account fields:

- `user_id`
- `contact_id`
- `status`
- `last_login_at`
- `accepted_terms_at` nullable for future use
- `metadata`
- timestamps

Expected membership fields:

- `customer_portal_account_id`
- `client_id`
- `site_id` nullable
- `role`
- `status`
- `capabilities` nullable JSON for future refinement
- `created_by`
- `disabled_at`
- timestamps

Expected invitation fields:

- `contact_id`
- `client_id`
- `site_id` nullable
- `email`
- `role`
- `token_hash`
- `expires_at`
- `accepted_at`
- `revoked_at`
- `created_by`
- timestamps

Expected audit fields:

- `customer_portal_account_id` nullable
- `user_id` nullable
- `contact_id` nullable
- `client_id` nullable
- `site_id` nullable
- `event`
- `metadata`
- timestamps

Migration rules:

- Do not auto-enable portal access for existing Contacts.
- Do not assign internal Spatie roles to portal-only users.
- Do not make existing public quote/contract links require portal login.
- Reuse existing `user_management` records only when the email/contact identity is unambiguous.
- If a Contact has multiple Client relations, create explicit memberships only for the scopes an
  admin selects.
- If a Contact ownership conflict exists, block invitation and point staff to Contact ownership
  repair rather than guessing.

Rollback:

- Dropping portal tables removes portal account state but does not delete Users, Contacts, Clients,
  Sites, Tickets, quotes, contracts, or documents.
- Existing public customer links continue to work independently.

## Testing Plan

Foundation tests:

- CustomerPortal module routes are loaded from `app/Modules/CustomerPortal/routes.php`.
- Portal-only user can log in and open `/portal`.
- Portal-only user cannot open `/tech`, `/tech/admin`, or technician domain routes.
- Internal user without portal membership cannot open authenticated portal pages.
- User with both internal access and portal membership can access `/portal` and internal routes
  according to their internal permissions.
- Portal context resolver rejects disabled users, disabled accounts, disabled memberships, inactive
  Clients, Sites that do not belong to the membership Client, and Contact/User mismatches.
- Invitation acceptance creates or reuses a linked `user_management` user safely.
- Invitation acceptance does not assign internal Spatie roles.
- Admin access management requires an internal CustomerPortal management permission.
- Admin cannot invite a Contact with ambiguous Client/Site ownership without selecting a safe scope.
- Portal dashboard shows only implemented scope information and no unfinished workflow controls.
- Audit events are written for invitation, acceptance, membership disable, and login/scope switch
  events where applicable.

Later domain slice tests:

- Ticket visibility tests must prove hidden by default, explicit customer visibility, scope
  enforcement, safe message rendering, internal-note hiding, and attachment filtering.
- Sales and Commercial tests must prove public links and authenticated portal identity binding do not
  bypass approval rules.
- Documentation and Knowledge tests must prove internal articles/documents remain hidden.
- Economy/payment tests must prove internal draft order details and margin data remain hidden.

## Documentation Plan

- Add CustomerPortal module README when the module is implemented.
- Add Knowledge documentation for:
  - customer portal overview,
  - portal accounts and memberships,
  - inviting customer contacts,
  - scope and visibility rules,
  - what customers can and cannot see in the current release.
- Update Contact Knowledge docs to explain portal identity links.
- Update Clients Knowledge docs to explain customer memberships.
- Update UserManagement Knowledge docs to distinguish internal users from portal-only users.
- Update Ticket/Sales/Commercial/Documentation/Knowledge/Economy docs only when their own portal
  slices expose data.
- Update this RFC and the feature slice if implementation finds a safer table or middleware
  contract.

## Feature Slices

1. `docs/feature-slices/2026-07-04-customer-portal-identity-and-access-foundation.md`
   - CustomerPortal module, account/membership/invitation/audit tables, portal middleware/context,
     admin access management shell, and portal dashboard shell.
2. Future slice: Customer-visible Ticket list/detail/reply foundation.
3. Future slice: quote and contract portal identity binding.
4. Future slice: selected Documentation and Knowledge visibility.
5. Future slice: upcoming work and booking visibility after Booking/ServiceVisit direction is
   approved.
6. Future slice: customer order/invoice/payment status after Economy/payment provider direction is
   approved.
7. Future slice: portal notifications and mobile/PWA polish.

## Open Questions

No open question blocks the first CustomerPortal foundation slice.

Non-blocking decisions for later slices:

- Exact customer-facing Ticket status labels.
- Whether portal account terms acceptance is required before first data exposure.
- Whether future portal API endpoints should use session/CSRF only or token-based access for PWA
  clients.
- Whether customer admins can invite other contacts in a later release.

## Approval

Approved by Svein in conversation on 2026-07-04 after confirming the foundation should be built
complete according to the plan, including real invitation behavior for testing with a fictive portal
user.
