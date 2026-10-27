The Customer Portal foundation gives customer contacts authenticated access to a scoped portal
account.

Current foundation behavior:

- Admins invite existing Contacts from `/tech/admin/system/customer-portal`.
- Users with `customer_portal.invite` can choose `Send customer portal invitation` while creating a
  Contact. Contact Settings controls the initial checkbox state, and the user may override it for
  that create action.
- Invitations are scoped to one Client and optionally one Site.
- Invited contacts accept through `/portal/invitations/{token}`.
- Portal login uses the existing Nexum user account system.
- Portal memberships and portal roles are separate from internal technician/admin roles.
- Portal-only users cannot open technician or admin routes.
- The portal dashboard shows the active customer scope, available memberships, and implemented
  customer-facing areas.
- Portal users can work with Tickets that are visible to their current Client/Site scope.
- Portal users can read explicitly published Documentation records and published public or matching
  client-wide Knowledge articles.
- Portal users can read and accept sent Sales quotes for their Client.
- Portal users can ask questions on sent Sales quotes.
- Portal users can read and accept sent Commercial contracts for their Client.
- Portal users can read Economy order summaries only when a technician explicitly publishes the
  order to the portal.
- Portal users can open `/portal/notifications` to read customer-safe notifications, mark them as
  read, and control email/in-app delivery preferences for portal events.

The portal does not yet expose full CPQ option selection, downstream quote conversion, bookings,
service visits, invoice payment, payment receipts, or accounting ledger behavior. Those records
remain hidden until their owning modules implement explicit customer-visible slices.

Portal access is hidden by default and must be explicitly granted per Contact and Client/Site scope.
Internal notes, internal documents, private tasks, billing margin data, credentials, assignment
logic, and technician-only audit details are never exposed by the foundation.

Contact-created invitations default to `Viewer`. On Dev from 27 October 2026, **Portal role**
selects the existing Customer admin, Site admin or Viewer role before saving. They use the same active Contact,
active Client, Site ownership, email identity, existing-access, pending-invitation, audit, and
queued-email safeguards as the Customer Portal admin form. The Contact option appears only during
create and only for users with `customer_portal.invite`; ordinary Contact edits cannot resend an
invitation. The global Contact default is off unless an administrator explicitly enables it.

Customer-visible records are owned by their domain modules:

- Ticket owns portal ticket list, create, detail, reply, safe public messages, and attachment access.
- Documentation owns technician publish/hide for structured documents.
- Knowledge owns article publication state and public/client-wide visibility.
- Sales owns portal quote list, detail, question, and acceptance for existing sent quote versions.
- Commercial owns contract list, detail, and acceptance for existing sent contracts.
- Economy owns technician publish/hide for order summaries.
- Notification owns portal notification delivery, read state, delivery preferences, and the
  customer-facing notification center.

Site-scoped portal memberships only see records that the owning module can safely tie to that Site.
Sales quotes, Commercial contracts, and Economy orders are currently client-level records, so
site-scoped portal memberships do not see those summaries until the owning domains support
site-specific splits.

Portal notifications are generated only for records that the recipient's active memberships can
see. Client-wide portal members receive client-level events. Site-scoped members receive matching
site events, and they also receive client-wide documentation or Knowledge notifications when those
records are visible across the client. Notifications never link to technician routes.

## Economy API summaries on Dev (25 October 2026)

The existing Orders experience now has customer-safe JSON equivalents on Dev under
HR-2026-10-25-ECONOMY-API (Reviewed by Svein Tore, 25 October 2026). Merged to Main in
[PR #315](https://github.com/SveinT83/Nexum-PSA/pull/315); production deployment is not verified.

1. Sign in to the existing Customer Portal with an active account/contact/membership.
2. Open Orders. Only orders explicitly published by staff for the current client are available.
   Client-level orders are not visible through a site-scoped membership.
3. Open an order to inspect its period/status, active line quantities/prices and effective VAT
   totals. These are billing summaries, not a provider invoice or payment request.

The session-authenticated read APIs are /api/v1/economy/portal/orders, /context, /{order} and
/{order}/lines under that prefix. List/detail/line paging follows the same current publication
and client/site rules. A staff bearer token cannot establish Portal identity. There is no customer
edit, publication, payment action or new customer API-token issuance. Internal source metadata,
staff identities and generation diagnostics are excluded. No permission expansion is required.

## Notification API (2026-10-27)

Verified on Dev; production rollout is separate. /api/v1/notifications/portal covers
list/detail/summary, explicit open/read-one/read-all, and preferences GET/PUT.
notifications.portal.read/update/preferences.write narrow native active contact,
account and membership access. Optional membership_id must identify an active own
membership. Internal copies/preferences are excluded; only portal Email/in-app choices
can be changed. Open resolves a currently authorized same-origin customer/site target.
See the Notification API guide for typed contracts/troubleshooting. The native
**Customer Portal > Notifications** uses the same preference/open/read actions.

## Explicit Contact API invitations on Dev - 27 October 2026

The Contact API supports the same create-time invitation with an explicit portal role.
It requires current contact.create/contact.update and customer_portal.invite permissions,
contacts.create/contacts.update/contacts.portal_invite token abilities, a valid active Client,
Contact scope and email, a body-bound preview, If-Match and Idempotency-Key. It uses the
CustomerPortal-owned validation, audit and after-commit invitation job; it does not create a
second membership lifecycle. Viewer is the default role. Job title and Contact relation are
separate and grant no portal permissions. Ordinary Contact API edits never send or resend.
The API execution switch defaults off; previews still work. Main/production deployment and
API execution activation are separate. Queued or SMTP acceptance does not verify inbox receipt.
See the Contact Domain Overview for the exact API procedure and troubleshooting.
