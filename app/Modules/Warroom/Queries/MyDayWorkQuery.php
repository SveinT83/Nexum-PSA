<?php

namespace App\Modules\Warroom\Queries;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class MyDayWorkQuery
{
    /**
     * Build a personal operational snapshot without taking ownership of source domains.
     */
    public function forUser(User $user, ?Carbon $now = null): array
    {
        $now = $now?->copy() ?? now();
        $startsAt = $now->copy()->startOfDay();
        $endsAt = $now->copy()->endOfDay();

        $tickets = $this->tickets($user);
        $tasks = $this->tasks($user);
        $events = $this->events($user, $startsAt, $endsAt);

        return [
            'generated_at' => $now,
            'window' => [
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ],
            'counts' => [
                'tickets' => $tickets->count(),
                'tasks' => $tasks->count(),
                'events' => $events->count(),
                'overdue' => $this->overdueCount($tickets, $tasks, $now),
                'unread' => $tickets->where('is_unread', true)->count(),
            ],
            'tickets' => $tickets,
            'tasks' => $tasks,
            'events' => $events,
            'actions' => $this->actions(),
        ];
    }

    private function tickets(User $user): Collection
    {
        if (! Schema::hasTable('tickets')) {
            return collect();
        }

        return Ticket::query()
            ->with([
                'client:id,name',
                'priority:id,name,level',
                'status:id,name,is_closed',
            ])
            ->where('owner_id', $user->id)
            ->whereNull('closed_at')
            ->whereDoesntHave('status', fn (Builder $query) => $query->where('is_closed', true))
            ->orderByRaw('CASE WHEN resolve_due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('resolve_due_at')
            ->orderByDesc('is_unread')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();
    }

    private function tasks(User $user): Collection
    {
        if (! Schema::hasTable('tasks')) {
            return collect();
        }

        return Task::query()
            ->with([
                'client:id,name',
                'priority:id,name,level',
                'status:id,name,is_done,is_cancelled',
            ])
            ->where('assigned_to', $user->id)
            ->whereNull('completed_at')
            ->where(function (Builder $query): void {
                $query
                    ->whereDoesntHave('status')
                    ->orWhereHas('status', fn (Builder $status) => $status
                        ->where('is_done', false)
                        ->where('is_cancelled', false));
            })
            ->orderByRaw('CASE WHEN scheduled_start_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_start_at')
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();
    }

    private function events(User $user, Carbon $startsAt, Carbon $endsAt): Collection
    {
        if (! Schema::hasTable('calendar_events')) {
            return collect();
        }

        $relations = [];

        if (Schema::hasTable('calendars')) {
            $relations[] = 'calendar:id,name,color,owner_type,owner_id';
        }

        if (Schema::hasTable('calendar_participants')) {
            $relations[] = 'participants';
        }

        return CalendarEvent::query()
            ->with($relations)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $endsAt->copy()->utc())
            ->where('ends_at', '>', $startsAt->copy()->utc())
            ->where(function (Builder $query) use ($user): void {
                $query->where('created_by', $user->id);

                if (Schema::hasTable('calendars')) {
                    $query->orWhereHas('calendar', fn (Builder $calendar) => $calendar
                        ->where('owner_type', $user::class)
                        ->where('owner_id', $user->id));
                }

                if (Schema::hasTable('calendar_participants')) {
                    $query->orWhereHas('participants', fn (Builder $participant) => $participant
                        ->where(function (Builder $lookup) use ($user): void {
                            $lookup
                                ->where(function (Builder $internal) use ($user): void {
                                    $internal
                                        ->whereIn('participant_type', ['user', $user::class])
                                        ->where('participant_id', $user->id);
                                })
                                ->orWhere('email', $user->email);
                        }));
                }
            })
            ->orderBy('starts_at')
            ->limit(12)
            ->get();
    }

    private function overdueCount(Collection $tickets, Collection $tasks, Carbon $now): int
    {
        $overdueTickets = $tickets->filter(
            fn (Ticket $ticket): bool => $ticket->resolve_due_at?->lt($now) ?? false
        )->count();

        $overdueTasks = $tasks->filter(
            fn (Task $task): bool => $task->due_at?->lt($now) ?? false
        )->count();

        return $overdueTickets + $overdueTasks;
    }

    private function actions(): array
    {
        return collect([
            ['label' => 'New ticket', 'route' => 'tech.tickets.create', 'icon' => 'bi-ticket-detailed'],
            ['label' => 'New task', 'route' => 'tech.tasks.create', 'icon' => 'bi-check2-square'],
            ['label' => 'Calendar', 'route' => 'tech.calendar.index', 'icon' => 'bi-calendar3'],
            ['label' => 'Inbox', 'route' => 'tech.inbox.index', 'icon' => 'bi-inbox'],
        ])
            ->filter(fn (array $action): bool => Route::has($action['route']))
            ->map(fn (array $action): array => array_merge($action, ['href' => route($action['route'])]))
            ->values()
            ->all();
    }
}
