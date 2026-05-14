<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;

class CloseTicket
{
    public function __construct(private readonly ChangeTicketStatus $changeTicketStatus)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Close shortcut
    |--------------------------------------------------------------------------
    |
    | Closing is a convenience action over the same status transition used by
    | manual status edits. Future workflow guards should hook into status change
    | validation before this action commits the closed status.
    |
    */
    public function handle(Ticket $ticket, ?User $actor = null): Ticket
    {
        $closedStatus = TicketStatus::query()
            ->where('is_active', true)
            ->where('is_closed', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first()
            ?? TicketStatus::create([
                'name' => 'Closed',
                'slug' => 'closed',
                'state' => 'closed',
                'is_default' => false,
                'is_closed' => true,
                'is_active' => true,
                'sort_order' => 50,
            ]);

        return $this->changeTicketStatus->handle($ticket, $closedStatus, $actor);
    }
}
