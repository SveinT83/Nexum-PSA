@extends('layouts.default_tech')

@section('title', $relationship->name)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-start w-100">
        <div>
            <h1 class="h4 mb-0">{{ $relationship->name }}</h1>
            <p class="text-muted mb-0">{{ $relationship->remote_organization_name ?: 'Remote organization not named' }}</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('tech.admin.system.relationships.edit', $relationship) }}" class="btn btn-outline-primary">
                <i class="bi bi-pencil" aria-hidden="true"></i>
                Edit
            </a>
            <a href="{{ route('tech.admin.system.relationships.index') }}" class="btn btn-light">Back</a>
        </div>
    </div>
@endsection

@section('content')
    @if(session('plain_inbound_token'))
        <div class="alert alert-warning">
            <div class="fw-semibold">New inbound token</div>
            <code>{{ session('plain_inbound_token') }}</code>
        </div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Relationship overview -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header">Overview</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase">Direction</div>
                            <div class="fw-semibold">{{ str_replace('_', ' ', ucfirst($relationship->direction)) }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase">Status</div>
                            <div class="fw-semibold">{{ ucfirst($relationship->status) }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase">Health</div>
                            <div class="fw-semibold">{{ ucfirst($relationship->health_status) }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase">Client</div>
                            <div class="fw-semibold">{{ $relationship->client?->name ?: 'None' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase">Vendor</div>
                            <div class="fw-semibold">{{ $relationship->vendor?->name ?: 'None' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted text-uppercase">Last sync</div>
                            <div class="fw-semibold">{{ $relationship->last_successful_sync_at?->diffForHumans() ?: 'Never' }}</div>
                        </div>
                    </div>
                    @if($relationship->failure_summary)
                        <div class="alert alert-danger mt-3 mb-0">{{ $relationship->failure_summary }}</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">Secret rotation</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('tech.admin.system.relationships.rotate-secrets', $relationship) }}" class="d-grid gap-2">
                        @csrf
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="rotate_inbound_token" value="1" id="rotate-inbound-token">
                            <label class="form-check-label" for="rotate-inbound-token">Rotate inbound token</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="rotate_webhook_secret" value="1" id="rotate-webhook-secret">
                            <label class="form-check-label" for="rotate-webhook-secret">Rotate webhook secret</label>
                        </div>
                        <button type="submit" class="btn btn-outline-primary">Rotate selected secrets</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Capabilities -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header">Capabilities</div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
                @foreach($capabilities as $capability)
                    <span class="badge {{ ($relationship->capabilities[$capability] ?? false) ? 'text-bg-success' : 'text-bg-light border' }}">
                        {{ str_replace('_', ' ', ucfirst($capability)) }}
                    </span>
                @endforeach
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Documentation and Knowledge sync -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3 mb-3">
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">Eligible documentation</div>
                <div class="list-group list-group-flush">
                    @forelse($eligibleDocumentations as $documentation)
                        <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div class="min-w-0">
                                <div class="fw-semibold text-truncate">{{ $documentation->title }}</div>
                                <div class="small text-muted">{{ ucfirst($documentation->scope_type) }}{{ $documentation->client ? ' · '.$documentation->client->name : '' }}</div>
                            </div>
                            <form method="POST" action="{{ route('tech.admin.system.relationships.documentations.push', [$relationship, $documentation]) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-primary" @disabled(!($relationship->capabilities['documentation_sync'] ?? false))>Push</button>
                            </form>
                        </div>
                    @empty
                        <div class="list-group-item text-muted">No eligible documentation records.</div>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">Eligible Knowledge articles</div>
                <div class="list-group list-group-flush">
                    @forelse($eligibleArticles as $article)
                        <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div class="min-w-0">
                                <div class="fw-semibold text-truncate">{{ $article->title }}</div>
                                <div class="small text-muted">{{ ucfirst($article->visibility) }}</div>
                            </div>
                            <form method="POST" action="{{ route('tech.admin.system.relationships.knowledge.push', [$relationship, $article]) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-primary" @disabled(!($relationship->capabilities['knowledge_sync'] ?? false))>Push</button>
                            </form>
                        </div>
                    @empty
                        <div class="list-group-item text-muted">No eligible Knowledge articles.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Sync audit -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">Sync links</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Remote ID</th>
                                <th>Status</th>
                                <th>Last sync</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($relationship->syncLinks as $link)
                                <tr>
                                    <td>{{ $link->domain }}</td>
                                    <td class="text-truncate" style="max-width: 12rem;">{{ $link->remote_id ?: 'Pending' }}</td>
                                    <td>{{ $link->sync_status }}</td>
                                    <td>{{ $link->last_synced_at?->diffForHumans() ?: 'Never' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted text-center py-3">No sync links yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">Recent events</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Outcome</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($relationship->syncEvents as $event)
                                <tr>
                                    <td>{{ str_replace('_', ' ', $event->event_type) }}</td>
                                    <td>{{ $event->outcome }}</td>
                                    <td>{{ $event->occurred_at?->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted text-center py-3">No sync events yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection
