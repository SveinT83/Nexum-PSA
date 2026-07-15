# ADR: Customer Portal Identity Separation

Status: Accepted
Date: 2026-07-04
Decision Makers: Svein / Codex

## Context

Customer Portal Foundation needs authenticated customer contacts without weakening the existing
technician/admin access model.

Nexum already authenticates users through `user_management` and the `web` guard. The `User` model can
link to a canonical `Contact` through `contact_id`. Internal roles and permissions are handled by
Spatie. Existing tech/admin middleware currently treats normal roles or direct permissions as a sign
that the user belongs in the internal workspace.

If customer contacts were given ordinary Spatie roles such as a `Portal` role, existing internal
route checks could mistake them for internal users. If customer contacts used a completely separate
user table, Nexum would duplicate passwords, invite flows, status handling, 2FA, future SSO, and
account lifecycle logic.

## Decision

Use existing `user_management` users for portal authentication, but keep portal account state,
portal roles, portal memberships, and portal scope in `CustomerPortal`-owned tables.

Portal-only customer users must not receive internal Spatie roles or direct internal permissions.
Portal access is granted through CustomerPortal accounts and memberships tied to canonical Contacts,
Clients, and optional Sites.

Technician/admin access remains controlled by internal Spatie roles and permissions. CustomerPortal
memberships alone never grant access to `/tech`, `/tech/admin`, domain tech routes, or internal API
surfaces.

## Rationale

This reuses the existing, tested login stack while preserving a hard authorization boundary between
internal staff and customers.

It also matches the Contact transition plan: new long-term person workflows should use Contact
records, while `client_users` remains only a compatibility bridge for older modules.

Keeping portal roles outside Spatie avoids accidental interaction with internal middleware,
permission seeders, admin role management, and route permission fallback behavior. It also lets
portal membership be scoped per Client/Site, which is different from broad internal role membership.

## Consequences

Positive consequences:

- One login identity system remains responsible for passwords, account status, invites, 2FA, and
  future SSO.
- Portal access can be scoped per Contact, Client, and Site.
- Portal-only users do not gain internal workspace access by receiving a portal role.
- Future domain slices can consume a small portal context instead of each inventing customer scope.

Negative consequences:

- CustomerPortal must implement its own role/membership resolver instead of relying on Spatie.
- Tech/admin middleware and root-login routing need explicit tests for portal-only users.
- Admin role screens must not be treated as the portal access management surface.
- Users with both internal and portal access need clear routing and account context behavior.

## Alternatives Considered

Use Spatie roles for portal users:

- Rejected because the existing tech/admin checks already use role/permission presence as an
  internal-access signal. A portal role could accidentally satisfy the wrong layer.

Create a separate `portal_users` authentication provider:

- Rejected for the first foundation because it duplicates login, password, invite, status, 2FA, and
  future SSO behavior.

Keep using only public signed links:

- Rejected because the target product needs authenticated customer history, scope switching,
  customer-safe messages, upcoming work, documents, and future payment/status surfaces.

## Follow-Up

- Implement the CustomerPortal module through the foundation feature slice after RFC approval.
- Add tests proving portal-only users cannot enter tech/admin routes.
- Update UserManagement, Contact, and Clients Knowledge docs when portal identity is implemented.
- Revisit this ADR if a future SSO/Identity RFC changes the shared authentication provider.
