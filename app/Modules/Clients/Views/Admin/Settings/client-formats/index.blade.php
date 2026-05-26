@extends('layouts.default_tech')

@section('title', 'Client Formats')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">Client Formats</h1>
            <p class="text-muted mb-0">Manage the client format choices used when creating clients and sales opportunities.</p>
        </div>
        <a href="{{ route('tech.admin.index') }}" class="btn btn-outline-secondary">Admin</a>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        <!-- ------------------------------------------------- -->
        <!-- Create client format -->
        <!-- ------------------------------------------------- -->
        <form method="POST" action="{{ route('tech.admin.settings.clients.client-formats.store') }}" class="card mb-4">
            @csrf
            <div class="card-header">
                <h2 class="h5 mb-0">New Client Format</h2>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" placeholder="Limited Company" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <label for="code" class="form-label">Code</label>
                        <input type="text" id="code" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code') }}" placeholder="AS" required>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="description" class="form-label">Description</label>
                        <input type="text" id="description" name="description" class="form-control @error('description') is-invalid @enderror" value="{{ old('description') }}">
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-1">
                        <label for="sort_order" class="form-label">Sort</label>
                        <input type="number" id="sort_order" name="sort_order" class="form-control @error('sort_order') is-invalid @enderror" min="0" value="{{ old('sort_order', 100) }}">
                        @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-1 form-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input" checked>
                        <label for="is_active" class="form-check-label">Active</label>
                    </div>
                    <div class="col-md-1 d-grid">
                        <button type="submit" class="btn btn-primary">Add</button>
                    </div>
                </div>
            </div>
        </form>

        <!-- ------------------------------------------------- -->
        <!-- Existing client formats -->
        <!-- ------------------------------------------------- -->
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Description</th>
                        <th>Sort</th>
                        <th>Clients</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($formats as $format)
                        <tr>
                            <td>
                                <input type="text" form="clientFormat{{ $format->id }}" name="name" class="form-control form-control-sm" value="{{ old('formats.'.$format->id.'.name', $format->name) }}" required>
                            </td>
                            <td>
                                <input type="text" form="clientFormat{{ $format->id }}" name="code" class="form-control form-control-sm" value="{{ old('formats.'.$format->id.'.code', $format->code) }}" required>
                            </td>
                            <td>
                                <input type="text" form="clientFormat{{ $format->id }}" name="description" class="form-control form-control-sm" value="{{ old('formats.'.$format->id.'.description', $format->description) }}">
                            </td>
                            <td style="width: 7rem;">
                                <input type="number" form="clientFormat{{ $format->id }}" name="sort_order" class="form-control form-control-sm" min="0" value="{{ old('formats.'.$format->id.'.sort_order', $format->sort_order) }}">
                            </td>
                            <td>{{ $format->clients_count }}</td>
                            <td>
                                <input type="hidden" form="clientFormat{{ $format->id }}" name="is_active" value="0">
                                <div class="form-check form-switch">
                                    <input type="checkbox" form="clientFormat{{ $format->id }}" name="is_active" value="1" class="form-check-input" @checked($format->is_active)>
                                </div>
                            </td>
                            <td class="text-end">
                                <form id="clientFormat{{ $format->id }}" method="POST" action="{{ route('tech.admin.settings.clients.client-formats.update', $format) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No client formats have been configured.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
