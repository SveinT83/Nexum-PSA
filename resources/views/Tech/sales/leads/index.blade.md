# tech.sales.leads — View Specification (Lead Overview)

**Creation date:** 2025-10-29
**URL:** `tech.sales.leads`
**Access:** `sales.view`, `client.view`, `sales.manage` (for editing priority or ownership)
**Controller:** `App\Http\Controllers\Tech\Sales\LeadsController@index`
**Livewire/Components:** `App\Livewire\Tech\Sales\Leads\*`
**Status:** Not started
**Difficulty:** Medium
**Estimated time:** 5.0 hours

---

## Purpose

Displays all **clients without active contracts** as sales leads. Enables filtering, sorting, and quick outreach (phone/email) for prospecting. The list is designed for fast operation during sales sessions or cold-calling, with inline visibility of essential client data.

---

## Layout (Bootstrap)

**Regions:**

* **Top Header** – Title, search bar, and action buttons.
* **Main Section** – Table of leads with dynamic filters and sorting.
* **Right Slim Panel** – Quick stats (total leads, average users/assets, top industries).

---

## Components & Functions

### Header

* **Title:** `Leads`
* **Buttons:** `+ Add Lead`, `Refresh`, `Export`, `Filters`
* **Search field:** Global search (client name, phone, email)

### Filters (collapsible sidebar/modal)

* Include/exclude:

  * No contract *(default view)*
  * Expired contracts
  * Draft contracts
* **Sort by:** Manual priority ↓ → Users ↓ → Assets ↓ → Name A–Z
* **Industry filter:** Dropdown list (industry taxonomy)
* **Category filter:** e.g. SMB, Enterprise, Private
* **Min/max users or assets:** numeric filters
* **Assigned owner:** user selector (sales rep)

### Table Columns

| Column   | Description                            |
| -------- | -------------------------------------- |
| Client # | Unique ID, clickable to open lead view |
| Name     | Full client name (company/person)      |
| Phone    | Click-to-call (tel: link)              |
| Email    | Click-to-email (mailto: link)          |
| Industry | From client profile                    |
| Users    | Count of active users                  |
| Assets   | Count of registered assets             |
| Priority | Manual ranking (editable in lead view) |
| Owner    | Assigned salesperson                   |
| Actions  | Quick buttons                          |

### Row Actions (icons)

* **Open Lead** → `tech.sales.leadshow:{clientId}`
* **Call** (phone icon)
* **Send Email** (envelope icon)
* **Assign Owner** (user icon)
* **Add Note** (sticky-note icon)
* **Convert to Prospect** (arrow-right icon)
* **Ignore/Remove** (x-circle icon)

---

## Behavior

* Default view shows all **clients without contracts**.
* Optional filters can include expired or draft-contract clients.
* Clicking a lead opens the detailed view: `tech.sales.leadshow`.
* Data updates live (polling or websocket refresh).
* Sorting and filters persist per user session.

---

## Suggested Widgets (Right Panel)

* **Lead Summary Card:** total leads, contacted today, open follow-ups.
* **Industry Breakdown Pie:** quick distribution by sector.
* **Top 5 by Priority:** compact list.

---

## Notes

* Inline editing limited to the dedicated lead view.
* Designed for fast navigation between cold-call sessions.
* Consistent layout with other sales views (same header template and table structure).
