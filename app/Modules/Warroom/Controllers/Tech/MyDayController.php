<?php

namespace App\Modules\Warroom\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Warroom\Queries\MyDayWorkQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyDayController extends Controller
{
    /**
     * Show the signed-in technician's personal work queue for today.
     */
    public function __invoke(Request $request, MyDayWorkQuery $query): View
    {
        $user = $request->user();
        $now = now();
        $myDay = $query->forUser($user, $now);

        if ($request->query('focus') === 'overdue') {
            $overdueTickets = Ticket::query()
                ->with([
                    'client:id,name',
                    'priority:id,name,level',
                    'status:id,name,is_closed',
                ])
                ->where('owner_id', $user->id)
                ->whereNull('closed_at')
                ->whereDoesntHave('status', fn ($q) => $q->where('is_closed', true))
                ->where('resolve_due_at', '<', $now->utc())
                ->orderBy('resolve_due_at')
                ->get()
                ->map(fn ($ticket) => [
                    'type' => 'ticket',
                    'id' => $ticket->id,
                    'title' => $ticket->subject,
                    'key' => $ticket->ticket_key,
                    'client' => $ticket->client?->name,
                    'due_at' => $ticket->resolve_due_at,
                    'is_unread' => $ticket->is_unread,
                    'url' => route('tech.tickets.show', $ticket),
                ]);

            $overdueTasks = Task::query()
                ->with([
                    'client:id,name',
                    'priority:id,name,level',
                    'status:id,name,is_done,is_cancelled',
                ])
                ->where('assigned_to', $user->id)
                ->whereNull('completed_at')
                ->where(function ($q): void {
                    $q->whereDoesntHave('status')
                        ->orWhereHas('status', fn ($status) => $status
                            ->where('is_done', false)
                            ->where('is_cancelled', false));
                })
                ->where('due_at', '<', $now->utc())
                ->orderBy('due_at')
                ->get()
                ->map(fn ($task) => [
                    'type' => 'task',
                    'id' => $task->id,
                    'title' => $task->title,
                    'client' => $task->client?->name,
                    'due_at' => $task->due_at,
                    'status' => $task->status?->name,
                    'url' => route('tech.tasks.show', $task),
                ]);

            $myDay['overdue_items'] = $overdueTickets->concat($overdueTasks)
                ->sortBy('due_at')
                ->values();
        }

        return view('warroom::Tech.my-day', [
            'myDay' => $myDay,
        ]);
    }
}
