@extends('layouts.default_tech')

@section('title', 'Warroom')

@section('pageHeader')
    <div class="col">
        <div class="d-flex align-items-center gap-2">
            <span class="badge text-bg-dark">Warroom</span>
            <h1 class="h5 mb-0">Operational Command Center</h1>
        </div>
    </div>
    <div class="col-auto">
        <span class="small text-muted">Updated {{ $warroom['generated_at']->format('H:i') }}</span>
    </div>
@endsection

@section('content')
    <!-- Unavailable authorized sources are not rendered as healthy empty queues. -->
    @foreach($warroom['sources'] as $source => $state)
        @if($state === 'unavailable')
            <div class="alert alert-warning py-2" role="status">{{ ucfirst($source) }} data is unavailable.</div>
        @endif
    @endforeach
    <!-- Warroom pulse -->
    @if($warroom['sections']['pulse'] === 'available')
        <div class="row g-2 mb-3">
            @foreach($warroom['pulse'] as $metric)
                <div class="col-12 col-md-6 col-xl-3">
                    <a href="{{ $metric['href'] ?? '#' }}" class="text-decoration-none">
                        <div class="card border-{{ $metric['tone'] }} h-100">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="small text-uppercase text-muted fw-semibold">{{ $metric['label'] }}</div>
                                        <div class="display-6 fw-semibold text-dark">{{ $metric['value'] }}</div>
                                    </div>
                                    <span class="badge text-bg-{{ $metric['tone'] }} rounded-pill">
                                        <i class="bi {{ $metric['icon'] }}" aria-hidden="true"></i>
                                    </span>
                                </div>
                                <div class="small text-muted mt-2">{{ $metric['meta'] }}</div>
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif

    @if($warroom['storage_should_order']['visible'])
        <!-- Storage reorder pressure stays quiet at zero and is visible only to Storage-authorized users. -->
        <div class="alert alert-warning d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2 py-2 mb-3" role="alert">
            <div class="d-flex align-items-center gap-2">
                <span class="badge text-bg-warning rounded-pill">
                    {{ $warroom['storage_should_order']['count'] }}
                </span>
                <div>
                    <div class="fw-semibold">Storage needs ordering</div>
                    <div class="small">{{ $warroom['storage_should_order']['count'] }} {{ $warroom['storage_should_order']['count'] === 1 ? 'item needs' : 'items need' }} ordering.</div>
                </div>
            </div>
            @if($warroom['storage_should_order']['href'])
                <a href="{{ $warroom['storage_should_order']['href'] }}" class="btn btn-sm btn-outline-dark flex-shrink-0">
                    <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Open Should order
                </a>
            @endif
        </div>
    @endif

    <!-- Main operations grid -->
    <div class="row g-3">
        <div class="col-12 col-xl-8">
            @if($warroom['sections']['tickets'] === 'available')
                <div class="card mb-3">
                    <div class="card-header py-2 d-flex align-items-center justify-content-between">
                        <h2 class="h6 mb-0">Ticket Fireline</h2>
                        @if(Route::has('tech.tickets.index'))
                            <a href="{{ route('tech.tickets.index') }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-arrow-right-short" aria-hidden="true"></i> Tickets
                            </a>
                        @endif
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Ticket</th>
                                    <th>Subject</th>
                                    <th>Signal</th>
                                    <th class="text-end">SLA</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($warroom['latest_tickets'] as $ticket)
                                    @php
                                        $due = $ticket->resolve_due_at ? \Illuminate\Support\Carbon::parse($ticket->resolve_due_at, 'UTC')->timezone(config('app.timezone')) : null;
                                        $isOverdue = $due && $due->isPast();
                                    @endphp
                                    <tr>
                                        <td class="fw-semibold">
                                            @if(Route::has('tech.tickets.show') && $ticket->ticket_key)
                                                <a href="{{ route('tech.tickets.show', $ticket->ticket_key) }}" class="text-decoration-none">{{ $ticket->ticket_key }}</a>
                                            @else
                                                {{ $ticket->ticket_key ?? '#'.$ticket->id }}
                                            @endif
                                        </td>
                                        <td class="text-truncate">{{ $ticket->subject ?? 'Untitled ticket' }}</td>
                                        <td>
                                            @if($ticket->is_unread)
                                                <span class="badge text-bg-warning">Unread</span>
                                            @endif
                                            @if(!$ticket->owner_id)
                                                <span class="badge text-bg-secondary">Unassigned</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if($due)
                                                <span class="badge text-bg-{{ $isOverdue ? 'danger' : 'light' }} {{ $isOverdue ? '' : 'text-dark' }}">
                                                    {{ $isOverdue ? 'Overdue' : $due->format('H:i') }}
                                                </span>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-muted py-4 text-center">No open ticket pressure right now.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="row g-3">
                @if($warroom['sections']['asset_alerts'] === 'available')
                    <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex align-items-center justify-content-between">
                            <h2 class="h6 mb-0">Asset Alerts</h2>
                            @if(Route::has('tech.assets.index'))
                                <a href="{{ route('tech.assets.index') }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-hdd-network" aria-hidden="true"></i>
                                </a>
                            @endif
                        </div>
                        <div class="list-group list-group-flush">
                            @forelse($warroom['latest_alerts'] as $alert)
                                <div class="list-group-item py-2">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="fw-semibold text-truncate">{{ $alert->title ?? 'Asset alert' }}</div>
                                        <span class="badge text-bg-danger">{{ $alert->status ?? 'open' }}</span>
                                    </div>
                                    <div class="small text-muted">
                                        Last seen {{ $alert->last_seen_at ? \Illuminate\Support\Carbon::parse($alert->last_seen_at)->diffForHumans() : 'unknown' }}
                                    </div>
                                </div>
                            @empty
                                <div class="list-group-item text-muted py-4 text-center">No active asset alerts.</div>
                            @endforelse
                        </div>
                    </div>
                    </div>
                @endif

                @if($warroom['sections']['calendar'] === 'available')
                    <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex align-items-center justify-content-between">
                            <h2 class="h6 mb-0">{{ $warroom['calendar_events_label'] }}</h2>
                            @if(Route::has('tech.calendar.index'))
                                <a href="{{ route('tech.calendar.index') }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                                </a>
                            @endif
                        </div>
                        <div class="list-group list-group-flush">
                            @forelse($warroom['calendar_events'] as $event)
                                @php
                                    $startsAt = $event->starts_at ? \Illuminate\Support\Carbon::parse($event->starts_at)->timezone(config('app.timezone')) : null;
                                @endphp
                                <div class="list-group-item py-2">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="fw-semibold text-truncate">{{ $event->title ?? 'Calendar event' }}</div>
                                        <span class="small text-muted">{{ $startsAt?->format('H:i') ?? '-' }}</span>
                                    </div>
                                    <div class="small text-muted">
                                        {{ $startsAt?->isToday() ? ($event->status ?? 'scheduled') : $startsAt?->format('M j') }}
                                    </div>
                                </div>
                            @empty
                                <div class="list-group-item text-muted py-4 text-center">No upcoming calendar events.</div>
                            @endforelse
                        </div>
                    </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-12 col-xl-4">
            @if($warroom['sections']['domain_radar'] === 'available')
                <div class="card mb-3">
                    <div class="card-header py-2">
                        <h2 class="h6 mb-0">Domain Radar</h2>
                    </div>
                    <div class="list-group list-group-flush">
                        @foreach($warroom['operations'] as $operation)
                            <a href="{{ $operation['href'] ?? '#' }}" class="list-group-item list-group-item-action py-2">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="text-primary"><i class="bi {{ $operation['icon'] }}" aria-hidden="true"></i></span>
                                        <span>{{ $operation['label'] }}</span>
                                    </div>
                                    <span class="badge text-bg-light text-dark">{{ $operation['value'] }}</span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($warroom['sections']['integrations'] === 'available')
                <div class="card">
                <div class="card-header py-2 d-flex align-items-center justify-content-between">
                    <h2 class="h6 mb-0">Integration Health</h2>
                    @if(Route::has('tech.admin.system.integrations.index'))
                        <a href="{{ route('tech.admin.system.integrations.index') }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-plug" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
                <div class="list-group list-group-flush">
                    @forelse($warroom['recent_integrations'] as $integration)
                        <div class="list-group-item py-2">
                            <div class="d-flex justify-content-between gap-2">
                                <div class="fw-semibold text-truncate">{{ $integration->name ?? $integration->type ?? 'Integration' }}</div>
                                <span class="badge text-bg-{{ !$integration->has_error && $integration->is_healthy ? 'success' : 'warning' }}">
                                    {{ $integration->is_healthy === null && !$integration->has_error ? 'Unknown' : (!$integration->has_error && $integration->is_healthy ? 'Healthy' : 'Attention') }}
                                </span>
                            </div>
                            <div class="small text-muted text-truncate">
                                {{ $integration->has_error ? 'Attention required' : ($integration->last_sync_at ? 'Synced '.\Illuminate\Support\Carbon::parse($integration->last_sync_at)->diffForHumans() : 'No sync recorded') }}
                            </div>
                        </div>
                    @empty
                        <div class="list-group-item text-muted py-4 text-center">No integrations configured.</div>
                    @endforelse
                </div>
            </div>
            @endif
        </div>
    </div>
@endsection

@section('sidebar')
    @include('warroom::Tech.partials._sidebar')

    <!-- Warroom lanes -->
    @if($warroom['sections']['lanes'] === 'available')
        <div class="card mb-3">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Warroom Lanes</h2>
        </div>
        <div class="list-group list-group-flush">
            @foreach($warroom['lanes'] as $lane)
                <a href="{{ $lane['href'] }}" class="list-group-item list-group-item-action py-2">
                    <i class="bi {{ $lane['icon'] }} me-2" aria-hidden="true"></i>{{ $lane['label'] }}
                </a>
            @endforeach
        </div>
    </div>
    @endif

    <div class="card">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Shift Focus</h2>
        </div>
        <div class="list-group list-group-flush">
            <div class="list-group-item py-2 d-flex justify-content-between">
                <span>Live operations</span>
                <span class="badge text-bg-primary">Now</span>
            </div>
            <div class="list-group-item py-2 d-flex justify-content-between">
                <span>Customer risk</span>
                <span class="badge text-bg-light text-dark">Watch</span>
            </div>
            <div class="list-group-item py-2 d-flex justify-content-between">
                <span>Billing readiness</span>
                <span class="badge text-bg-light text-dark">Queue</span>
            </div>
        </div>
    </div>
@endsection

@section('rightbar')
    <!-- System health -->
    @if($warroom['sections']['system_health'] === 'available')
        <div class="card mb-3">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">System Health</h2>
        </div>
        <div class="card-body py-2">
            @foreach($warroom['system'] as $key => $count)
                <div class="d-flex justify-content-between small mb-2">
                    <span>{{ ucwords(str_replace('_', ' ', $key)) }}</span>
                    <span class="fw-semibold">{{ $count }}</span>
                </div>
            @endforeach
            @if(($warroom['system']['integrations_total'] ?? 0) > 0)
                @php($healthyWidth = max(0, 100 - round($warroom['system']['integrations_unhealthy'] / $warroom['system']['integrations_total'] * 100)))
                <div class="progress" role="progressbar" aria-label="Integration health" aria-valuenow="{{ $healthyWidth }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar bg-success" style="width: {{ $healthyWidth }}%"></div>
                </div>
            @endif
        </div>
    </div>
    @endif

    @if($warroom['sections']['next_actions'] === 'available')
        <div class="card">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Next Actions</h2>
        </div>
        <div class="list-group list-group-flush">
            @foreach($warroom['next_actions'] as $action)
                <a href="{{ $action['href'] }}" class="list-group-item list-group-item-action py-2">
                    <div class="fw-semibold">{{ $action['title'] }}</div>
                    <div class="small text-muted">{{ $action['description'] }}</div>
                </a>
            @endforeach
        </div>
    </div>
    @endif
@endsection
