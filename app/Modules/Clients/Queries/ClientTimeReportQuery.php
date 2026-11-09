<?php

namespace App\Modules\Clients\Queries;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Task\Services\TaskApiScope;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A read-only historical projection. It never recalculates or settles customer billing. */
class ClientTimeReportQuery
{
    private const LIMIT = 10000;

    public function build(Client $client, User $actor, ?string $from, ?string $to): array
    {
        return DB::transaction(fn () => $this->materialize($client, $actor, $from, $to));
    }

    private function materialize(Client $client, User $actor, ?string $from, ?string $to): array
    {
        $tickets = $this->bounded($this->context(Ticket::withTrashed()->where('client_id', $client->id), $client)
            ->with('status')->orderBy('id'));
        $ticketIds = $tickets->modelKeys();
        $tasks = app(TaskApiScope::class)->visible(
            $this->context(Task::withTrashed()->where(function (Builder $q) use ($client, $ticketIds): void {
                $q->where('client_id', $client->id)->orWhere(function (Builder $legacy) use ($client, $ticketIds): void {
                    $legacy->whereNull('client_id')->where(function (Builder $owner) use ($client, $ticketIds): void {
                        $owner->where(fn (Builder $q) => $q->where('owner_type', $client->getMorphClass())->where('owner_id', $client->id))
                            ->orWhere(fn (Builder $q) => $q->where('owner_type', (new Ticket)->getMorphClass())->whereIn('owner_id', $ticketIds));
                    });
                });
            }), $client), $actor
        )->where(function (Builder $q) use ($client, $ticketIds): void {
            $q->where(fn (Builder $q) => $q->where('owner_type', $client->getMorphClass())->where('owner_id', $client->id))
                ->orWhere(fn (Builder $q) => $q->where('owner_type', (new Ticket)->getMorphClass())->whereIn('owner_id', $ticketIds))
                ->orWhereNotIn('owner_type', [$client->getMorphClass(), (new Ticket)->getMorphClass()]);
        })->with('status')->orderBy('id');
        $tasks = $this->bounded($tasks);
        $taskIds = $tasks->modelKeys();

        // Task billing projections are visible only when the originating Task is visible.
        $ticketEntries = $this->bounded($this->dates(TicketTimeEntry::query()->whereIn('ticket_id', $ticketIds)
            ->where(fn (Builder $q) => $q->whereNull('task_id')->orWhereIn('task_id', $taskIds))
            ->with(['user', 'allocation']), $from, $to)->orderBy('id'));
        $taskEntries = $this->bounded($this->dates(TaskTimeEntry::query()->whereIn('task_id', $taskIds)->with('user'), $from, $to)->orderBy('id'));
        $quickEntries = $this->bounded($this->dates(ClientContractTimeConsumption::query()
            ->where('client_id', $client->id)->with(['user', 'contractItem']), $from, $to)->orderBy('id'));

        $objects = collect();
        foreach ($tickets as $ticket) {
            $objects->put('ticket:'.$ticket->id, $this->object('Ticket', $ticket->id, $ticket->ticket_key, $ticket->subject,
                $ticket->status?->name, $ticket->trashed(), null));
        }
        foreach ($tasks as $task) {
            $parent = $task->owner_type === (new Ticket)->getMorphClass() ? 'ticket:'.$task->owner_id : null;
            $objects->put('task:'.$task->id, $this->object('Task', $task->id, 'Task #'.$task->id, $task->title,
                $task->status?->name, $task->trashed(), $parent));
        }

        $entries = collect();
        foreach ($ticketEntries as $row) {
            $key = $row->task_id ? 'task:'.$row->task_id : 'ticket:'.$row->ticket_id;
            $allocation = $row->allocation;
            $entries->push($this->entry('ticket', $row, $key, $objects[$key]['reference'],
                $row->task_id ? 0 : (int) $row->minutes, $row->billable ? (int) $row->minutes : 0,
                $allocation ? (int) $allocation->covered_minutes : 0,
                $allocation ? (int) $allocation->billable_minutes : 0,
                $row->billable && ! $allocation ? (int) $row->minutes : 0,
                $row->task_id ? 'Task billing projection' : 'Direct Ticket time',
                $row->billing_status ?? 'Unknown'));
        }
        foreach ($taskEntries as $row) {
            $key = 'task:'.$row->task_id;
            $entries->push($this->entry('task', $row, $key, $objects[$key]['reference'], (int) $row->minutes, 0, 0, 0, 0,
                $row->source_type ?? 'Task time', 'Not a billing source'));
        }
        foreach ($quickEntries as $row) {
            $key = 'quick:'.$row->contract_item_id;
            if (! $objects->has($key)) {
                $objects->put($key, $this->object('Direct timebank', $row->contract_item_id, 'Contract #'.$row->contract_id,
                    $row->contractItem?->name ?? 'Historical contract line', null, false, null));
            }
            $overuse = (int) $row->overused_minutes;
            $entries->push($this->entry('quick', $row, $key, $objects[$key]['reference'], (int) $row->minutes,
                (int) $row->minutes, max(0, (int) $row->minutes - $overuse), $overuse, 0,
                'Direct timebank usage', 'Stored consumption'));
        }

        // Order links are metadata, never an exclusion. Do one query per source instead of N+1.
        foreach (['ticket' => TicketTimeEntry::class, 'quick' => ClientContractTimeConsumption::class] as $source => $class) {
            $ids = $entries->where('source', $source)->pluck('id');
            $orderedIds = EconomyOrderLine::query()->where('source_type', (new $class)->getMorphClass())
                ->whereIn('source_id', $ids)->pluck('source_id')->flip();
            $entries = $entries->map(function (array $entry) use ($source, $orderedIds): array {
                if ($entry['source'] === $source) {
                    $entry['ordered'] = $orderedIds->has($entry['id']);
                }

                return $entry;
            });
        }

        // Preserve registered work; net the customer measures only for a retained reversed source.
        $entries = $entries->map(function (array $entry) use ($to): array {
            $correction = in_array($entry['source'], ['quick', 'ticket'], true)
                ? \App\Modules\Commercial\Support\TimebankLedger::reversal($entry['source'], $entry['id']) : null;
            if ($correction && (! $to || $correction->effective_date->toDateString() <= $to)) {
                $entry['gross_basis_minutes'] = $entry['basis_minutes'];
                $entry['correction_id'] = $correction->id;
                $entry['correction_reason'] = $correction->reason;
                $entry['customer_charge_reversed'] = true;
                foreach (['basis_minutes', 'covered_minutes', 'processed_billable_minutes', 'unresolved_minutes'] as $key) {
                    $entry[$key] = 0;
                }
                $entry['billing_status'] = 'Customer charge reversed #'.$correction->id;
            } else {
                $entry['gross_basis_minutes'] = $entry['basis_minutes'];
                $entry['correction_id'] = null;
                $entry['correction_reason'] = null;
                $entry['customer_charge_reversed'] = false;
            }

            return $entry;
        });

        $metrics = ['work_minutes', 'basis_minutes', 'covered_minutes', 'processed_billable_minutes', 'unresolved_minutes'];
        $groups = $entries->groupBy('object_key');
        $objects = $objects->map(function (array $object, string $key) use ($groups, $metrics): array {
            $rows = $groups->get($key, collect());
            foreach ($metrics as $metric) {
                $object[$metric] = (int) $rows->sum($metric);
            }
            $object['entry_count'] = $rows->count();

            return $object;
        })->values();
        $totals = [];
        foreach ($metrics as $metric) {
            $totals[$metric] = (int) $entries->sum($metric);
        }

        $correctionHistory = collect();
        if (\App\Modules\Commercial\Support\TimebankLedger::installed()) {
            $visibleSources = $entries->mapWithKeys(fn ($e) => [$e['source'].':'.$e['id'] => true]);
            $correctionHistory = \App\Modules\Commercial\Models\TimebankCorrection::where('client_id', $client->id)
                ->when($from, fn ($q) => $q->whereDate('effective_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('effective_date', '<=', $to))
                ->with('effects')->orderBy('id')->get()
                ->filter(fn ($c) => $c->kind === 'adjustment' || $visibleSources->has($c->source_type.':'.$c->source_id))
                ->map(fn ($c) => ['id' => $c->id, 'kind' => $c->kind, 'source_type' => $c->source_type,
                    'source_id' => $c->source_id, 'reason' => $c->reason,
                    'effective_date' => $c->effective_date->toDateString(), 'recorded_at' => $c->created_at->toIso8601String(),
                    'adjustment_minutes' => (int) $c->effects->sum('adjustment_minutes'),
                    'reversed_usage_minutes' => (int) $c->effects->sum('reversed_usage_minutes')])->values();
        }

        return ['client' => $client, 'from' => $from, 'to' => $to, 'objects' => $objects,
            'entries' => $entries->sortBy([['date', 'asc'], ['source', 'asc'], ['id', 'asc']])->values(),
            'totals' => $totals, 'corrections' => $correctionHistory, 'generated_at' => now()];
    }

    private function context(Builder $query, Client $client): Builder
    {
        // Retain legacy customer rows with no context, but reject explicit cross-context records.
        return $query->where(fn (Builder $q) => $q->whereNull('work_context_id')
            ->orWhereHas('workContext', fn (Builder $q) => $q->where('type', 'client')->where('client_id', $client->id)));
    }

    private function dates(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query->when($from, fn (Builder $q) => $q->whereDate('work_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('work_date', '<=', $to));
    }

    private function bounded(Builder $query): Collection
    {
        $rows = $query->limit(self::LIMIT + 1)->get();
        if ($rows->count() > self::LIMIT) {
            throw ValidationException::withMessages(['date_from' => 'This report exceeds 10,000 records in one source. Select a narrower period.']);
        }

        return $rows;
    }

    private function object(string $type, ?int $id, ?string $reference, string $title, ?string $status, bool $archived, ?string $parent): array
    {
        return compact('type', 'id', 'reference', 'title', 'status', 'archived', 'parent');
    }

    private function entry(string $source, object $row, string $key, string $reference, int $work, int $basis,
        int $covered, int $processed, int $unresolved, string $provenance, string $billingStatus): array
    {
        return ['source' => $source, 'id' => $row->id, 'object_key' => $key, 'reference' => $reference,
            'date' => $row->work_date?->toDateString(), 'employee' => $row->user?->name ?? 'Former employee',
            'description' => $row->note, 'invoice_text' => $row->invoice_text ?? null, 'provenance' => $provenance,
            'work_minutes' => $work, 'basis_minutes' => $basis, 'covered_minutes' => $covered,
            'processed_billable_minutes' => $processed, 'unresolved_minutes' => $unresolved,
            'billing_status' => $billingStatus, 'ordered' => false];
    }
}
