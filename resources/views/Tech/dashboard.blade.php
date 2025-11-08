# UI/UX Documentation – dashboard.view.tech

URL: /tech/dashboard (dashboard.view.tech)  
Access Level: dashboard.view.tech, ticket.view, inbox.view, report.view, email.admin, ticket.admin  
Date: 2025-10-15  
Status: Not implemented  
Difficulty: Medium  
Estimated Time: 3.0 hours

---

## Purpose & Function

The Technician Dashboard provides an immediate, role-based operational overview for technicians. It displays real-time data about tickets, SLAs, inbox status, and system health. Content dynamically adapts to the technician’s permissions.

Main objectives:

- Deliver a unified workspace overview after login.
- Reduce context switching by showing tickets, alerts, and metrics in one view.
- Enable quick navigation to critical areas (Tickets, Inbox, Reports, Settings).
- Ensure visibility and access align with each technician’s RBAC level.

---

## Design & Layout

Based on /layout/default.blade.php shell. Uses the standard 3-column grid:

| Area             | Slot     | Description                                             |
|------------------|----------|---------------------------------------------------------|
| Header           | header   | Page title, search/filter bar, technician status toggle |
| Sidebar (left)   | sidebar  | Queue filters, team load overview, quick navigation     |
| Content (center) | content  | Live widgets: tickets, SLA monitor, workload, metrics   |
| Rightbar         | rightbar | Alerts, inbox summary, system health, quick actions     |

Icons (Lucide recommended): activity, inbox, alert-circle, clock, check-circle, mail, users, server.  
Responsive: Hides sidebar/rightbar on mobile; content stacks vertically.

---

## Widgets & Components

### 1. Ticket Overview Widget (x-ticket-stats)

- Purpose: Real-time counts for Open, In Progress, Waiting, and SLA-breached tickets.
- Data Source: /api/tickets/stats?user=current
- Livewire: Yes (auto-refresh every 60s)
- RBAC: ticket.view

### 2. SLA Monitor (x-sla-monitor)

- Purpose: Shows SLA compliance per priority (P1–P4).
- UI: Progress bars + numeric KPI badges.
- Data Source: /api/sla/status
- RBAC: report.ticket.view

### 3. Personal Workload (x-personal-workload)

- Purpose: Displays active and assigned tickets for logged-in technician.
- UI: Compact list view with status, priority, and quick actions.
- Data Source: /api/tickets/assigned

### 4. Inbox Summary (x-inbox-summary)

- Purpose: Displays unread/untriaged messages.
- UI: Counters + quick link to /tech/inbox.
- RBAC: inbox.view

### 5. System Health (x-system-health)

- Purpose: Monitors IMAP/SMTP accounts, background queues, and job errors.
- UI: Color-coded (green/yellow/red) status indicators.
- RBAC: email.admin, ticket.admin

### 6. Alerts Stream (x-alerts-stream)

- Purpose: Lists active SLA breaches, sync errors, and alerts.
- Livewire: Real-time updates.
- RBAC: ticket.admin, superadmin

### 7. Quick Actions Panel

- Buttons: Create Ticket (ticket.create), Open Inbox (inbox.view), View Reports (report.view).
- Presence Toggle: Available / Busy / Offline.

---

## Slot Usage Example

```blade
<x-dashboard-shell>
    <x-slot name="header">
        <x-layout.page-title title="Technician Dashboard" />
    </x-slot>

    <x-slot name="sidebar">
        <x-queue-filter />
        <x-inbox-summary />
    </x-slot>

    <x-slot name="content">
        <x-ticket-stats />
        <x-sla-monitor />
        <x-personal-workload />
    </x-slot>

    <x-slot name="rightbar">
        <x-system-health />
        <x-alerts-stream />
    </x-slot>
</x-dashboard-shell>
```

---

## Role-Based Visibility

| Role         | Visible Widgets                                       |
|--------------|-------------------------------------------------------|
| Technician   | Ticket Overview, Workload, Inbox Summary, SLA Monitor |
| Tech.Admin   | \+ System Health, Alerts                               |
| Ticket.Admin | \+ Global Ticket Metrics, System Widgets               |
| Superadmin   | All widgets + configuration links                     |

Widgets hide automatically when the user lacks required permissions.

---

## Smart UX Behaviors

- Auto-refresh: Every 30–60s or via WebSocket events.
- Keyboard Shortcuts: T = new ticket, I = inbox, R = refresh dashboard.
- Hover Tooltips: Quick SLA stats and ticket queue summaries.
- PWA Notifications: New inbox message or SLA breach triggers notification.
- Adaptive Layout: Hides non-permitted or empty slots gracefully.

---

## Testing & Acceptance Criteria

- Dashboard loads within 1s after login.
- All Livewire widgets auto-refresh or respond to push updates.
- Hidden widgets never render for unauthorized users.
- Columns collapse correctly on small screens.
- Data caching for 30–60s prevents excessive API calls.

---

## Summary

This view represents the live operational workspace for technicians. It centralizes SLA, ticket, inbox, and system health information using the /layout/default.blade.php shell with Livewire components and RBAC visibility.