@extends('layouts.default_tech')

@section('title', 'Tickets')

@section('pageName')
    <h3>Tickets</h3>
@endsection

@section('pageHeader')
    <h1>Tickets list</h1>
@endsection

@section('content')
<div class="container-fluid">
  <div class="row">
    <div class="col-12">
      <div class="card">
        <div class="card-header">
          <h2>tech.ticket.index — Functional Specification</h2>
          <p class="text-muted mb-0">
            <strong>Date:</strong> 2025-10-20<br>
            <strong>URL / View Key:</strong> tech.ticket.index<br>
            <strong>Route Path:</strong> /tech/tickets<br>
            <strong>Permission Required:</strong> ticket.view<br>
            <strong>Access Levels:</strong> Technician, Ticket Admin, SuperAdmin<br>
            <strong>Controller:</strong> App\Http\Controllers\Tech\TicketsController@index<br>
            <strong>Controller (folder map):</strong> app/Http/Controllers/Tech/TicketsController.php<br>
            <strong>Status:</strong> <span class="badge badge-warning">Not completed</span><br>
            <strong>Difficulty:</strong> <span class="badge badge-info">Medium</span><br>
            <strong>Estimated Time:</strong> 5.0 hours
          </p>
        </div>
        <div class="card-body">
          <h3>Purpose</h3>
          <p>A technician-facing ticket list that prioritizes speed, clarity, and focus. The page offers sidebar filtering with persistent (browser-local) memory, two-line list rows with unread indicators, and reliable refresh mechanics. No bulk operations: work one ticket at a time.</p>

          <h3>Design & Layout (Bootstrap frame)</h3>
          <ul>
            <li><strong>Top header (page toolbar):</strong> Search, sort, manual refresh, item count, and "open in new tab" helper text.</li>
            <li><strong>Main position:</strong> Virtualized/lazy-loaded ticket list with internal scrolling.</li>
            <li><strong>Right slim rail:</strong> Live read-only widgets (SLA Monitor, Personal Workload, Refresh Status).</li>
          </ul>
          <blockquote class="blockquote">
            <p>The dashboard shell is static; content within the list and widgets updates live.</p>
          </blockquote>

          <h3>Left Sidebar (Filters & Persistence)</h3>
          <h4>Elements (checkboxes and inputs):</h4>
          <ul>
            <li><strong>Ownership</strong>
              <ul>
                <li>My tickets (default: ON)</li>
                <li>All tickets</li>
              </ul>
            </li>
            <li><strong>Status</strong> (multi-select checkboxes)
              <ul>
                <li>New, In Progress, Waiting on Customer, On Hold, Resolved, Closed (default: Closed OFF)</li>
              </ul>
            </li>
            <li><strong>Priority</strong>
              <ul>
                <li>P1, P2, P3, P4</li>
              </ul>
            </li>
            <li><strong>Customer</strong>
              <ul>
                <li>Searchable select (customer; optional customer number field shown in results)</li>
              </ul>
            </li>
          </ul>

          <h4>Behavior</h4>
          <ul>
            <li>Any change triggers immediate requery and refresh of the list.</li>
            <li>Filters are <strong>remembered per user in the browser</strong> (e.g., <code>localStorage</code>), restored on revisit and page refresh. Not persisted across login/logout.</li>
            <li>Manual <strong>Refresh</strong> button in the header.</li>
            <li><strong>Auto-refresh</strong> full list reload on interval (default 5 minutes; configurable later in Tickets Settings).</li>
          </ul>

          <h3>Header Controls</h3>
          <ul>
            <li><strong>Search</strong>: Full‑text across <em>Title</em> and <em>Description</em>.</li>
            <li><strong>Sort</strong>: Clickable column headers (primary sort only; toggle asc/desc). Default sort is <em>Unread first</em> then <em>Newest updated</em>.</li>
            <li><strong>Refresh</strong>: Manual trigger; shows spinner during reload and updates the timestamp indicator.</li>
            <li><strong>Updated indicator</strong>: "Updated X minutes ago" (auto-updated after each refresh).</li>
            <li><strong>Open-in-new-tab tip</strong>: Small hint text and an icon on each row (see Open Behavior).</li>
            <li><strong>Total counter</strong>: Displays total items for current filter ("Showing N tickets").</li>
          </ul>

          <h3>Ticket List (Main)</h3>
          <p><strong>Row Density:</strong> Two lines per ticket (title + meta line).<br>
          <strong>Scroll Model:</strong> Internal scroll container; <strong>lazy load</strong> more as user scrolls.</p>

          <h4>Columns (configurable later in Tickets Settings)</h4>
          <ol>
            <li><strong>Ticket ID</strong></li>
            <li><strong>Title</strong> (single-line with truncation)</li>
            <li><strong>Customer No.</strong></li>
            <li><strong>Customer</strong></li>
            <li><strong>Contact</strong> (requester)</li>
            <li><strong>Queue</strong></li>
            <li><strong>Category</strong></li>
            <li><strong>Priority</strong> (P1–P4) – small colored badge only for the badge (no row coloring)</li>
            <li><strong>Status</strong> – small colored tag + text</li>
            <li><strong>Last Updated</strong> – relative time (e.g., "12m ago")</li>
            <li><strong>Unread</strong> – blue dot badge (customer reply not yet marked as read)</li>
          </ol>

          <p><strong>Per-row actions:</strong> None. (Open the ticket to act.)</p>

          <h4>Open Behavior</h4>
          <ul>
            <li>Default click: open in same view (<code>tech.ticket.show</code>).</li>
            <li>Dedicated <strong>new-tab icon button</strong> on the row: opens in a new tab.</li>
            <li>Ctrl/Cmd + click: opens in a new tab.</li>
            <li>Returning from a ticket preserves filters, sort, scroll position, and search.</li>
          </ul>

          <h3>Unread Indicator Policy</h3>
          <ul>
            <li>Visual: <strong>Blue dot</strong> when a customer reply exists and message is not marked as read.</li>
            <li>Behavior: Controlled by Tickets Settings later
              <ul>
                <li><strong>Automatic</strong>: mark as read on ticket open</li>
                <li><strong>Manual</strong>: requires explicit "Mark as read" action inside the ticket view</li>
              </ul>
            </li>
          </ul>

          <h3>Widgets (Right Slim Rail)</h3>
          <ul>
            <li><strong>SLA Monitor</strong>: Counts and small list of tickets at risk/breached within current filters.</li>
            <li><strong>Personal Workload</strong>: Mine vs team counts by status (read-only snapshot).</li>
            <li><strong>Refresh Status</strong>: Last updated timestamp + next refresh countdown.</li>
          </ul>
          <blockquote class="blockquote">
            <p>Widgets are read-only here; interaction happens inside ticket details or settings.</p>
          </blockquote>

          <h3>Live Update & Refresh</h3>
          <ul>
            <li><strong>Auto-refresh</strong>: Whole list reload on interval (default 5 minutes). Interval configurable in Tickets Settings.</li>
            <li><strong>Manual refresh</strong>: Button in header; shows spinner during load.</li>
            <li><strong>Indicator</strong>: "Updated X minutes ago." No partial (diff) updates; full reload only.</li>
          </ul>

          <h3>Settings Hooks (to document under Tickets Settings)</h3>
          <ul>
            <li><strong>Columns</strong>: Enable/disable per column for <code>tech.ticket.index</code>.</li>
            <li><strong>Auto-refresh interval</strong>: Allowed range and default (e.g., 1–15 minutes; default 5).</li>
            <li><strong>Unread policy</strong>: Automatic vs manual mark-as-read on open.</li>
            <li><strong>Default sidebar state</strong>: e.g., My tickets ON, Closed OFF.</li>
          </ul>

          <h3>Suggested Components (Livewire-friendly)</h3>
          <ul>
            <li><strong>ticketsList</strong> (reusable): data table with lazy load + sortable headers + unread/SLA flags.</li>
            <li><strong>personalWorkload</strong> (existing livewire in tickets area): right-rail snapshot.</li>
            <li><strong>slaMonitor</strong> (existing livewire in tickets area): right-rail snapshot.</li>
            <li><strong>refreshStatus</strong>: lightweight widget for last/next refresh.</li>
          </ul>
          <blockquote class="blockquote">
            <p>Prefer Bootstrap components for layout. Keep modals/popovers minimal; no row-level quick actions.</p>
          </blockquote>

          <h3>Icons (no colors specified)</h3>
          <ul>
            <li><strong>Priority badge</strong>: small level/flag icon with P1–P4 text.</li>
            <li><strong>Status tag</strong>: small tag icon with status text.</li>
            <li><strong>Unread</strong>: solid dot icon.</li>
            <li><strong>Open in new tab</strong>: external-link / new-tab icon.</li>
            <li><strong>Refresh</strong>: rotate/refresh icon with spinner state.</li>
          </ul>

          <h3>Non-functional</h3>
          <ul>
            <li><strong>Performance</strong>: Virtualized list or efficient pagination to keep row rendering smooth.</li>
            <li><strong>Accessibility</strong>: Keyboard navigation for list rows; readable status labels.</li>
            <li><strong>Audit</strong>: None on this view; actions are read-only here. Audit is on ticket detail/actions.</li>
          </ul>

          <h3>Notes & Exclusions</h3>
          <ul>
            <li>No bulk selection or bulk actions by design.</li>
            <li>"Closed" tickets are hidden by default; opt-in via sidebar checkbox.</li>
            <li>Customer filters should debounce input to avoid chatty queries.</li>
            <li>This spec avoids HTML; it enumerates components, behaviors, and interactions only.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@section('sidebar')
    <h3>Left Sidebar</h3>
    <ul>
        <li><a href="#">System Status</a></li>
        <li><a href="#">Task Management</a></li>
        <li><a href="#">Reports</a></li>
    </ul>
@endsection

@section('rightbar')
    <h3>Right Sidebar</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection