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
- My Day personal work focus for the signed-in technician.

Warroom is intentionally hardcoded for beta. Technician-custom dashboards are planned later, but the
current dashboard must remain reliable and simple.

The Warroom route is `/tech/dashboard` and is protected by `warroom.view`.

My Day is available at `/tech/my-day` and is also protected by `warroom.view`. It shows the signed-in
technician's open assigned tickets, open assigned tasks, and today's calendar events in a compact
mobile-friendly surface. It reads existing Ticket, Task, and Calendar data; those source domains keep
ownership of their own workflows and write actions.
