<?php

namespace App\Modules\Warroom\Queries;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Queries\CalendarOverlayQuery;
use App\Modules\Calendar\Services\CalendarVisibility;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Warroom\Support\WarroomSources;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Personal filters are applied in addition to native source authority, before totals and previews. */
class MyDayWorkQuery
{
    public function __construct(private WarroomSources $sources) {}

    public function forUser(User $user, ?Carbon $now = null, bool $focus = false, int $page = 1, int $perPage = 25): array
    {
        $now = $now?->copy() ?? now();
        $states = array_intersect_key($this->sources->states($user), array_flip(['tickets', 'tasks', 'calendar']));
        $tickets = collect();
        $tasks = collect();
        $events = collect();
        $counts = ['tickets' => null, 'tasks' => null, 'events' => null, 'overdue' => null, 'unread' => null];
        foreach (['tickets', 'tasks', 'calendar'] as $source) {
            if ($states[$source] !== 'available') {
                continue;
            }
            try {
                if ($source === 'tickets') {
                    $counts['tickets'] = $this->tickets($user)->count();
                    $counts['unread'] = $this->tickets($user)->where('is_unread', true)->count();
                    $tickets = $this->tickets($user)->with(['client:id,name', 'status:id,name,is_closed'])
                        ->orderByRaw('CASE WHEN resolve_due_at IS NULL THEN 1 ELSE 0 END')
                        ->orderBy('resolve_due_at')->orderByDesc('is_unread')->orderByDesc('updated_at')->orderBy('id')->limit(8)->get();
                } elseif ($source === 'tasks') {
                    $counts['tasks'] = $this->tasks($user)->count();
                    $tasks = $this->tasks($user)->with(['client:id,name', 'status:id,name,is_done,is_cancelled'])
                        ->orderByRaw('CASE WHEN scheduled_start_at IS NULL THEN 1 ELSE 0 END')
                        ->orderBy('scheduled_start_at')->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                        ->orderBy('due_at')->orderByDesc('updated_at')->orderBy('id')->limit(8)->get();
                } else {
                    $query = $this->events($user, $now);
                    $counts['events'] = $query->count();
                    $events = $query->with(['calendar.owner', 'participants', 'links'])->orderBy('starts_at')->orderBy('id')->limit(12)->get()
                        ->map(function ($event) use ($user) {
                            $safe = app(CalendarVisibility::class)->maskEvent($event, $user);

                            return (object) ['id' => $event->id, 'title' => $safe['title'],
                                'location' => $safe['location'], 'starts_at' => $event->starts_at->copy(),
                                'ends_at' => $event->ends_at->copy(), 'status' => $event->status,
                                'details_visible' => $safe['details_visible'],
                                'calendar' => (object) ['name' => $safe['calendar_name']]];
                        });
                }
            } catch (QueryException|\PDOException $error) {
                $states[$source] = 'unavailable';
                $counts[$source === 'calendar' ? 'events' : $source] = null;
                if ($source === 'tickets') {
                    $counts['unread'] = null;
                    $tickets = collect();
                } elseif ($source === 'tasks') {
                    $tasks = collect();
                } else {
                    $events = collect();
                }
            }
        }
        $overdue = $this->overdue($user, $now, $states, $page, $perPage);
        $counts['overdue'] = $overdue['total'];
        $data = ['generated_at' => $now, 'window' => ['starts_at' => $now->copy()->startOfDay(), 'ends_at' => $now->copy()->endOfDay()],
            'timezone' => config('app.timezone'), 'sources' => $states, 'counts' => $counts,
            'tickets' => $tickets, 'tasks' => $tasks, 'events' => $events,
            'actions' => $this->sources->links($user, true), 'workday_actions' => $this->sources->workdayLinks($user), 'overdue_state' => $overdue['state']];
        if ($focus) {
            $data['overdue_items'] = collect($overdue['items']);
            $data['overdue_pagination'] = array_diff_key($overdue, ['items' => true]);
        }

        return $data;
    }

    public function overdue(User $user, Carbon $now, array $states, int $page = 1, int $perPage = 25): array
    {
        $active = array_filter(array_intersect_key($states, array_flip(['tickets', 'tasks'])), fn ($state) => $state === 'available');
        $state = in_array('unavailable', [$states['tickets'], $states['tasks']], true) ? 'unavailable'
            : ($active ? 'available' : 'inaccessible');
        $empty = ['state' => $state, 'total' => null, 'page' => $page, 'per_page' => $perPage, 'last_page' => 1, 'items' => []];
        if ($state !== 'available') {
            return $empty;
        }
        try {
            $queries = [];
            if (isset($active['tickets'])) {
                $queries[] = $this->tickets($user)->where('resolve_due_at', '<', $now->copy()->utc())
                    ->leftJoin('clients', 'clients.id', '=', 'tickets.client_id')
                    ->selectRaw("'ticket' as type, tickets.id, tickets.subject as title, tickets.ticket_key as work_key, tickets.resolve_due_at as due_at, tickets.is_unread, clients.name as client")->toBase();
            }
            if (isset($active['tasks'])) {
                $queries[] = $this->tasks($user)->where('due_at', '<', $now->copy()->utc())
                    ->leftJoin('clients', 'clients.id', '=', 'tasks.client_id')
                    ->selectRaw("'task' as type, tasks.id, tasks.title, NULL as work_key, tasks.due_at, 0 as is_unread, clients.name as client")->toBase();
            }
            $union = array_shift($queries);
            foreach ($queries as $query) {
                $union->unionAll($query);
            }
            $paginator = DB::query()->fromSub($union, 'work')->orderBy('due_at')->orderBy('type')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
            $items = $paginator->getCollection()->map(fn ($row) => [
                'type' => $row->type, 'id' => (int) $row->id, 'title' => $row->title, 'key' => $row->work_key,
                'client' => $row->client, 'due_at' => Carbon::parse($row->due_at, 'UTC'),
                'is_unread' => (bool) $row->is_unread,
                'url' => $this->sources->url($row->type === 'ticket' ? 'tech.tickets.show' : 'tech.tasks.show',
                    [$row->type === 'ticket' ? $row->work_key : $row->id]),
            ])->all();

            return ['state' => 'available', 'total' => $paginator->total(), 'page' => $page,
                'per_page' => $perPage, 'last_page' => $paginator->lastPage(), 'items' => $items];
        } catch (QueryException|\PDOException $error) {
            return array_replace($empty, ['state' => 'unavailable']);
        }
    }

    private function tickets(User $user): Builder
    {
        return Ticket::query()->where('tickets.owner_id', $user->id)->whereNull('tickets.closed_at')
            ->whereDoesntHave('status', fn (Builder $query) => $query->where('is_closed', true));
    }

    private function tasks(User $user): Builder
    {
        return Task::query()->where('tasks.assigned_to', $user->id)->whereNull('tasks.completed_at')
            ->where(fn (Builder $query) => $query->whereDoesntHave('status')
                ->orWhereHas('status', fn (Builder $status) => $status->where('is_done', false)->where('is_cancelled', false)));
    }

    private function events(User $user, Carbon $now): Builder
    {
        $ids = app(CalendarOverlayQuery::class)->visibleCalendars($user)->pluck('id');

        return CalendarEvent::query()->whereIn('calendar_id', $ids)->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $now->copy()->endOfDay()->utc())->where('ends_at', '>', $now->copy()->startOfDay()->utc())
            ->where(fn (Builder $query) => $query->where('created_by', $user->id)
                ->orWhereHas('calendar', fn (Builder $calendar) => $calendar->where('owner_type', $user::class)->where('owner_id', $user->id))
                ->orWhereHas('participants', fn (Builder $participant) => $participant->where(fn (Builder $lookup) => $lookup
                    ->where(fn (Builder $internal) => $internal->whereIn('participant_type', ['user', $user::class])->where('participant_id', $user->id))
                    ->orWhere('email', $user->email))));
    }
}
