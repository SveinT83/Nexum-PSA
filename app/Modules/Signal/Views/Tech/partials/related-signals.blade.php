@php
    $signalSeverityClass = [
        'critical' => 'text-bg-danger',
        'error' => 'text-bg-danger',
        'warning' => 'text-bg-warning',
        'info' => 'text-bg-info',
    ];
@endphp

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-semibold">Signals</span>
            <span class="badge text-bg-light border">{{ $signals->count() }}</span>
        </div>
        <a href="{{ route('tech.admin.system.signals.index') }}" class="btn btn-sm btn-outline-secondary">Open feed</a>
    </div>
    <div class="list-group list-group-flush">
        @forelse($signals as $signal)
            <a href="{{ route('tech.admin.system.signals.show', $signal) }}" class="list-group-item list-group-item-action">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div class="min-w-0">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="fw-semibold text-truncate">{{ $signal->summary ?: ucfirst(str_replace('_', ' ', $signal->signal_type)) }}</span>
                            <span class="badge {{ $signalSeverityClass[$signal->severity] ?? 'text-bg-secondary' }}">{{ ucfirst($signal->severity) }}</span>
                            <span class="badge text-bg-light border">{{ str_replace('_', ' ', $signal->signal_type) }}</span>
                        </div>
                        <div class="small text-muted">
                            {{ ucfirst($signal->source_domain) }}
                            @if($signal->contact)
                                · {{ $signal->contact->display_name }}
                            @endif
                            @if($signal->client)
                                · {{ $signal->client->name }}
                            @endif
                        </div>
                    </div>
                    <div class="text-end flex-shrink-0 small text-muted">
                        <div>{{ $signal->occurred_at?->diffForHumans() ?? '—' }}</div>
                        <div>{{ $signal->confidence }}%</div>
                    </div>
                </div>
            </a>
        @empty
            <div class="list-group-item text-center text-muted py-4">No signals recorded yet.</div>
        @endforelse
    </div>
</div>
