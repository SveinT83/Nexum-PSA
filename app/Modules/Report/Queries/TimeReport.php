<?php

namespace App\Modules\Report\Queries;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** One bounded read snapshot supplies totals, groups, pages and complete exports. */
class TimeReport
{
    public const LIMIT = 10000;

    public function filters(array $input): array
    {
        $rules = [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_filter(['nullable', 'date_format:Y-m-d', filled($input['date_from'] ?? null) ? 'after_or_equal:date_from' : null]),
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'ticket_id' => ['nullable', 'integer', 'min:1'],
            'task_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'in:all,ticket,task'],
            'context' => ['nullable', 'in:all,client,internal'],
            'billable' => ['nullable', 'in:all,yes,no'],
            'status' => ['nullable', 'regex:/^(ticket|task):[1-9][0-9]*$/D'],
            'group_by' => ['nullable', 'in:employee,client,record,date,employee_client'],
            'all_history' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'group_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'format' => ['nullable', 'in:xlsx,pdf'],
        ];
        if (array_diff(array_keys($input), array_keys($rules))) {
            throw ValidationException::withMessages(['filters' => 'Unknown report filter.']);
        }
        $data = Validator::make($input, $rules)->validate();
        $all = (bool) ($data['all_history'] ?? false);
        $filters = array_merge(['date_from' => $all ? null : now('Europe/Oslo')->startOfMonth()->toDateString(),
            'date_to' => $all ? null : now('Europe/Oslo')->toDateString(), 'source' => 'all', 'context' => 'all',
            'billable' => 'all', 'group_by' => 'employee', 'status' => null, 'employee_id' => null,
            'client_id' => null, 'ticket_id' => null, 'task_id' => null, 'page' => 1, 'per_page' => 25,
            'group_page' => 1, 'all_history' => $all, 'format' => null], array_filter($data, fn ($v) => $v !== null));
        $filters['all_history'] = $all;
        foreach (['page', 'group_page', 'per_page'] as $paging) {
            $filters[$paging] = (int) $filters[$paging];
        }
        foreach (['employee_id', 'client_id', 'ticket_id', 'task_id'] as $id) {
            $filters[$id] = isset($data[$id]) ? (int) $data[$id] : null;
        }
        if ($all) {
            $filters['date_from'] = $data['date_from'] ?? null;
            $filters['date_to'] = $data['date_to'] ?? null;
        }
        if ($filters['date_from'] && $filters['date_to'] && $filters['date_from'] > $filters['date_to']) {
            throw ValidationException::withMessages(['date_to' => 'End date must be on or after start date.']);
        }

        return $filters;
    }

    public function build(User $actor, array $input): array
    {
        $filters = $this->filters($input);

        return DB::transaction(function () use ($actor, $filters) {
            foreach (['employee' => $filters['employee_id'], 'client' => $filters['client_id'],
                'ticket' => $filters['ticket_id'], 'task' => $filters['task_id']] as $kind => $id) {
                if ($id && ! app(TimeReportLookups::class)->selected($actor, $kind, $id)) {
                    throw ValidationException::withMessages([$kind.'_id' => 'Choose an available '.$kind.'.']);
                }
            }
            $rows = collect();
            foreach (['ticket', 'task'] as $source) {
                if ($filters['source'] !== 'all' && $filters['source'] !== $source) {
                    continue;
                }
                $query = $source === 'ticket'
                    ? app(\App\Modules\Ticket\Queries\ReportedTime::class)->entries($filters)
                    : app(\App\Modules\Task\Queries\ReportedTime::class)->entries($actor, $filters);
                $entries = $query
                    ->when($filters['date_from'], fn ($q, $date) => $q->whereDate('work_date', '>=', $date))
                    ->when($filters['date_to'], fn ($q, $date) => $q->whereDate('work_date', '<=', $date))
                    ->when($filters['employee_id'], fn ($q, $id) => $q->where('user_id', $id))
                    ->when($filters['billable'] !== 'all', fn ($q) => $q->where('billable', $filters['billable'] === 'yes'))
                    ->orderBy('id')->limit(self::LIMIT + 1)->get();
                $rows = $rows->concat($entries->map(fn ($entry) => ['source' => $source, 'entry' => $entry]));
                if ($rows->count() > self::LIMIT) {
                    throw ValidationException::withMessages(['date_from' => 'This report exceeds 10,000 entries. Select a narrower period.']);
                }
            }
            // Batch-resolve legacy Task owners, including archived Tickets, without N+1 reads.
            $tasks = $rows->where('source', 'task')->map(fn ($row) => $row['entry']->task);
            $parents = Ticket::withTrashed()->whereIn('id', $tasks->where('owner_type', (new Ticket)->getMorphClass())->pluck('owner_id'))->get()->keyBy('id');
            $clientIds = $tasks->pluck('client_id')->merge($parents->pluck('client_id'))
                ->merge($tasks->where('owner_type', (new Client)->getMorphClass())->pluck('owner_id'))
                ->merge($rows->where('source', 'ticket')->map(fn ($r) => $r['entry']->ticket?->client_id))->filter()->unique();
            $clients = Client::query()->whereIn('id', $clientIds)->get(['id', 'name'])->keyBy('id');
            $entries = $rows->map(function ($row) use ($parents, $clients) {
                $entry = $row['entry'];
                $source = $row['source'];
                $record = $source === 'task' ? $entry->task : $entry->ticket;
                $parent = $source === 'task' && $record->owner_type === (new Ticket)->getMorphClass() ? $parents->get($record->owner_id) : null;
                $clientId = $record->client_id ?? $parent?->client_id
                    ?? ($source === 'task' && $record->owner_type === (new Client)->getMorphClass() ? $record->owner_id : null);

                return ['source' => $source, 'id' => $entry->id, 'record_id' => $record->id,
                    'reference' => $source === 'ticket' ? $record->ticket_key : 'Task #'.$record->id,
                    'title' => $source === 'ticket' ? $record->subject : $record->title,
                    'url' => $record->trashed() ? null : route($source === 'ticket' ? 'tech.tickets.show' : 'tech.tasks.show', $record->id),
                    'archived' => $record->trashed(), 'status' => $record->status?->name,
                    'parent_ticket_id' => $parent?->id, 'parent_ticket_reference' => $parent?->ticket_key,
                    'employee_id' => $entry->user_id, 'employee' => $entry->user?->name ?? 'Former employee',
                    'client_id' => $clientId, 'client' => $clientId ? ($clients->get($clientId)?->name ?? 'Former Client') : 'Internal',
                    'date' => $entry->work_date?->toDateString(), 'minutes' => (int) $entry->minutes,
                    'billable' => (bool) $entry->billable, 'note' => $entry->note,
                    'provenance' => $source === 'task' ? ($entry->source_type ?? 'unknown') : 'recorded'];
            })->sortBy([['date', 'desc'], ['source', 'asc'], ['id', 'asc']])->values();
            $groups = $entries->groupBy(fn ($e) => match ($filters['group_by']) {
                'client' => 'client:'.($e['client_id'] ?? 'internal'),
                'record' => $e['source'].':'.$e['record_id'],
                'date' => $e['date'] ?? 'unknown',
                'employee_client' => 'employee:'.($e['employee_id'] ?? 'former').'|client:'.($e['client_id'] ?? 'internal'),
                default => 'employee:'.($e['employee_id'] ?? 'former'),
            })->map(function ($rows, $key) use ($filters) {
                $e = $rows->first();

                return ['key' => $key, 'label' => match ($filters['group_by']) {
                    'client' => $e['client'], 'record' => $e['reference'].' — '.$e['title'],
                    'date' => $e['date'] ?? 'Unknown work date', 'employee_client' => $e['employee'].' / '.$e['client'],
                    default => $e['employee'],
                }, 'minutes' => (int) $rows->sum('minutes'), 'entry_count' => $rows->count()];
            })->sortBy('label')->values();

            return ['filters' => $filters, 'entries' => $entries, 'groups' => $groups,
                'totals' => ['minutes' => (int) $entries->sum('minutes'), 'entry_count' => $entries->count(),
                    'ticket_minutes' => (int) $entries->where('source', 'ticket')->sum('minutes'),
                    'task_minutes' => (int) $entries->where('source', 'task')->sum('minutes')],
                'generated_at' => now()->toIso8601String()];
        });
    }

    public function page(array $report): array
    {
        $f = $report['filters'];
        $total = $report['entries']->count();

        return ['data' => $report['entries']->forPage($f['page'], $f['per_page'])->values()->all(),
            'groups' => $report['groups']->forPage($f['group_page'], $f['per_page'])->values()->all(),
            'totals' => $report['totals'], 'filters' => $f,
            'meta' => ['total' => $total, 'page' => (int) $f['page'], 'per_page' => (int) $f['per_page'],
                'last_page' => max(1, (int) ceil($total / $f['per_page'])),
                'groups_total' => $report['groups']->count(), 'group_page' => (int) $f['group_page'],
                'groups_last_page' => max(1, (int) ceil($report['groups']->count() / $f['per_page'])),
                'totals_scope' => 'all_matching_entries', 'truncated' => false,
                'consistency' => 'One request snapshot; refresh after source changes.', 'generated_at' => $report['generated_at']]];
    }
}
