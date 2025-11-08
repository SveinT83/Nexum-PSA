# tech.clients* — General Documentation

**URL root:** `tech.clients.*`
**Access & permissions:** `client.view` (base), `client.create` (new client), `client.edit` (update), `client.admin` (advanced / LDAP / policies)
**Creation date:** 2025-10-31
**Controller base path:** `App\Http\Controllers\Tech\Clients\*` and Livewire `App\Livewire\Tech\Clients\*`
**Status:** In progress
**Difficulty:** Medium
**Estimated time:** 5.0 hours (index 2.0h, show 2.0h, partials 1.0h)

## 1. Purpose

Provide technicians with a fast, scoped view of **all customers (clients)** in the tenant. From here they can:

* search/filter for a client,
* open the client dashboard (scoped to that client),
* start common actions (new site, new user, open docs, open contracts/timebank),
* see active tickets/tasks tied to that client.
  The view is **read-first**: create/edit happens in subviews or via action buttons.

## 2. Views

### 2.1 `tech.clients.index`

* **URL:** `tech/clients`
* **Access:** `client.view`
* **Controller:** `App\Http\Controllers\Tech\Clients\IndexController@index`
* **Status:** In progress
* **Difficulty:** Low–Medium
* **Estimated time:** 2.0h

**Layout (Bootstrap):**

* **Top section:** page title (Clients), global search (name, org no, domain), filter dropdown (status: active/inactive), optional queue/tag filter later.
* **Main section (left/wide):** table/list of clients (Name, Org no, Default contact, Ticket count badge (optional), Last activity). No contract/timebank columns here (must click client to see it).
* **Right-side panel (narrow):** quick help, recent clients (MRU), possibly a widget for "clients with open tickets".

**Behavior:**

* List is **global** for all with `client.view` (no per-tech filtering by default) — matches decision from user.
* Clicking a row → `tech.clients.show:{clientId}`.
* Search is instant (Livewire) and scoped to clients only (NOT tickets).
* Sorting on name and last activity.

**Components to mark as reusable:**

* `clients.list.table` (standard table with actions)
* `clients.filters.bar` (search + filter)
* `clients.panel.recent` (right-side MRU widget)

### 2.2 `tech.clients.show`

* **URL:** `tech/clients/{client}`
* **Access:** `client.view`
* **Controller:** `App\Http\Controllers\Tech\Clients\ShowController@show`
* **Status:** In progress
* **Difficulty:** Medium
* **Estimated time:** 2.0h

**Scope rule (important):** when inside this view, **all child lists and widgets are hard-scoped to this client** (tickets, tasks, sites, users, services, docs link). Even if the technician has global `ticket.view`, the embedded list here only shows items for this client.

**Layout (Bootstrap, standard for tech views):**

* **Top section:** client name, org no, status badge (active/inactive), small badge for default timezone/language.
* **Action bar (under top):**

  * `New site` → `tech.clients.sites.create` (or same form as edit)
  * `New user` → `tech.clients.users.create`
  * `Open documentations` → goes to documentations view pre-scoped to this client (route to be mapped later)
  * `Contracts / Timebank` → goes to sales/contracts module for this client
  * (optional) `Edit client` → if `client.edit`
* **Main section (left/wide):** 3 stacked cards:

  1. **Client summary card**: name, org no, billing email, notes, number of sites/users, last ticket activity.
  2. **Active tickets list (embedded)**: table-like list, 5–10 rows, columns: Ticket ID, Subject, Queue, Status, Owner. Row click → `tech.tickets.show:{ticketId}`. Data loaded from ticket module, filtered by client.
  3. **Open tasks for this client (optional)**: list of tasks tied to tickets for this client. Row click → `tech.tasks.show:{taskId}`.
* **Right-side panel (narrow):**

  * **Sites (compact list):** show first 5 sites with link "View all" → `tech.clients.sites.index:{client}`
  * **Users (compact list):** show first 5 users with link "View all" → `tech.clients.users.index:{client}`
  * **Active services (compact list):** simple list (service name, status), loaded from sales/contracts. Click → open relevant sales/order/contract view.
  * **Timebank / contract hours widget:** single box: "Remaining: Xh / Yh". If none → show "No timebank configured".

**Notes:**

* Tickets and tasks are **lists**, not just counts — user requirement.
* Contracts/timebank are **not** shown in `index`, only here — user requirement.

## 3. Subviews / Related routes

These already exist in route map and are only referenced here:

* `tech.clients.sites.index` → list all sites for the client (access: `site.view`).
* `tech.clients.documents.index` → list documents/docs for this client (access: `documents.view.site`). Triggered from the button in action bar.
* `tech.clients.users.index` → list all users under this client (access: `user.view`).
* `tech.clients.users.create` → create new user under this client (access: `user.create`).

All of these inherit the **client scope** from `tech.clients.show` and should validate that a client is selected.

## 4. Permissions & roles (summary)

* Base listing: `client.view`
* New client: `client.create`
* Edit client / LDAP / policies: `client.admin` or `client.edit`
* Create site from client: `site.create`
* Create user from client: `user.create`
* View tickets in client view: requires `ticket.view` but filtered by client
* View services/timebank: `service.view` or `sales.admin` depending on how sales is wired in this tenant

## 5. Smart UX

* When a ticket is opened from client view, return/back should preserve the client filter (i.e. user lands back on the same client, not on global ticket list).
* Right panel widgets should be lazy-loaded to keep show-view fast.
* In `index`, remember last search per user (localStorage) so technicians can keep their client list filtered.

## 6. Reusable layout components

* `x-tech-page` (top + main + right panel) — same as tickets.
* `x-client-header` (name + org no + actions)
* `x-client-tickets-list` (embedded view, client-scoped)
* `x-client-sidepanel` (sites, users, services, timebank)

## 7. Status

This file is a **general** doc for `tech.clients*`. Detailed docs for:

* `tech.clients.index`
* `tech.clients.show`
* `tech.clients.users.*`
* `tech.clients.sites.*`
  …skal lages som egne filer etterpå.
