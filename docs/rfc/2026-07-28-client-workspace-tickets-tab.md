# RFC: Client Workspace Tickets Tab

Status: Approved / Implemented
Date: 2026-07-28
Owner: Svein Tore / Codex
Change level: Level 3 cross-module Client/Ticket UI and permission-aware data access
GitHub issue: #188
Implementation approval: Approved by Svein Tore on 2026-07-28

## Context

The Client profile is already an operational workspace for Assets, Sites, Contacts, Contracts,
Time, Signals, Tasks, and Custom Fields. Technicians currently have to leave that workspace and
filter the Ticket index separately to see the selected Client's tickets.

GitHub issue #188 requests a Tickets tab immediately after Contacts. The tab must use the existing
Ticket detail surface, enforce the current Ticket permission boundary, and never include tickets
from another Client. Because the change makes the Client module consume Ticket-owned data and adds
new user-visible cross-module behavior, it is classified as Level 3.

## Goals

- Add a Tickets tab to the Client workspace for technicians who can view tickets.
- Use this tab order: Assets, Sites, Contacts, Tickets, Tasks, Time, Contracts, Signals, Custom
  Fields.
- List non-deleted tickets linked directly to the selected Client.
- Link every listed ticket to the existing Ticket detail route.
- Keep the count badge and rendered list based on the same permission-filtered collection.
- Preserve all existing Client workspace tabs and their behavior.
- Keep Ticket query and permission rules owned by the Ticket module.

## Non-Goals

- Do not add a new Ticket detail page, Client route, API endpoint, permission, or database field.
- Do not change Ticket ownership, assignment, lifecycle, portal visibility, or workflow behavior.
- Do not expose soft-deleted tickets.
- Do not add inline Ticket editing or Ticket creation inside this first tab.
- Do not redesign the Client Summary card; that is tracked separately from issue #188.
- Do not change the global Ticket index's default ownership or lifecycle filters.

## Current Behavior

- `ClientController::show` prepares related Client records and renders one Client show view.
- The Client workspace tabs currently appear as Assets, Sites, Contacts, Contracts, Time, Signals,
  Tasks, and Custom Fields.
- The Client controller already queries Ticket IDs to include unfinished Ticket-owned Tasks in the
  Client Tasks tab, but it does not provide a Ticket list to the view.
- The tech Ticket routes are protected by `ticket.view` through
  `EnforceTechRoutePermission`, while the Client show route requires `client.view`.
- `TicketIndexQuery` can filter by `client_id`, but its default ownership and lifecycle filters are
  designed for the global Ticket queue and are not suitable as the Client's complete related-record
  list.

## Proposed Change

### Ticket-Owned Client List Query

Add a focused query under `app/Modules/Ticket/Queries` that receives the selected Client and current
technician. It will return an empty collection when the technician lacks `ticket.view`; otherwise it
will return all non-deleted tickets whose `client_id` exactly matches the selected Client.

The query will eager-load only the relations needed for the compact list, including status,
priority, queue, and owner, and order tickets by most recently updated first. It will not reuse the
global Ticket index defaults because the Client tab must not silently hide closed, assigned, or
unassigned Client tickets.

### Client Workspace Integration

Inject the Ticket-owned query into `ClientController::show`. The controller will provide:

- whether the technician has `ticket.view`;
- the accessible Ticket collection for the selected Client.

The Client show view will add `tickets` to its allowed tab keys only when Ticket access is present.
It will render the tab immediately after Contacts and reorder the following existing tabs to Tasks,
Time, Contracts, Signals, and Custom Fields.

The Tickets pane will use a compact Bootstrap table consistent with the existing workspace lists.
Rows will show the Ticket key, subject, status, priority, owner, and last update. The Ticket key and
clickable row will point to `tech.tickets.show`. An empty state will explain when the selected Client
has no tickets.

The badge count and rows will use the same collection. A user without `ticket.view` will not see the
Tickets tab and the controller will not load Ticket rows for that user. This preserves the existing
Client permission while preventing Client access from becoming an indirect Ticket-read permission.

### Domain Ownership

- Clients owns placement and presentation inside the Client workspace.
- Ticket owns the query that selects Ticket records and applies Ticket-read eligibility.
- The existing Ticket detail route remains the only detail destination.
- No new shared component is needed unless implementation finds an existing reusable Ticket list
  component that already matches the required compact table.

## Impact Analysis

### Affected Modules

- Clients: show controller, workspace Blade view, feature tests, and Client Knowledge documentation.
- Ticket: focused related-list query, query/feature coverage, and Ticket Knowledge documentation.

### Permissions And Scoping

- `client.view` remains required to open the Client profile.
- `ticket.view` is additionally required to receive or render Ticket rows.
- Superuser and the existing privileged Admin fallback continue to work through the established
  permission behavior.
- Every returned row must satisfy `tickets.client_id = selected client id`.
- No customer portal route or membership scope is affected.

### Routes And UI

- No route is added or renamed.
- The existing `tech.clients.show` and `tech.tickets.show` routes remain stable.
- Existing tab panes are reordered without changing their IDs or request tab keys.
- The implementation uses Bootstrap and existing clickable-row conventions.

### Data, Integrations, Queues, And Build

- No database migration, backfill, API change, external integration, queue, scheduler, or frontend
  build is required.
- Normal Laravel view/config cache clearing is sufficient after deployment.

### Risks And Side Effects

- Loading all tickets for a very large Client may increase Client profile query time. The query must
  eager-load its displayed relations and tests should guard against accidental cross-client rows.
  Pagination or a capped preview may be proposed later only through a separate approved change,
  because either would make the issue's list/count contract ambiguous.
- Reordering tabs can break request-selected tab activation if existing IDs or tab keys change. The
  implementation must preserve those values and test the required order.
- The Client controller and show view are shared surfaces with other completed Client features.
  Implementation must preserve Contracts, Time, Signals, Tasks, and Custom Fields behavior.
- A user may have `client.view` without `ticket.view`; hiding the tab and avoiding the query is
  required to prevent permission leakage.

## Data And Migration Plan

No data migration or backfill is required. Rollback consists of removing the Ticket-owned query and
the Client workspace tab/controller wiring. No persisted records change.

## Testing Plan

- Client feature test: an authorized technician sees the Tickets tab in the required order.
- Client feature test: the badge and rows include the selected Client's open and closed tickets and
  exclude another Client's ticket.
- Client feature test: Ticket rows link to the existing Ticket detail route.
- Client feature test: a technician with `client.view` but without `ticket.view` does not see Ticket
  data or the tab.
- Ticket query/feature test: the focused query returns only matching, non-deleted Client tickets in
  newest-updated order and enforces Ticket view eligibility.
- Regression test the existing Client and Ticket module feature suites.
- Render the Blade views and perform a Dev HTTP smoke check for the Client profile.

## Documentation Plan

- Update `app/Modules/Clients/Docs/knowledge/client-domain-overview.md` with the Tickets tab,
  permission behavior, fields, and tab order.
- Update `app/Modules/Ticket/Docs/knowledge/ticket-overview.md` with the Client workspace entry
  point and its complete related-record behavior.
- Add a pending human-review entry for the Client/Ticket workflow before completion handoff.
- Sync the updated repository Knowledge documents through the existing Knowledge sync command when
  implementation is complete.

## Open Questions

None. Issue #188 defines the required placement and behavior; this RFC selects the narrowest
permission-safe implementation.

## Approval

Approved by Svein Tore in the Codex task on 2026-07-28.

## Implementation

Implemented on Dev on 2026-07-28. Ticket owns `ClientTicketListQuery`, Clients owns the tab and
table, and the existing `ticket.view` permission controls whether the tab and rows are available.
The complete Client feature file and Ticket feature suites pass with 182 tests and 1424 assertions.
Knowledge documentation is synchronized for Clients and Ticket, and manual verification remains
open under `HR-2026-07-28-003`.
