@extends('layouts.default_tech')

{{--
    Knowledge Article Show Page

    Displays a single article and its metadata. The controller records a view
    before rendering this page and eager-loads category, owner, client scope,
    creator/updater, and tags for the side panel.
--}}

@section('title', $article->title)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h4 mb-0">{{ $article->title }}</h1>
        </div>
        <div class="btn-group">
            <x-buttons.back url="{{ route('tech.knowledge.index') }}" class="mb-0">Back</x-buttons.back>
            @if($canEditArticle)
                <x-buttons.editlink url="{{ route('tech.knowledge.edit', $article) }}" class="mb-0">Edit</x-buttons.editlink>
            @endif
            @if(blank($article->source_system))
                <x-buttons.delete
                    :url="route('tech.knowledge.destroy', $article)"
                    :name="$article->title"
                    class="btn btn-sm btn-outline-danger"
                />
            @elseif($article->source_url)
                <a href="{{ $article->source_url }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-box-arrow-up-right"></i> Open in BookStack
                </a>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            @if($article->bookStackSyncState && in_array($article->bookStackSyncState->status, ['conflict', 'pending_inbound', 'remote_deleted', 'remote_missing_identifier'], true))
                <div class="card border-warning mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center gap-3">
                        <h2 class="h6 mb-0">BookStack synchronization review</h2>
                        <span class="badge text-bg-warning">
                            {{ ucfirst(str_replace('_', ' ', $article->bookStackSyncState->status)) }}
                        </span>
                    </div>
                    <div class="card-body">
                        <p class="mb-3">
                            Nexum kept the current article unchanged because the remote state could not be safely fast-forwarded.
                        </p>

                        @if($article->bookStackSyncState->candidateRevision)
                            @php($candidate = $article->bookStackSyncState->candidateRevision)
                            <div class="row g-3 mb-3">
                                <div class="col-lg-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="small text-muted mb-1">Current Nexum article</div>
                                        <div class="fw-semibold">{{ $article->title }}</div>
                                        <div class="small text-muted mt-2">Updated {{ $article->updated_at->format('d.m.Y H:i') }}</div>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="small text-muted mb-1">BookStack candidate revision {{ $candidate->revision_number }}</div>
                                        <div class="fw-semibold">{{ $candidate->title }}</div>
                                        <div class="small text-muted mt-2">Imported {{ $candidate->created_at->format('d.m.Y H:i') }}</div>
                                    </div>
                                </div>
                            </div>

                            <details class="mb-3">
                                <summary class="fw-semibold">Compare Markdown</summary>
                                <div class="row g-3 mt-1">
                                    <div class="col-lg-6"><pre class="border rounded p-3 small text-wrap mb-0">{{ $article->body_markdown }}</pre></div>
                                    <div class="col-lg-6"><pre class="border rounded p-3 small text-wrap mb-0">{{ $candidate->body_markdown }}</pre></div>
                                </div>
                            </details>
                        @elseif($article->bookStackSyncState->status === 'remote_deleted')
                            <div class="alert alert-warning">The linked BookStack page is no longer returned by the remote API. The Nexum article was preserved.</div>
                        @endif

                        @can('knowledge.manage_drafts')
                            <div class="d-flex flex-wrap gap-2">
                                @if($article->bookStackSyncState->candidateRevision)
                                    <form method="POST" action="{{ route('tech.knowledge.book-stack.accept-remote', $article) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-primary">Accept BookStack candidate</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('tech.knowledge.book-stack.keep-nexum', $article) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-primary">Keep Nexum revision and push</button>
                                </form>
                            </div>
                        @else
                            <div class="text-muted small">Knowledge update permission is required to resolve this review.</div>
                        @endcan
                    </div>
                </div>
            @endif

            <div class="card mb-4">
                <div class="card-body">
                    <div class="article-content">
                        {!! $article->body_html ?: nl2br(e($article->body_markdown)) !!}
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />
    <x-nav.knowledge-tree />
@endsection

@section('rightbar')
    <livewire:system.tag-manager :model="$article" module="knowledge" />

    <!-- Revision workflow and immutable history -->
    <div class="card mb-3">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Revisions</h5>
            <span class="badge text-bg-secondary">{{ $article->revisions->count() }}</span>
        </div>
        <div class="list-group list-group-flush">
            @forelse($article->revisions->take(10) as $revision)
                <a href="{{ route('tech.knowledge.revisions.show', $revision) }}" class="list-group-item list-group-item-action">
                    <div class="d-flex justify-content-between gap-2">
                        <span>Revision {{ $revision->revision_number }}</span>
                        <span class="badge text-bg-{{ $revision->state === 'published' ? 'success' : ($revision->state === 'publication_failed' || $revision->state === 'conflict' ? 'danger' : 'secondary') }}">
                            {{ str_replace('_', ' ', $revision->state) }}
                        </span>
                    </div>
                    <div class="small text-muted">{{ $revision->created_at?->format('d.m.Y H:i') }} · {{ $revision->origin }}</div>
                </a>
            @empty
                <div class="list-group-item text-muted small">No revision baseline is available yet.</div>
            @endforelse
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-light">
            <h5 class="card-title mb-0">Article Details</h5>
        </div>
        <div class="card-body small">
            <p class="mb-1"><strong>Status:</strong>
                <span class="badge bg-{{ $article->status == 'published' ? 'success' : ($article->status == 'draft' ? 'warning' : 'secondary') }}">
                            {{ ucfirst($article->status) }}
                        </span>
            </p>
            <p class="mb-1"><strong>Visibility:</strong>
                <span class="badge bg-info">{{ ucfirst($article->visibility) }}</span>
            </p>
            <p class="mb-1"><strong>Category:</strong> {{ $article->category->name ?? 'Uncategorized' }}</p>
            <p class="mb-1"><strong>Owner:</strong> {{ $article->owner->name ?? 'Unknown' }}</p>
            <p class="mb-1"><strong>Views:</strong> {{ $article->view_count }}</p>
            @if($article->bookStackSyncState)
                <p class="mb-1"><strong>BookStack sync:</strong>
                    <span class="badge text-bg-{{ $article->bookStackSyncState->status === 'synced' ? 'success' : 'warning' }}">
                        {{ ucfirst(str_replace('_', ' ', $article->bookStackSyncState->status)) }}
                    </span>
                </p>
            @endif
            @if($article->clientScope)
                <p class="mb-1"><strong>Client:</strong> {{ $article->clientScope->name }}</p>
            @endif
            @if($article->knowledgeBook)
                <p class="mb-1"><strong>Book:</strong> {{ $article->knowledgeBook->name }}</p>
            @endif
            @if($article->knowledgeChapter)
                <p class="mb-1"><strong>Chapter:</strong> {{ $article->knowledgeChapter->name }}</p>
            @endif
            <hr>
            <p class="mb-1 text-muted">Created: {{ $article->created_at->format('d.m.Y H:i') }}</p>
            <p class="mb-1 text-muted">Updated: {{ $article->updated_at->format('d.m.Y H:i') }}</p>
            @if($article->source_system)
                <p class="mb-1 text-muted">Source: {{ ucfirst(str_replace('_', ' ', $article->source_system)) }}</p>
            @else
                <p class="mb-1 text-muted">Source: Local Nexum PSA</p>
            @endif
            @if($article->next_review_at)
                <p class="mb-1 text-muted">Next Review: {{ $article->next_review_at->format('d.m.Y') }}</p>
            @endif
            @if($article->source_url)
                <a href="{{ $article->source_url }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mt-2">
                    <i class="bi bi-box-arrow-up-right"></i> Open in BookStack
                </a>
            @elseif($article->source_system)
                <div class="text-muted small mt-2">This synced page does not have a BookStack URL stored.</div>
            @endif
        </div>
    </div>
@endsection
