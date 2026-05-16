# Nexum-PSA — Ticket Reopen Feature

**Created:** 2026-05-16  
**Branch:** `feature/ticket-reopen` (from `feature/user-invite-mfa`)  
**Status:** Proposal — pending review

---

## Problem

When a ticket is closed/resolved, there is no way for a customer (or technician) to reopen it. If a customer discovers the issue isn't fully resolved after we mark it solved, they must create a brand-new ticket — losing all context, history, and the connection to the original problem.

This is semantically different from a new ticket. A reopened ticket says "your fix didn't work" or "there's more to this issue." Tracking reopen rates is also a key MSP quality metric.

---

## Requirements

### Customer-Facing
1. **Reopen window**: Customers can reopen a closed ticket within a configurable time period (default: 7 days after close)
2. **Reopen reason required**: Customer must provide a reason ("I'm still seeing the issue", "New symptoms appeared", etc.)
3. **Reopen from portal or email reply**: Customer clicks "Reopen" on the ticket in the portal, or replies to the closed-ticket notification email
4. **After window expires**: Ticket is truly closed — customer must create a new ticket (with a link to the original for reference)

### Technician-Facing
5. **Technicians can always reopen**: No time limit for internal staff
6. **Reopen transition in workflow**: Must be a valid workflow transition (Closed/Resolved → Reopened/In Progress)
7. **Clear visual indicator**: Reopened tickets show a "Reopened" badge and the reopen count
8. **Audit trail**: Each reopen creates a `TicketEvent` with type `status_changed` and the reason

### Dashboard/Reporting
9. **Reopen count on ticket model**: `reopened_count` integer field, incremented on each reopen
10. **Reopen rate metric**: Dashboard shows % of closed tickets that were reopened (quality signal)
11. **Reopened tickets in status breakdown**: Separate from "New" and "Open" in charts and filters

---

## Implementation Plan

### 1. Migration: Add `reopened_count` to tickets table
```php
Schema::table('tickets', function (Blueprint $table) {
    $table->unsignedInteger('reopened_count')->default(0)->after('closed_at');
});
```

### 2. TicketStatus: Ensure "Reopened" status exists
- Either a dedicated `Reopened` status, or reuse `Open`/`In Progress` with a flag
- Recommend: Dedicated status with `state = 'reopened'`, `is_closed = false`, `is_default = false`
- Seeded as part of the default workflow

### 3. Workflow Transition: Add "Reopen" transitions
- `Closed → Reopened` (customer and technician)
- `Resolved → Reopened` (customer and technician)
- Transition requirements: `requires_note = true` (reopen reason)

### 4. ReopenController: Public endpoint for customer reopen
```php
// routes/tech.php or routes/client.php
Route::post('/tickets/{ticket}/reopen', [TicketController::class, 'reopen'])
    ->name('tech.tickets.reopen');
```

### 5. ReopenTicket Action
```php
class ReopenTicket
{
    public function handle(Ticket $ticket, string $reason, ?User $actor = null, bool $isCustomer = false): Ticket
    {
        // 1. Validate reopen window (customers only)
        if ($isCustomer) {
            $reopenDeadline = $ticket->closed_at->addDays(config('ticket.reopen_window_days', 7));
            if (now()->isAfter($reopenDeadline)) {
                throw ValidationException::withMessages([
                    'ticket' => 'This ticket can no longer be reopened. Please create a new ticket.'
                ]);
            }
        }

        // 2. Find "Reopened" status (or configured reopen target status)
        $reopenedStatus = TicketStatus::where('state', 'reopened')->first()
            ?? TicketStatus::where('slug', 'open')->first();

        // 3. Use workflow runtime to validate transition
        // Falls back to direct status change if no workflow configured

        // 4. Increment reopened_count
        $ticket->increment('reopened_count');

        // 5. Clear resolved_at/closed_at, set status
        // (ChangeTicketStatus handles this via workflow)

        // 6. Create reopen event with reason
        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'actor_id' => $actor?->id,
            'type' => 'ticket_reopened',
            'message' => $reason,
            'before' => ['status_id' => $ticket->status_id, 'closed_at' => $ticket->closed_at?->toISOString()],
            'after' => ['status_id' => $reopenedStatus->id],
        ]);

        // 7. Notify assignee
        if ($ticket->owner_id) {
            $ticket->owner->notify(new TicketReopened($ticket, $reason, $actor?->name));
        }
    }
}
```

### 6. Config Setting
```php
// config/ticket.php
'reopen_window_days' => env('TICKET_REOPEN_WINDOW_DAYS', 7),
```

### 7. Customer Portal UI
- Show "Reopen Ticket" button on closed tickets (within reopen window)
- Button opens a form with a required reason textarea
- After window expires, show "This ticket is closed. Create a new ticket?" with link

### 8. Technician UI
- "Reopen" button always available on closed/resolved tickets (no time limit)
- Dropdown option in status change workflow

### 9. Email Reopen
- Inbound email on closed ticket (within reopen window) → auto-reopen with email body as reason
- After window → auto-create new ticket with reference to original

### 10. Notification
- `TicketReopened` notification class (extends notification framework)
- Channels: email, in-app, Nextcloud Talk (respects user preferences)

---

## Dashboard Impact

The `reopened_count` field and `ticket_reopened` event type enable:

| Metric | Query |
|--------|-------|
| Reopened tickets (total) | `Ticket::where('reopened_count', '>', 0)->count()` |
| Reopened this week | `TicketEvent::where('type', 'ticket_reopened')->where('created_at', '>=', now()->subWeek())->count()` |
| Reopen rate | `reopened_count / closed_count * 100` |
| Tickets reopened > 1 time | `Ticket::where('reopened_count', '>', 1)->count()` |

---

## Open Questions for Discussion

1. **Reopen status**: Dedicated "Reopened" status vs. reusing "Open"? (Recommend: dedicated — clearer semantics, easier to filter and report on)
2. **Reopen window**: 7 days default? Configurable per-queue or per-client?
3. **Auto-close after reopen window**: Should tickets auto-close after the reopen window, or stay "Closed" from the start?
4. **Reopen limit**: Should there be a max reopen count? (e.g., after 3 reopens, require manager approval?)
5. **Email reply behavior**: Should any reply to a closed ticket reopen it (within window), or only explicit "Reopen" action?

---

*Drafted by Commander Cobra 🐍 — awaiting review before implementation.*