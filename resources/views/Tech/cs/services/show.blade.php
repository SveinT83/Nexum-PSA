# tech.cs.services.show – Functional Specification

Date: 2025-10-17
Status: Not completed
Difficulty: Medium
Estimated Time: 3.5 hours
Controller: App\Http\Controllers\Tech\CS\Services\ServiceShowController
URL: /tech/cs/services/{service}
Access: `service.view`

---

## 1) Purpose

Display full, read-only details of a single service, including pricing, timebank rules, availability, and associated clients/contracts. Serves as a central inspection view for admins and technicians.

---

## 2) Layout (Bootstrap standard)

**Top Header:**

* Breadcrumbs: Services → [Service Name]
* Action Buttons (right side): Edit, Archive/Unarchive, Back to list

**Main Content Area (center):**
Divided into structured info panels for readability:

### A. Overview

* Icon + Service Name + SKU
* Status badge (Draft / Published / Archived)
* Queue (Category) chip
* Default discount indicator (if any)

### B. Pricing

* Price excl. VAT + currency (e.g. NOK 500 / month)
* Billing interval: Minutes / Monthly / Yearly
* Unit type: none / per user / per device / per server
* One-time fee: amount + recurrence policy (if defined)
* Taxable: yes/no (from settings)
* Timebank: enabled/disabled + interval (if applicable)

### C. Availability

* Audience: All / Business / Private
* Addon of: [Linked Service] (if set)
* Client whitelist: List of clients (shows badges or table)
* Orderable in Client Portal: Yes / No
* Queue default: linked Ticket Queue name

### D. Descriptions & Terms

* Short description (text)
* Long description (text)
* Terms (list): each displayed as separate bullet/paragraph

### E. Active Clients & Contracts

* Table of active clients using this service: Client Name | Contract Name | Status | Next Renewal Date
* Supports pagination or collapsible sections if many results.
* Clicking client name → opens `/tech/clients/{client}`.

### F. Audit Summary (read-only)

* Created by / Created at
* Last modified by / Updated at
* Source of last update (manual / automated)

**Right Slim Rail:**

* Quick metadata summary: SKU, Queue, Interval, Status, Taxable, Published Date
* Mini card: “Related Addons” (lists dependent services)
* Mini card: “Used in Contracts” (short count with link to full list)

---

## 3) Behavior

* View is read-only; edit actions redirect to `tech.cs.services.edit`.
* Archive button available only if service not referenced by active contracts.
* If service is Archived, highlight warning bar at top: “This service is archived and cannot be added to new contracts.”
* When viewing a Draft (unpublished) service, show banner: “Not visible to contracts or customers until published.”

---

## 4) Widgets & Components

* **ServiceSummaryCard** – icon, name, price, interval, unit type
* **PricingDetailsCard** – one-time fee, taxable info, discount info
* **AvailabilityPanel** – audience, addon, whitelist, portal orderable flag
* **ClientsTableWidget** – dynamic (Livewire optional)
* **TermsAccordion** – collapsible list of terms
* **AuditFooter** – creator, timestamps, update info

---

## 5) Livewire (optional enhancements)

* Used to lazy-load client and contract lists.
* Supports real-time updates if contract or client relationship changes.

---

## 6) Validation & Error Handling

* Invalid service ID → 404 Not Found
* If user lacks permission → 403 Forbidden
* When service data partially missing (e.g. missing queue): show placeholder “Not assigned.”

---

## 7) Estimated Development Time

| Task                              | Time     |
| --------------------------------- | -------- |
| View layout & controller binding  | 1.0h     |
| Service data binding & formatting | 1.0h     |
| Active clients/contracts table    | 1.0h     |
| Audit & widgets integration       | 0.5h     |
| **Total**                         | **3.5h** |

---

## 8) Notes for GitHub Copilot

* Use shared Bootstrap components (cards, accordions, tables).
* Do not allow inline edits; route to Edit view for changes.
* Maintain route naming consistency (`tech.cs.services.show`).
* Prepare partials (cards/panels) for reuse in edit and index views.
* Include placeholder components for audit and related clients for later integration.
