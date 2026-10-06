@extends('layouts.default_tech')

@section('title', 'Knowledge revision review')

@section('content')
    <!-- Revision heading and exact identity -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <a href="{{ route('tech.knowledge.show', $revision->article) }}" class="small text-decoration-none">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Published article
            </a>
            <h1 class="h3 mb-1">{{ $revision->title }}</h1>
            <div class="text-muted">
                Revision {{ $revision->revision_number }}
                <span class="badge text-bg-secondary ms-2">{{ str_replace('_', ' ', $revision->state) }}</span>
                <span class="ms-2">Origin: {{ $revision->origin }}</span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if($canApprove && in_array($revision->state, ['draft', 'ready_for_review'], true))
                <form method="POST" action="{{ route('tech.knowledge.revisions.approve', $revision) }}">
                    @csrf
                    <button class="btn btn-success" type="submit">Approve exact revision</button>
                </form>
            @endif
            @can('knowledge.publish')
                @if(in_array($revision->state, ['approved', 'publication_failed'], true))
                    <form method="POST" action="{{ route($revision->state === 'publication_failed' ? 'tech.knowledge.revisions.retry' : 'tech.knowledge.revisions.publish', $revision) }}">
                        @csrf
                        <button class="btn btn-primary" type="submit">{{ $revision->state === 'publication_failed' ? 'Retry publication' : 'Publish approved revision' }}</button>
                    </form>
                @endif
            @endcan
            @can('knowledge.rollback')
                @if(in_array($revision->state, ['published', 'superseded'], true))
                    <form method="POST" action="{{ route('tech.knowledge.revisions.rollback', $revision) }}">
                        @csrf
                        <button class="btn btn-outline-warning" type="submit">Create rollback proposal</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>

    @if($revision->state === 'publication_failed')
        <div class="alert alert-danger">
            Publication failed but is retryable. No new revision will be created.
            @if($revision->publication_error)<div class="small mt-1">{{ $revision->publication_error }}</div>@endif
        </div>
    @elseif($revision->state === 'conflict')
        <div class="alert alert-warning">The published base changed. This proposal failed closed and must be recreated.</div>
    @endif

    <!-- Provenance and authorization scope -->
    <div class="card mb-4">
        <div class="card-header fw-semibold">Exact revision and provenance</div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Snapshot hash</dt><dd class="col-sm-9 font-monospace small">{{ $revision->snapshot_hash }}</dd>
                <dt class="col-sm-3">Base revision</dt><dd class="col-sm-9">{{ $revision->base_revision_id ? '#'.$revision->baseRevision?->revision_number : 'No published base' }}</dd>
                <dt class="col-sm-3">Author</dt><dd class="col-sm-9">{{ $revision->humanAuthor?->name ?? $revision->aiAgent?->name ?? $revision->creator?->name ?? 'System' }}</dd>
                <dt class="col-sm-3">Ticket scope</dt><dd class="col-sm-9">{{ $revision->ticket?->ticket_key ?? 'None' }}</dd>
                <dt class="col-sm-3">Publication</dt><dd class="col-sm-9">{{ $revision->publication_status ?? 'Not started' }}</dd>
            </dl>
        </div>
    </div>

    <!-- Compact diff -->
    <div class="card mb-4">
        <div class="card-header fw-semibold">Changes from published base</div>
        <div class="card-body p-0">
            <pre class="mb-0 p-3 bg-body-tertiary" style="white-space: pre-wrap;">@foreach($diff['before'] as $line)  {{ $line }}
@endforeach
@foreach($diff['removed'] as $line)<span class="text-danger">- {{ $line }}</span>
@endforeach
@foreach($diff['added'] as $line)<span class="text-success">+ {{ $line }}</span>
@endforeach
@foreach($diff['after'] as $line)  {{ $line }}
@endforeach</pre>
        </div>
    </div>

    <!-- Full rendered proposal preview -->
    <div class="card mb-4">
        <div class="card-header fw-semibold">Full preview of proposed revision</div>
        <div class="card-body">{!! $revision->body_html !!}</div>
    </div>

    <!-- Immutable audit timeline -->
    <div class="card">
        <div class="card-header fw-semibold">Audit trail</div>
        <div class="list-group list-group-flush">
            @forelse($revision->events as $event)
                <div class="list-group-item">
                    <div class="d-flex justify-content-between gap-3">
                        <strong>{{ str_replace('_', ' ', $event->event_type) }}</strong>
                        <span class="text-muted small">{{ $event->created_at?->format('Y-m-d H:i') }}</span>
                    </div>
                    <div class="small text-muted">{{ $event->actor?->name ?? 'System' }} · {{ $event->from_state ?? 'new' }} → {{ $event->to_state ?? '-' }}</div>
                    @if($event->note)<div class="mt-1">{{ $event->note }}</div>@endif
                </div>
            @empty
                <div class="list-group-item text-muted">No workflow events recorded.</div>
            @endforelse
        </div>
    </div>
@endsection
