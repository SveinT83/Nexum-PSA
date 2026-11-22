<?php

namespace App\Modules\Ticket\Queries;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Database\Eloquent\Builder;

/** Historical registered effort excludes Task-owned billing projections at the source. */
class ReportedTime
{
    public function records(): Builder
    {
        return Ticket::withTrashed()->where(function (Builder $q) {
            $q->whereNull('tickets.work_context_id')->orWhereHas('workContext', function (Builder $context) {
                $context->where(fn ($c) => $c->where('type', 'client')->whereColumn('work_contexts.client_id', 'tickets.client_id'))
                    ->orWhere(fn ($c) => $c->where('type', 'internal')->whereNull('work_contexts.client_id')->whereNull('tickets.client_id'));
            });
        });
    }

    public function entries(array $filters): Builder
    {
        $records = $this->records()
            ->when($filters['client_id'], fn ($q, $id) => $q->where('tickets.client_id', $id))
            ->when($filters['context'] === 'internal', fn ($q) => $q->whereNull('tickets.client_id'))
            ->when($filters['context'] === 'client', fn ($q) => $q->whereNotNull('tickets.client_id'))
            ->when($filters['ticket_id'], fn ($q, $id) => $q->whereKey($id));
        if ($filters['status']) {
            [$source, $id] = explode(':', $filters['status']);
            $records->where('tickets.status_id', $id)->when($source !== 'ticket', fn ($q) => $q->whereRaw('1=0'));
        }
        if ($filters['task_id']) {
            $records->whereRaw('1=0');
        }

        return TicketTimeEntry::query()->whereNull('task_id')
            ->whereIn('ticket_id', $records->select('tickets.id'))
            ->with(['user:id,name', 'ticket' => fn ($q) => $q->withTrashed()->with(['client', 'status'])]);
    }
}
