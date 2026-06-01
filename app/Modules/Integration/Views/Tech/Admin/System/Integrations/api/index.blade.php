@extends('layouts.default_tech')

@section('title', 'API Management')

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
            <li class="breadcrumb-item active" aria-current="page">API Management</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center">
        <h1>API Management</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createApiKeyModal">
            <i class="bi bi-plus-lg"></i> Create API Key
        </button>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">API Keys</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Scopes</th>
                                        <th>Last Used</th>
                                        <th>Created At</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($apiKeys as $key)
                                        <tr>
                                            <td>
                                                <strong>{{ $key->name }}</strong>
                                            </td>
                                            <td>
                                                @foreach($key->abilities ?? [] as $ability)
                                                    <span class="badge text-bg-light text-dark">{{ $abilityCatalog->labelFor($ability) }}</span>
                                                @endforeach
                                            </td>
                                            <td>{{ $key->last_used_at ? $key->last_used_at->diffForHumans() : 'Never' }}</td>
                                            <td>{{ $key->created_at->format('Y-m-d H:i') }}</td>
                                            <td class="text-end">
                                                <form action="{{ route('tech.admin.system.integrations.api.destroy', $key->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to revoke this API key?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash"></i> Revoke
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">
                                                No API keys found. Create one to get started.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create API Key Modal -->
    <div class="modal fade" id="createApiKeyModal" tabindex="-1" aria-labelledby="createApiKeyModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form action="{{ route('tech.admin.system.integrations.api.store') }}" method="POST">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createApiKeyModalLabel">Create New API Key</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Key Name</label>
                            <input type="text" class="form-control" id="name" name="name" placeholder="e.g. n8n automation" required>
                            <div class="form-text">A descriptive name to identify this integration.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Scopes</label>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="full_access" name="full_access" value="1">
                                <label class="form-check-label" for="full_access">Full access</label>
                            </div>

                            <div class="border rounded p-2">
                                @foreach($abilities as $ability => $details)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ability_{{ str_replace('.', '_', $ability) }}" name="abilities[]" value="{{ $ability }}" checked>
                                        <label class="form-check-label" for="ability_{{ str_replace('.', '_', $ability) }}">
                                            <span class="fw-semibold">{{ $details['label'] }}</span>
                                            <span class="text-muted small d-block">{{ $details['description'] }}</span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Key</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('rightbar')
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Security Info</h5>
        </div>
        <div class="card-body">
            <p class="small text-muted">
                <i class="bi bi-info-circle"></i> API keys provide full access to the system based on their permissions. Never share them.
            </p>
            <div class="alert alert-warning py-2 small mb-0">
                API scopes are enforced by Sanctum token abilities on protected API routes.
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Documentation</h5>
            <i class="bi bi-info-circle"></i>
        </div>
        <div class="card-body">
            <p class="small text-muted">
                Access the interactive Swagger UI documentation to explore and test our API endpoints.
            </p>
            <div class="d-grid gap-2">
                <a href="{{ route('tech.admin.system.integrations.api.docs') }}" class="btn btn-sm btn-outline-primary" target="_blank">
                    <i class="bi bi-file-earmark-code me-1"></i> Open API Documentation
                </a>
            </div>
        </div>
    </div>
@endsection
