# Nexum-PSA — Status Dashboard Plan

**Created:** 2026-05-16  
**Branch:** To be built on `feature/dashboard-stats` (branched from `feature/user-invite-mfa`)

---

## Goal

Replace the placeholder tech dashboard with a live status dashboard showing ticket statistics, trends, and operational KPIs — starting with ticket stats and expanding to other modules.

---

## Architecture

### Stack
- **Livewire 3** — real-time reactive components, no separate API needed
- **Alpine.js** — already installed, for lightweight UI interactions
- **Bootstrap 5** — already in use, consistent with existing UI
- **Chart.js** — add via npm, lightweight (~70KB gzip), no external deps
- **No new dependencies beyond Chart.js**

### Data Flow
```
Livewire Component → Eloquent Queries → Computed Properties → Blade + Chart.js
```
Livewire handles data fetching and reactivity. Chart.js renders charts. No API endpoints needed — Livewire serves data directly to the frontend.

---

## Dashboard Components

### Phase 1: Ticket Stats (Now)

#### 1. Ticket Status Breakdown
**Livewire:** `TicketStatusChart`  
**Display:** Donut chart + numeric table  
**Data:** Count of tickets per status (New, Open, Pending Customer, In Progress, Resolved, Closed, etc.)  
**Query:**
```php
Ticket::select('status_id', DB::raw('count(*) as count'))
    ->with('status')
    ->groupBy('status_id')
    ->get();
```

#### 2. Ticket Trend (Last 30 Days)
**Livewire:** `TicketTrendChart`  
**Display:** Line chart — tickets created vs resolved per day  
**Data:** Daily counts for created and resolved tickets  
**Query:**
```php
// Created per day
Ticket::select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
    ->where('created_at', '>=', now()->subDays(30))
    ->groupBy('date')
    ->orderBy('date')
    ->get();

// Resolved per day
Ticket::select(DB::raw('DATE(resolved_at) as date'), DB::raw('count(*) as count'))
    ->whereNotNull('resolved_at')
    ->where('resolved_at', '>=', now()->subDays(30))
    ->groupBy('date')
    ->orderBy('date')
    ->get();
```

#### 3. Ticket KPI Cards
**Livewire:** `TicketStatsCards`  
**Display:** Grid of metric cards (like the existing rightbar but richer)

| Card | Metric | Color Logic |
|------|--------|-------------|
| Open Tickets | `status.is_closed = false` | Default |
| Unassigned | `owner_id IS NULL AND status.is_closed = false` | ⚠️ Warning if > 0 |
| Awaiting Customer | `status.slug = 'pending-customer'` OR similar | Info |
| SLA At Risk | `first_response_due < now+2h` OR `resolve_due < now+4h` AND not yet responded/resolved | 🔴 Danger |
| Overdue SLA | `first_response_due < now` OR `resolve_due < now` AND not yet resolved | 🔴 Danger |
| Reopened | Count tickets with `type = 'reopened'` OR events where `type = 'status_changed'` from closed state | Info |
| Closed Today | `closed_at = today()` | ✅ Success |
| Avg Resolution Time | `avg(resolved_at - created_at)` for tickets closed in last 7 days | Context-dependent |

#### 4. Priority Distribution
**Livewire:** `TicketPriorityChart`  
**Display:** Horizontal bar chart — tickets by priority level  
**Data:** Count per priority (P1-Critical through P5-Low)

#### 5. My Tickets Summary (personal)
**Livewire:** `MyTicketsSummary`  
**Display:** Mini-card showing current user's assigned tickets by status  
**Data:** Filtered by `owner_id = auth()->id()`

---

### Phase 2: Operational Dashboard (Later)

| Component | Data |
|-----------|------|
| Client Health | Top clients by open ticket count, SLA compliance |
| Queue Distribution | Tickets per queue, avg time in queue |
| Agent Workload | Tickets per owner, resolution rate |
| Asset Alerts | Active alerts count, unresolved > 24h |
| Recent Activity Feed | Last 10 ticket events (assignments, status changes, comments) |

---

## File Structure

```
app/Modules/Ticket/
├── Livewire/
│   ├── Tech/
│   │   ├── Dashboard/
│   │   │   ├── TicketStatusChart.php
│   │   │   ├── TicketTrendChart.php
│   │   │   ├── TicketStatsCards.php
│   │   │   ├── TicketPriorityChart.php
│   │   │   └── MyTicketsSummary.php
├── Views/
│   ├── Tech/
│   │   ├── Dashboard/
│   │   │   ├── index.blade.php          ← replaces placeholder dashboard
│   │   │   ├── ticket-status-chart.blade.php
│   │   │   ├── ticket-trend-chart.blade.php
│   │   │   ├── ticket-stats-cards.blade.php
│   │   │   ├── ticket-priority-chart.blade.php
│   │   │   └── my-tickets-summary.blade.php

resources/
├── js/
│   └── app.js                           ← add Chart.js import
├── css/
│   └── app.css                          ← dashboard styles
```

---

## Routes

Add to `routes/tech.php`:
```php
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
```

The main dashboard view composes all Livewire components:
```blade
<x-card.default title="Ticket Overview">
    <livewire:ticket-stats-cards />
</x-card.default>

<div class="row">
    <div class="col-md-6">
        <x-card.default title="Status Breakdown">
            <livewire:ticket-status-chart />
        </x-card.default>
    </div>
    <div class="col-md-6">
        <x-card.default title="Priority Distribution">
            <livewire:ticket-priority-chart />
        </x-card.default>
    </div>
</div>

<x-card.default title="30-Day Trend">
    <livewire:ticket-trend-chart />
</x-card.default>
```

---

## Implementation Details

### Chart.js Integration
- Install: `npm install chart.js`
- Import in `resources/js/app.js`: `import Chart from 'chart.js/auto'`
- Charts render inside Livewire components via Alpine + `x-init` or `@chart` directive
- Charts update on Livewire re-renders using `wire:ignore` + Alpine watchers

### Performance
- Dashboard queries are lightweight (indexed columns, date ranges)
- Livewire handles polling if live updates are needed (e.g., `wire:poll.30s`)
- Chart.js canvases use `wire:ignore` to prevent DOM diffing issues

### Reopened Detection
Two approaches (use whichever fits the data model):
1. **Status-based**: Count tickets where `status.state = 'reopened'` (if status slugs include 'reopened')
2. **Event-based**: Count `TicketEvent` records where `type = 'status_changed'` AND previous status was a closed state

### SLA Risk Calculation
```php
// SLA at risk: response or resolution due within 2h/4h but not yet met
Ticket::whereHas('status', fn($q) => $q->where('is_closed', false))
    ->where(function ($q) {
        $q->where(fn($q) => $q->whereNull('first_responded_at')
            ->where('first_response_due_at', '<=', now()->addHours(2)))
        ->orWhere(fn($q) => $q->whereNull('resolved_at')
            ->where('resolve_due_at', '<=', now()->addHours(4)));
    })
    ->count();
```

---

## Implementation Order

1. ✅ Plan approved
2. Create `feature/dashboard-stats` branch from `feature/user-invite-mfa`
3. Install Chart.js (`npm install chart.js`)
4. Build `TicketStatsCards` Livewire component (KPI cards — most visible, simplest)
5. Build `TicketStatusChart` (donut chart — status breakdown)
6. Build `TicketPriorityChart` (horizontal bar — priority distribution)
7. Build `TicketTrendChart` (line chart — 30-day created vs resolved)
8. Build `MyTicketsSummary` (personal ticket count by status)
9. Replace placeholder dashboard with composed view
10. Register route + navigation link
11. Wire up Livewire components in `AppServiceProvider`
12. Test, push, create Gitea issue

---

## Design Reference

Cards should match the existing rightbar stat cards style:
```html
<div class="border rounded bg-light py-2 px-1">
    <div class="small text-muted text-uppercase">Open</div>
    <div class="fw-bold fs-5 lh-1">42</div>
</div>
```

Charts use Bootstrap 5 card wrappers:
```html
<div class="card">
    <div class="card-header">Status Breakdown</div>
    <div class="card-body">
        <canvas id="statusChart" wire:ignore></canvas>
    </div>
</div>
```

Color palette for statuses (matches typical MSP priorities):
- New: `#0d6efd` (Bootstrap primary)
- Open/In Progress: `#0dcaf0` (info)
- Pending Customer: `#ffc107` (warning)
- Resolved: `#198754` (success)
- Closed: `#6c757d` (secondary)
- Overdue SLA: `#dc3545` (danger)

---

*Drafted by Commander Cobra 🐍 — ready for implementation on approval.*