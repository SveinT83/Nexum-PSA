<?php

namespace App\Modules\Task\Queries;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Task\Services\TaskApiScope;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/** Task visibility and owner/context integrity apply before lookup, totals and exports. */
class ReportedTime
{
    public function records(User $actor): Builder
    {
        $tickets = app(\App\Modules\Ticket\Queries\ReportedTime::class)->records()
            ->where(fn ($q) => $q->whereNull('tasks.client_id')->orWhereColumn('tickets.client_id', 'tasks.client_id'))
            ->where(fn ($q) => $q->whereNull('tasks.work_context_id')->orWhereNull('tickets.work_context_id')
                ->orWhereColumn('tickets.work_context_id', 'tasks.work_context_id'));

        return app(TaskApiScope::class)->visible(Task::withTrashed(), $actor)
            ->where(function ($q) {
                $q->whereNull('tasks.work_context_id')->orWhereHas('workContext', function ($c) {
                    $c->where(fn ($c) => $c->where('type', 'client')->whereColumn('work_contexts.client_id', 'tasks.client_id'))
                        ->orWhere(fn ($c) => $c->where('type', 'internal')->whereNull('work_contexts.client_id')->whereNull('tasks.client_id'));
                });
            })->where(function ($q) use ($tickets) {
                $q->where(fn ($q) => $q->where('owner_type', (new Ticket)->getMorphClass())->whereIn('owner_id', $tickets->select('tickets.id')))
                    ->orWhere(fn ($q) => $q->where('owner_type', (new Client)->getMorphClass())
                        ->whereIn('owner_id', Client::query()->select('id')->where(fn ($c) => $c->whereNull('tasks.client_id')->orWhereColumn('clients.id', 'tasks.client_id')))
                        ->where(fn ($c) => $c->whereNotNull('tasks.client_id')->orWhereNull('tasks.work_context_id')))
                    ->orWhereNotIn('owner_type', [(new Ticket)->getMorphClass(), (new Client)->getMorphClass()])
                    ->orWhereNull('owner_type');
            });
    }

    public function entries(User $actor, array $filters): Builder
    {
        $records = $this->records($actor)
            ->when($filters['task_id'], fn ($q, $id) => $q->whereKey($id))
            ->when($filters['ticket_id'], fn ($q, $id) => $q->where('owner_type', (new Ticket)->getMorphClass())->where('owner_id', $id));
        if ($filters['client_id'] || $filters['context'] !== 'all') {
            $this->clientScope($records, $filters['client_id'], $filters['context']);
        }
        if ($filters['status']) {
            [$source, $id] = explode(':', $filters['status']);
            $records->where('tasks.status_id', $id)->when($source !== 'task', fn ($q) => $q->whereRaw('1=0'));
        }

        return TaskTimeEntry::query()->whereIn('task_id', $records->select('tasks.id'))
            ->with(['user:id,name', 'task' => fn ($q) => $q->withTrashed()->with('status')]);
    }

    public function clientScope(Builder $records, ?int $clientId, string $context): void
    {
        $parentClients = Ticket::withTrashed()->select('id')
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId), fn ($q) => $q->whereNotNull('client_id'));
        $clientWork = function ($q) use ($clientId, $parentClients) {
            $q->when($clientId, fn ($q) => $q->where('tasks.client_id', $clientId), fn ($q) => $q->whereNotNull('tasks.client_id'))
                ->orWhere(fn ($q) => $q->whereNull('tasks.client_id')->where('owner_type', (new Ticket)->getMorphClass())->whereIn('owner_id', $parentClients))
                ->orWhere(fn ($q) => $q->whereNull('tasks.client_id')->where('owner_type', (new Client)->getMorphClass())
                    ->when($clientId, fn ($q) => $q->where('owner_id', $clientId)));
        };
        if ($context === 'internal') {
            $records->whereNull('tasks.client_id')
                ->where(fn ($q) => $q->whereNull('owner_type')->orWhere('owner_type', '!=', (new Client)->getMorphClass()))
                ->where(fn ($q) => $q->whereNull('owner_type')->orWhere('owner_type', '!=', (new Ticket)->getMorphClass())
                    ->orWhereNotIn('owner_id', $parentClients));
            if ($clientId) {
                $records->whereRaw('1=0');
            }
        } else {
            $records->where($clientWork);
        }
    }
}
