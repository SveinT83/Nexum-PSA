Warroom is the fixed operations dashboard for the current beta.

It gives technicians and admins a fast overview of the most important live signals across existing
domains:

- Open tickets and SLA pressure.
- Unread and unassigned ticket queue pressure.
- Active asset alerts.
- Inbox triage volume.
- Client, contract, sales, economy, storage, and Knowledge counts.
- Calendar focus.
- Integration and system health.

Warroom is intentionally hardcoded for beta. Technician-custom dashboards are planned later, but the
current dashboard must remain reliable and simple.

The Warroom route is `/tech/dashboard` and is protected by `warroom.view`.
