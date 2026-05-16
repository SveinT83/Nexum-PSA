<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Notifications\TicketReopened;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReopenTicket
{

    /*
    |--------------------------------------------------------------------------
    | Reopen a closed ticket
    |--------------------------------------------------------------------------
    |
    | Reopening moves a closed/resolved ticket back to an open state.
    | It clears resolved_at/closed_at, increments reopen_count, and sets
    | reopened_at. This is designed so that future customer-facing portals
    | can also trigger reopen (e.g. customer replies on a closed ticket).
    |
    | The status is set to the configured "reopen" status (default: "open")
    | or the first active non-closed status if no reopen-target is configured.
    |
    */
    public function handle(Ticket $ticket, ?User $actor = null, ?string $reason = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor, $reason) {
            $reopenStatus = $this->resolveReopenStatus();

            if (! $reopenStatus) {
                throw ValidationException::withMessages([
                    'status_id' => 'No active open status exists to reopen into. Create an open status first.',
                ]);
            }

            $before = [
                'status_id' => $ticket->status_id,
                'resolved_at' => $ticket->resolved_at?->toISOString(),
                'closed_at' => $ticket->closed_at?->toISOString(),
                'reopen_count' => $ticket->reopen_count ?? 0,
            ];

            // Update ticket: change status, clear closed/resolved timestamps, track reopen
            $ticket->forceFill([
                'status_id' => $reopenStatus->id,
                'resolved_at' => null,
                'closed_at' => null,
                'reopened_at' => now(),
                'reopen_count' => ($ticket->reopen_count ?? 0) + 1,
                'updated_by' => $actor?->id,
            ])->save();

            $ticket->refresh();

            $after = [
                'status_id' => $ticket->status_id,
                'resolved_at' => null,
                'closed_at' => null,
                'reopen_count' => $ticket->reopen_count,
                'reopened_at' => $ticket->reopened_at?->toISOString(),
            ];

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'reopened',
                'before' => $before,
                'after' => $after,
                'message' => $reason
                    ? 'Ticket reopened: ' . $reason
                    : 'Ticket reopened.',
            ]);

            // Notify the ticket owner if they didn't reopen it themselves
            if ($ticket->owner_id && $ticket->owner_id !== $actor?->id) {
                $owner = User::find($ticket->owner_id);
                if ($owner) {
                    $owner->notify(new TicketReopened(
                        ticket: $ticket,
                        reopenedBy: $actor?->name ?? 'System',
                        reason: $reason,
                    ));
                }
            }

            return $ticket;
        });
    }

    /**
     * Find the best status to reopen into.
     *
     * Priority:
     *  1. A status with state = 'reopen' (future: admin can configure a dedicated reopen target)
     *  2. A status with state = 'open' (default open status)
     *  3. The default status (is_default = true)
     *  4. First active non-closed status
     */
    private function resolveReopenStatus(): ?TicketStatus
    {
        // 1. Dedicated reopen target status
        $reopenStatus = TicketStatus::query()
            ->where('is_active', true)
            ->where('state', 'reopen')
            ->orderBy('sort_order')
            ->first();

        if ($reopenStatus) {
            return $reopenStatus;
        }

        // 2. Open state status
        $openStatus = TicketStatus::query()
            ->where('is_active', true)
            ->where('state', 'open')
            ->orderBy('sort_order')
            ->first();

        if ($openStatus) {
            return $openStatus;
        }

        // 3. Default status
        $defaultStatus = TicketStatus::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        if ($defaultStatus && ! $defaultStatus->is_closed) {
            return $defaultStatus;
        }

        // 4. First active non-closed status
        return TicketStatus::query()
            ->where('is_active', true)
            ->where('is_closed', false)
            ->orderBy('sort_order')
            ->first();
    }
}