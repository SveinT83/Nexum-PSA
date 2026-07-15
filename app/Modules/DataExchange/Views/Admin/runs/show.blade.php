@extends('layouts.default_tech')

@section('title', 'Data Exchange Run #' . $run->id)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">Data Exchange Run #{{ $run->id }}</h1>
        <x-buttons.back :url="route('tech.admin.system.data-exchange.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Run Summary -->
    <!-- ------------------------------------------------- -->
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="small text-muted text-uppercase">Profile</div>
                <div class="fw-semibold">{{ $run->profile?->name ?? 'Deleted profile' }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="small text-muted text-uppercase">Status</div>
                <div class="fw-semibold">{{ ucfirst($run->status) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="small text-muted text-uppercase">Trigger</div>
                <div class="fw-semibold">{{ ucfirst(str_replace('_', ' ', $run->trigger_type)) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="small text-muted text-uppercase">Rows</div>
                <div class="fw-semibold">{{ data_get($run->summary, 'rows', '-') }}</div>
            </div>
        </div>
    </div>

    @if($run->error_message)
        <div class="alert alert-danger">{{ $run->error_message }}</div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Generated Files -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Generated Files">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Filename</th>
                        <th>Format</th>
                        <th>Size</th>
                        <th>Retention</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($run->files as $file)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $file->filename }}</div>
                                <div class="text-muted small">{{ $file->checksum }}</div>
                            </td>
                            <td>{{ strtoupper($file->format) }}</td>
                            <td>{{ number_format($file->size_bytes / 1024, 1) }} KB</td>
                            <td class="small text-muted">{{ $file->retention_until?->format('Y-m-d') ?? '-' }}</td>
                            <td class="text-end">
                                @can('data_exchange.download')
                                    <a href="{{ route('tech.admin.system.data-exchange.files.download', $file) }}" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-download" aria-hidden="true"></i>
                                        Download
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted small">No files generated for this run.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card.default>

    <!-- ------------------------------------------------- -->
    <!-- Audit Events -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Audit Events" class="mt-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Event</th>
                        <th>Outcome</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($auditEvents as $event)
                        <tr>
                            <td>{{ str_replace('_', ' ', $event->event_type) }}</td>
                            <td><span class="badge text-bg-light border">{{ $event->outcome }}</span></td>
                            <td class="small text-muted">{{ $event->occurred_at?->format('Y-m-d H:i:s') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-muted small">No audit events found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card.default>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="data-exchange" />
@endsection

@section('rightbar')
    <x-card.default title="Run Metadata">
        <pre class="small mb-0 text-wrap">{{ json_encode($run->summary ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </x-card.default>
@endsection
