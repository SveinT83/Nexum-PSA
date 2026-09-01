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

        $ticketsQuery = $this->ticketsQuery($user);
        $tasksQuery = $this->tasksQuery($user);
        $eventsQuery = $this->eventsQuery($user, $startsAt, $endsAt);

        $ticketsFullCount = $ticketsQuery->count();
        $tasksFullCount = $tasksQuery->count();
        $eventsFullCount = $eventsQuery->count();

        $tickets = $ticketsQuery
            ->orderByRaw('CASE WHEN resolve_due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('resolve_due_at')
            ->orderByDesc('is_unread')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        $tasks = $tasksQuery
            ->orderByRaw('CASE WHEN scheduled_start_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_start_at')
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        $events = $eventsQuery
            ->orderBy('starts_at')
            ->limit(12)
            ->get();

        $unreadCount = $this->ticketsQuery($user)->where('is_unread', true)->count();
        $overdueCount = $this->overdueCount($user, $now);

        return [
            'generated_at' => $now,
            'window' => [
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ],
            'counts' => [
                'tickets' => $ticketsFullCount,
                'tasks' => $tasksFullCount,
                'events' => $eventsFullCount,
                'overdue' => $overdueCount,
                'unread' => $unreadCount,
            ],
            'tickets' => $tickets,
            'tasks' => $tasks,
            'events' => $events,
            'actions' => $this->actions(),
        ];
    }

    private function ticketsQuery(User $user): Builder
    {
        if (! Schema::hasTable('tickets')) {
            return Ticket::query()->whereRaw('1=0');
        }

        return Ticket::query()
            ->with([
                'client:id,name',
                'priority:id,name,level',
                'status:id,name,is_closed',
            ])
            ->where('owner_id', $user->id)
            ->whereNull('closed_at')
            ->whereDoesntHave('status', fn (Builder $query) => $query->where('is_closed', true));
    }

    private function tasksQuery(User $user): Builder
    {
        if (! Schema::hasTable('tasks')) {
            return Task::query()->whereRaw('1=0');
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
            });
    }

    private function eventsQuery(User $user, Carbon $startsAt, Carbon $endsAt): Builder
    {
        if (! Schema::hasTable('calendar_events')) {
            return CalendarEvent::query()->whereRaw('1=0');
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
            });
    }

    private function overdueCount(User $user, Carbon $now): int
    {
        $overdueTickets = $this->ticketsQuery($user)
            ->where('resolve_due_at', '<', $now->copy()->utc())
            ->count();

        $overdueTasks = $this->tasksQuery($user)
            ->where('due_at', '<', $now->copy()->utc())
            ->count();

        return $overdueTickets + $overdueTasks;
    }

    private function actions(): array
    {
        return collect([
            ['label' => 'New ticket', 'route' => 'tech.tickets.create', 'icon' => 'bi-ticket-detailed'],
            ['label' => 'New task', 'route' => 'tech.tasks.create', 'icon' => 'bi-check2-square'],
            ['label' => 'Calendar', 'route' => 'tech.calendar.index', 'icon' => 'bi-calendar3'],
            ['label' => 'Mail', 'route' => 'tech.mail.index', 'icon' => 'bi-inbox'],
        ])
            ->filter(fn (array $action): bool => Route::has($action['route']))
            ->map(fn (array $action): array => array_merge($action, ['href' => route($action['route'])]))
            ->values()
            ->all();
    }
}
