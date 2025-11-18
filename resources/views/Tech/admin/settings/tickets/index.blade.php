@extends('layouts.default_tech')

@section('title', 'Tech Dashboard')

@section('pageHeader')
    <h1>Ticket Settings</h1>
@endsection

@section('content')
# tech.admin.settings.ticket.index – Functional Specification

**Date:** 2025-10-20
**Controller:** `App\\Http\\Controllers\\Tech\\Admin\\Settings\\Tickets\\SettingsController`
**Access:** `ticket.admin`, `ticket.rules.manage`, `ticket.workflow.manage` (view only for the last two; no inline management)
**Status:** Not completed
**Difficulty:** Medium
**Estimated time:** 5.0 hours

---

## Purpose

This view provides a unified configuration interface for all **Ticket-related system settings**, excluding Rule and Workflow management (which reside on their own subpages). It consolidates all core ticket options into a single autosaving form to simplify administration and ensure immediate effect of changes.

---

## URL & Scope

* **URL:** `/tech/admin/settings/tickets`
* **Namespace:** `tech.admin.settings.tickets`
* **Layout:** Shared Admin Template – Top Header / Main Content / Right Slim Rail
* **Autosave:** Every field change triggers an inline Livewire save (no manual submit)
* **Access Roles:** `superadmin`, `tech.admin`, `ticket.admin`

---

## UI Structure

Single-page layout with grouped collapsible panels (accordion). Each panel represents one logical configuration domain. All inputs use autosave on change. A single **“Restore Defaults”** button resets all ticket settings to system defaults.

### Sections

#### 1. General Settings

* Default Queue (select)
* Default Category (select)
* Default Priority (P1–P4 dropdown)
* Default Ticket ID format (text)
* Auto-assign owner (dropdown: none / round-robin / by queue lead)
* Auto-close resolved tickets after X days (numeric input)
* Default Language (dropdown)
* Default Timezone (dropdown)

#### 2. Time Tracking

* Enable manual Start/Stop timer (toggle)
* Enable fallback estimation (toggle)
* Rounding rule (dropdown: 1 / 5 / 15 / 30 / 60 minutes)
* Require time entry before Resolve/Close (toggle)
* Default cost account mapping (select)

#### 3. Email & Templates

* Default outbound account (select)
* Use per-queue sender addresses (toggle)
* Default signature / footer (textarea)
* Include disclaimer “Do not send sensitive information” (toggle)
* Enable automatic translation of replies (toggle)

#### 4. SLA & Priorities

* Default SLA policy (dropdown)
* Pause conditions (checkboxes: Waiting on Customer, On Hold)
* Escalation thresholds (inputs for 50%, 80%, 100%)
* Impact × Urgency → Priority matrix (modal link)

#### 5. Statuses

* Editable labels and order for base statuses (New, In Progress, Waiting, On Hold, Resolved, Closed)
* Each line editable via inline form with drag-and-drop reordering

#### 6. Custom Fields

* Enable custom fields per Queue/Category (toggle)
* Button: **Manage Custom Fields** (opens modal)
* Indicator: number of active custom fields

#### 7. Security & Permissions

* Role matrix preview (read-only, showing who can override SLA, edit time logs, publish KB, etc.)
* Toggles:

  * Allow technicians to override SLA (toggle)
  * Allow time log edits after Resolve (toggle)
  * Require justification for ticket transfer (toggle)

#### 8. Notifications

* Enable internal notifications for ticket assignment and updates (toggle)
* Allow client confirmation requests (toggle)
* Default notification templates link (opens modal)

---

## Additional Features

* **Inline Save Feedback:** small toast or icon indicating “Saved ✓” after every autosave.
* **Last Saved Display:** omitted (no timestamp or history per section).
* **Immediate Effect:** all changes apply instantly; no manual reload required.
* **Error Handling:** invalid input shows inline validation messages; autosave pauses until resolved.
* **Restore Defaults:** confirmation modal with description of affected settings.

---

## Layout Components

* **Top Header:** Breadcrumb (Settings → Tickets), page title, and Restore Defaults button.
* **Main Section:** Accordion with 8 panels (as listed above).
* **Right Slim Rail:** Contextual help widget (Livewire) showing last changed setting, link to documentation.
* **Icons:** Lucide icons for each section header (e.g., Gear, Clock, Mail, AlertTriangle, Tags, Shield, Bell).
* **Widgets:**

  * Livewire autosave spinner per section.
  * Toast notifications for save confirmation.
  * Optional inline markdown preview for signature template.

---

## Design & Layout

* Uses Bootstrap grid system.
* Panels styled with card components, collapsible accordions.
* Each input labeled and aligned for readability (2-column layout on desktop, stacked on mobile).
* Dark mode compatible.

---

## Future Extensions

* Add per-queue override capability (e.g., SLA, default workflow, timer rules).
* Add audit trail per setting (change log table).
* Integrate cross-module dependencies (e.g., email domain hints, RMM triggers).

---

**End of Document**
@endsection

@section('sidebar')
    <h3>Tech Sidebar</h3>
    <ul>
        <li><a href="#">System Status</a></li>
        <li><a href="#">Task Management</a></li>
        <li><a href="#">Reports</a></li>
    </ul>
@endsection

@section('rightbar')
    <h3>Notifications</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection