<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Validation\ValidationException;

class ReopenTicket
{
    public function __construct(
        private readonly TicketActionGuard $guard,
        private readonly TransitionTicketWorkflow $transitions,
    ) {}

    public function handle(
        Ticket $ticket,
        TicketStatus $targetStatus,
        User $actor,
        string $idempotencyKey,
        bool $notificationsEnabled = true,
    ): Ticket {
        $ticket->loadMissing('status');
        if (! $ticket->status?->is_closed && ! $ticket->closed_at) {
            return $ticket;
        }
        if (! $targetStatus->is_active || $targetStatus->is_closed) {
            throw ValidationException::withMessages([
                'reopen_status_id' => 'The configured reopen target must be an active non-closed status.',
            ]);
        }
        if ($reason = $this->guard->reason($ticket, TicketAction::REOPEN, $actor)) {
            throw ValidationException::withMessages(['reopen_status_id' => $reason]);
        }

        $reopened = $this->transitions->handleToStatus(
            $ticket,
            $targetStatus,
            $actor,
            $idempotencyKey,
            enforceActionGuard: false,
            allowTerminal: false,
            notificationsEnabled: $notificationsEnabled,
        );
        $reopened->refresh()->loadMissing('status');

        if ($reopened->status?->is_closed || $reopened->closed_at || $reopened->resolved_at) {
            throw ValidationException::withMessages([
                'reopen_status_id' => 'The Ticket Workflow did not produce a fully reopened Ticket lifecycle.',
            ]);
        }

        return $reopened;
    }
}
