{{-- Time synchronization and explicit employee mapping. --}}
<section class="card mb-3">
    <div class="card-header">Time registration synchronization</div>
    <div class="card-body">
        <form method="post" action="{{ route('tech.admin.system.integrations.tripletex.time-sync', $connection->id) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $connection->config['version'] ?? 0 }}">
            <input type="hidden" name="enabled" value="0">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="tripletex-time-sync" name="enabled" value="1"
                    @checked($connection->status === 'active' && ($connection->config['time_sync_enabled'] ?? true)) onchange="this.form.requestSubmit()">
                <label class="form-check-label" for="tripletex-time-sync">Synchronize time registrations automatically</label>
            </div>
            <p class="form-text">Creates, changes and deletions synchronize both ways. Turning this off pauses transfer and keeps saved time and pending changes.</p>
            <noscript><button class="btn btn-primary" type="submit">Save synchronization setting</button></noscript>
        </form>
        @foreach($connection->config['time_mappings'] ?? [] as $workerId => $mapping)
            <p class="mb-1">
                {{ $workers->firstWhere('id', (int) $workerId)?->name ?? 'Employee' }}
                → {{ collect($connection->config['time_catalog']['employees'] ?? [])->firstWhere('id', $mapping['employee_id'])['firstName'] ?? $mapping['employee_id'] }}
                {{ collect($connection->config['time_catalog']['employees'] ?? [])->firstWhere('id', $mapping['employee_id'])['lastName'] ?? '' }}.
                From {{ $mapping['start_date'] }}.
            </p>
        @endforeach
        @if(($connection->status !== 'active' || !($connection->config['time_sync_enabled'] ?? true)) && !empty($connection->config['time_catalog']))
        <details class="mt-3"><summary>Employee mapping</summary>
            <form method="post" action="{{ route('tech.admin.system.integrations.tripletex.mapping', $connection->id) }}" class="row g-3 mt-1">
                @csrf
                <input type="hidden" name="version" value="{{ $connection->config['version'] }}">
                <div class="col-md-3"><label class="form-label" for="map-user">Nexum employee</label>
                    <select class="form-select" id="map-user" name="user_id" required>@foreach($workers as $worker)<option value="{{ $worker->id }}">{{ $worker->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label" for="map-employee">Tripletex employee</label>
                    <select class="form-select" id="map-employee" name="employee_id" required>@foreach($connection->config['time_catalog']['employees'] as $employee)<option value="{{ $employee['id'] }}">{{ $employee['firstName'] }} {{ $employee['lastName'] }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label" for="map-activity">Default activity</label>
                    <select class="form-select" id="map-activity" name="activity_id" required>@foreach($connection->config['time_catalog']['activities'] as $activity)<option value="{{ $activity['id'] }}">{{ $activity['name'] }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label" for="map-start">Synchronize from</label>
                    <input class="form-control" id="map-start" type="date" name="start_date" value="{{ now('Europe/Oslo')->toDateString() }}" required></div>
                <div class="col-12"><button class="btn btn-outline-primary" type="submit">Save employee mapping</button></div>
            </form>
        </details>
        @endif
        @if($syncStates->isNotEmpty())
            <div class="alert alert-warning mt-3 mb-0">
                <strong>Time synchronization needs attention</strong>
                <ul class="mb-0">@foreach($syncStates as $syncState)
                    <li>{{ $workers->firstWhere('id', $syncState->user_id)?->name ?? 'Employee' }} · {{ $syncState->work_date }}:
                        {{ str_replace('_', ' ', $syncState->error_code) }}</li>
                @endforeach</ul>
                <p class="mb-0 mt-2">Both versions are kept. Resolve the indicated condition; the next scan retries automatically.</p>
            </div>
        @endif
    </div>
</section>
