<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Support\TicketAction;

class TicketActionGuard
{
    public function allowed(Ticket $ticket, string $action, ?User $actor = null): bool
    {
        return $this->reason($ticket, $action, $actor) === null;
    }

    public function reason(Ticket $ticket, string $action, ?User $actor = null): ?string
    {
        if (! array_key_exists($action, TicketAction::definitions())) {
            return 'Unknown ticket action.';
        }

        if (! $actor || $actor->status !== User::STATUS_ACTIVE) {
            return 'An active user is required for this ticket action.';
        }

        $ticket->loadMissing('status', 'contact');

        if ($action === TicketAction::CUSTOMER_REPLY && empty($ticket->contact?->email)) {
            return 'A customer reply requires a ticket contact with an email address.';
        }

        if ($this->isClosed($ticket) && in_array($action, [
            TicketAction::UPDATE_FIELDS,
            TicketAction::ASSIGN_OWNER,
            TicketAction::CUSTOMER_REPLY,
            TicketAction::APPLY_SLA,
        ], true)) {
            return 'This action is blocked because the ticket is closed.';
        }

        if ($action === TicketAction::CLOSE && $this->isClosed($ticket)) {
            return 'The ticket is already closed.';
        }

        if ($action === TicketAction::REOPEN && ! $this->isClosed($ticket)) {
            return 'Only closed tickets can be reopened.';
        }

        return null;
    }

    /**
     * @return array<string, bool>
     */
    public function map(Ticket $ticket, ?User $actor = null): array
    {
        return collect(array_keys(TicketAction::definitions()))
            ->mapWithKeys(fn (string $action) => [$action => $this->allowed($ticket, $action, $actor)])
            ->all();
    }

    private function isClosed(Ticket $ticket): bool
    {
        return (bool) ($ticket->status?->is_closed || $ticket->closed_at);
    }
}
