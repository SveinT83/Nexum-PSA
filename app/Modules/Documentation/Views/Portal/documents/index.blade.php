@extends('customerportal::layouts.portal')

@section('title', 'Documents')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Document List -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Documents</h1>
            <div class="small text-muted">{{ $context->client->name }}{{ $context->site ? ' - '.$context->site->name : '' }}</div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Document</th>
                        <th>Category</th>
                        <th>Scope</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($documentations as $documentation)
                        <tr>
                            <td>
                                <a href="{{ route('customer-portal.documents.show', $documentation) }}" class="fw-semibold text-decoration-none">{{ $documentation->title }}</a>
                            </td>
                            <td>{{ $documentation->category?->name ?: '-' }}</td>
                            <td>{{ $documentation->site?->name ?: 'All sites' }}</td>
                            <td class="text-muted small">{{ $documentation->updated_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No visible documents for this portal scope.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $documentations->links() }}
    </div>
@endsection
