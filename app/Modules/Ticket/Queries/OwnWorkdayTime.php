<?php

namespace App\Modules\Ticket\Queries;

use App\Models\Core\User;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Database\Eloquent\Builder;

/** A Task billing projection can never become a second employee actual-time source. */
class OwnWorkdayTime
{
    public function query(User $worker): Builder
    {
        abort_unless($worker->can('ticket.view'), 403);

        return TicketTimeEntry::query()->where('user_id', $worker->id)->whereNull('task_id')
            ->where('type', '!=', 'task_billing')->whereHas('ticket')->with('ticket');
    }

    /** A specific confirmed reference may be inspected with the viewer's domain access. Workday gates oversight. */
    public function reference(User $viewer, int $workerId, int $entryId): ?TicketTimeEntry
    {
        abort_unless($viewer->can('ticket.view'), 403);

        return TicketTimeEntry::query()->where('user_id', $workerId)->whereKey($entryId)
            ->whereNull('task_id')->where('type', '!=', 'task_billing')
            ->whereHas('ticket')->with('ticket')->first();
    }
}
