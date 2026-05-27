<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Economy\Jobs\GenerateEconomyOrdersJob;
use App\Modules\Notification\Notifications\TicketStatusChanged;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeTicketStatus
{
    public function __construct(private readonly TicketWorkflowRuntime $workflowRuntime)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Status lifecycle foundation
    |--------------------------------------------------------------------------
    |
    | Status changes are the stable low-level operation that future workflows
    | will validate. Keep timestamp side effects here so controllers, rules, and
    | inbound email handlers all get the same lifecycle behavior.
    |
    */
    public function handle(Ticket $ticket, TicketStatus $status, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $status, $actor) {
            if ($reason = $this->workflowRuntime->blockedReason($ticket, $status)) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => $actor?->id,
                    'type' => 'workflow_transition_blocked',
                    'message' => $reason,
                    'before' => ['status_id' => $ticket->status_id],
                    'after' => ['status_id' => $status->id],
                ]);

                throw ValidationException::withMessages(['status_id' => $reason]);
            }

            $before = [
                'status_id' => $ticket->status_id,
                'resolved_at' => $ticket->resolved_at?->toISOString(),
                'closed_at' => $ticket->closed_at?->toISOString(),
            ];

            $updates = [
                'status_id' => $status->id,
                'updated_by' => $actor?->id,
            ];

            if ($status->is_closed) {
                $updates['resolved_at'] = $ticket->resolved_at ?? now();
                $updates['closed_at'] = $ticket->closed_at ?? now();
            } elseif ($status->state === 'resolved' && ! $ticket->resolved_at) {
                $updates['resolved_at'] = now();
                $updates['closed_at'] = null;
            } else {
                $updates['closed_at'] = null;
            }

            $ticket->forceFill($updates)->save();
            $ticket->refresh();

            $after = [
                'status_id' => $ticket->status_id,
                'resolved_at' => $ticket->resolved_at?->toISOString(),
                'closed_at' => $ticket->closed_at?->toISOString(),
            ];

            if ($before !== $after) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => $actor?->id,
                    'type' => 'status_changed',
                    'before' => $before,
                    'after' => $after,
                    'message' => 'Ticket status changed to ' . $status->name . '.',
                ]);

                // Notify the ticket owner if they didn't change the status themselves
                if ($ticket->owner_id && $ticket->owner_id !== $actor?->id) {
                    $owner = User::find($ticket->owner_id);
                    if ($owner) {
                        $oldStatusName = TicketStatus::find($before['status_id'])?->name ?? 'Unknown';
                        $owner->notify(new TicketStatusChanged(
                            ticket: $ticket,
                            oldStatus: $oldStatusName,
                            newStatus: $status->name,
                            changedBy: $actor?->name,
                        ));
                    }
                }

                if ($status->is_closed) {
                    GenerateEconomyOrdersJob::dispatch(
                        $ticket->closed_at?->copy()->startOfMonth()->toDateString(),
                        $ticket->closed_at?->copy()->endOfMonth()->toDateString(),
                        $actor?->id,
                    )->onQueue('economy')->afterCommit();
                }
            }

            return $ticket;
        });
    }
}
