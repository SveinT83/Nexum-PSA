@extends('layouts.default_tech')

@section('title', 'My Day')

@section('pageHeader')
    <div class="col">
        <div class="d-flex align-items-center gap-2">
            <span class="badge text-bg-primary">My Day</span>
            <h1 class="h5 mb-0">{{ $myDay['generated_at']->format('l, M j') }}</h1>
        </div>
    </div>
    <div class="col-auto">
        <span class="small text-muted">Updated {{ $myDay['generated_at']->format('H:i') }}</span>
    </div>
@endsection

@section('content')
    <!-- Personal pulse -->
    <div class="row g-2 mb-3">
        @foreach([
            ['label' => 'Tickets', 'value' => $myDay['counts']['tickets'], 'tone' => $myDay['counts']['unread'] > 0 ? 'warning' : 'primary', 'icon' => 'bi-ticket-detailed'],
            ['label' => 'Tasks', 'value' => $myDay['counts']['tasks'], 'tone' => 'info', 'icon' => 'bi-check2-square'],
            ['label' => 'Events', 'value' => $myDay['counts']['events'], 'tone' => 'secondary', 'icon' => 'bi-calendar3'],
            ['label' => 'Overdue', 'value' => $myDay['counts']['overdue'], 'tone' => $myDay['counts']['overdue'] > 0 ? 'danger' : 'success', 'icon' => 'bi-exclamation-triangle'],
        ] as $metric)
            <div class="col-6 col-xl-3">
                <div class="card h-100 border-{{ $metric['tone'] }}">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="small text-uppercase text-muted fw-semibold">{{ $metric['label'] }}</div>
                                <div class="h2 mb-0">{{ $metric['value'] }}</div>
                            </div>
                            <span class="badge text-bg-{{ $metric['tone'] }} rounded-pill">
                                <i class="bi {{ $metric['icon'] }}" aria-hidden="true"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Schedule -->
    <div class="card mb-3">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0">Today</h2>
            @if(Route::has('tech.calendar.index'))
                <a href="{{ route('tech.calendar.index') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-calendar3 me-1" aria-hidden="true"></i> Calendar
                </a>
            @endif
        </div>
        <div class="list-group list-group-flush">
            @forelse($myDay['events'] as $event)
                <div class="list-group-item py-3">
                    <div class="d-flex justify-content-between gap-3">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate">{{ $event->title }}</div>
                            <div class="small text-muted text-truncate">
                                {{ $event->calendar?->name ?? 'Calendar' }}
                                @if($event->location)
                                    <span class="mx-1">/</span>{{ $event->location }}
                                @endif
                            </div>
                        </div>
                        <div class="text-end small flex-shrink-0">
                            <div class="fw-semibold">{{ $event->starts_at?->timezone(config('app.timezone'))->format('H:i') }}</div>
                            <div class="text-muted">{{ $event->ends_at?->timezone(config('app.timezone'))->format('H:i') }}</div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="list-group-item text-muted py-4 text-center">No calendar events today.</div>
            @endforelse
        </div>
    </div>

    <!-- Work queues -->
    <div class="row g-3">
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header py-2 d-flex align-items-center justify-content-between">
                    <h2 class="h6 mb-0">Tickets</h2>
                    @if(Route::has('tech.tickets.index'))
                        <a href="{{ route('tech.tickets.index') }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
                <div class="list-group list-group-flush">
                    @forelse($myDay['tickets'] as $ticket)
                        @php
                            $ticketDue = $ticket->resolve_due_at;
                            $ticketOverdue = $ticketDue?->lt($myDay['generated_at']) ?? false;
                        @endphp
                        <a href="{{ Route::has('tech.tickets.show') ? route('tech.tickets.show', $ticket) : '#' }}" class="list-group-item list-group-item-action py-3">
                            <div class="d-flex justify-content-between gap-2">
                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate">{{ $ticket->subject }}</div>
                                    <div class="small text-muted text-truncate">
                                        {{ $ticket->ticket_key }}
                                        @if($ticket->client)
                                            <span class="mx-1">/</span>{{ $ticket->client->name }}
                                        @endif
                                    </div>
                                </div>
                                <div class="text-end flex-shrink-0">
                                    @if($ticketDue)
                                        <span class="badge text-bg-{{ $ticketOverdue ? 'danger' : 'light' }} {{ $ticketOverdue ? '' : 'text-dark' }}">
                                            {{ $ticketOverdue ? 'Overdue' : $ticketDue->timezone(config('app.timezone'))->format('H:i') }}
                                        </span>
                                    @endif
                                    @if($ticket->is_unread)
                                        <span class="badge text-bg-warning">Unread</span>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="list-group-item text-muted py-4 text-center">No assigned open tickets.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header py-2 d-flex align-items-center justify-content-between">
                    <h2 class="h6 mb-0">Tasks</h2>
                    @if(Route::has('tech.tasks.index'))
                        <a href="{{ route('tech.tasks.index') }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
                <div class="list-group list-group-flush">
                    @forelse($myDay['tasks'] as $task)
                        @php
                            $taskDue = $task->due_at;
                            $taskStarts = $task->scheduled_start_at;
                            $taskOverdue = $taskDue?->lt($myDay['generated_at']) ?? false;
                        @endphp
                        <a href="{{ Route::has('tech.tasks.show') ? route('tech.tasks.show', $task) : '#' }}" class="list-group-item list-group-item-action py-3">
                            <div class="d-flex justify-content-between gap-2">
                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate">{{ $task->title }}</div>
                                    <div class="small text-muted text-truncate">
                                        {{ $task->client?->name ?? 'Internal' }}
                                        @if($task->status)
                                            <span class="mx-1">/</span>{{ $task->status->name }}
                                        @endif
                                    </div>
                                </div>
                                <div class="text-end flex-shrink-0">
                                    @if($taskStarts)
                                        <span class="badge text-bg-light text-dark">{{ $taskStarts->timezone(config('app.timezone'))->format('H:i') }}</span>
                                    @elseif($taskDue)
                                        <span class="badge text-bg-{{ $taskOverdue ? 'danger' : 'light' }} {{ $taskOverdue ? '' : 'text-dark' }}">
                                            {{ $taskOverdue ? 'Overdue' : $taskDue->timezone(config('app.timezone'))->format('M j') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="list-group-item text-muted py-4 text-center">No assigned open tasks.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <!-- Quick actions -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Actions</h2>
        </div>
        <div class="list-group list-group-flush">
            @foreach($myDay['actions'] as $action)
                <a href="{{ $action['href'] }}" class="list-group-item list-group-item-action py-2">
                    <i class="bi {{ $action['icon'] }} me-2" aria-hidden="true"></i>{{ $action['label'] }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="card">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Queue</h2>
        </div>
        <div class="list-group list-group-flush">
            <div class="list-group-item d-flex justify-content-between py-2">
                <span>Unread</span>
                <span class="badge text-bg-{{ $myDay['counts']['unread'] > 0 ? 'warning' : 'light' }} {{ $myDay['counts']['unread'] > 0 ? '' : 'text-dark' }}">
                    {{ $myDay['counts']['unread'] }}
                </span>
            </div>
            <div class="list-group-item d-flex justify-content-between py-2">
                <span>Overdue</span>
                <span class="badge text-bg-{{ $myDay['counts']['overdue'] > 0 ? 'danger' : 'success' }}">
                    {{ $myDay['counts']['overdue'] }}
                </span>
            </div>
        </div>
    </div>
@endsection

@section('rightbar')
    <!-- Focus -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Focus</h2>
        </div>
        <div class="list-group list-group-flush">
            <div class="list-group-item py-2 d-flex justify-content-between">
                <span>Calendar</span>
                <span class="fw-semibold">{{ $myDay['counts']['events'] }}</span>
            </div>
            <div class="list-group-item py-2 d-flex justify-content-between">
                <span>Work items</span>
                <span class="fw-semibold">{{ $myDay['counts']['tickets'] + $myDay['counts']['tasks'] }}</span>
            </div>
            <div class="list-group-item py-2 d-flex justify-content-between">
                <span>Updated</span>
                <span class="fw-semibold">{{ $myDay['generated_at']->format('H:i') }}</span>
            </div>
        </div>
    </div>
@endsection
