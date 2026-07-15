@extends('layouts.default_tech')

@section('title', 'Import Preview #' . $preview->id)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">Import Preview #{{ $preview->id }}</h1>
        <x-buttons.back :url="route('tech.admin.system.data-exchange.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Import Preview Summary -->
    <!-- ------------------------------------------------- -->
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="small text-muted text-uppercase">Profile</div>
                <div class="fw-semibold">{{ $preview->profile?->name ?? 'Deleted profile' }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="small text-muted text-uppercase">Rows</div>
                <div class="fw-semibold">{{ $preview->row_count }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="small text-muted text-uppercase">Valid</div>
                <div class="fw-semibold">{{ $preview->valid_count }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="small text-muted text-uppercase">Invalid</div>
                <div class="fw-semibold">{{ $preview->invalid_count }}</div>
            </div>
        </div>
    </div>

    @if($preview->invalid_count > 0)
        <div class="alert alert-warning">Fix the invalid rows and run a new preview before committing.</div>
    @endif

    @if($preview->status === 'previewed' && $preview->invalid_count === 0)
        @can('data_exchange.approve_import')
            <form method="POST" action="{{ route('tech.admin.system.data-exchange.imports.commit', $preview) }}" class="mb-3" onsubmit="return confirm('Commit this import preview?')">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                    Commit import
                </button>
            </form>
        @endcan
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Row Preview -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Rows">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Row</th>
                        <th>Status</th>
                        <th>Values</th>
                        <th>Errors</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach((array) $preview->rows as $row)
                        <tr>
                            <td>{{ $row['row_number'] ?? '-' }}</td>
                            <td>
                                <span class="badge {{ ($row['valid'] ?? false) ? 'text-bg-success' : 'text-bg-warning' }}">
                                    {{ ($row['valid'] ?? false) ? 'Valid' : 'Invalid' }}
                                </span>
                            </td>
                            <td><pre class="small mb-0 text-wrap">{{ json_encode($row['values'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td>
                            <td class="small text-danger">{{ implode(', ', (array) ($row['errors'] ?? [])) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card.default>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="data-exchange" />
@endsection

@section('rightbar')
    <x-card.default title="Preview Metadata">
        <div class="small text-muted mb-2">{{ $preview->original_filename }} · {{ strtoupper((string) $preview->format) }}</div>
        <pre class="small mb-0 text-wrap">{{ json_encode($preview->summary ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </x-card.default>
@endsection
