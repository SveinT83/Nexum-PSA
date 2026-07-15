The Customer Portal foundation gives customer contacts authenticated access to a scoped portal
account.

Current foundation behavior:

- Admins invite existing Contacts from `/tech/admin/system/customer-portal`.
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
