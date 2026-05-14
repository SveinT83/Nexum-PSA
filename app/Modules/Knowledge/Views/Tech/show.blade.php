@extends('layouts.default_tech')

{{--
    Knowledge Article Show Page

    Displays a single article and its metadata. The controller records a view
    before rendering this page and eager-loads category, owner, client scope,
    creator/updater, and tags for the side panel.
--}}

@section('title', $article->title)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h1 class="h4 mb-0">{{ $article->title }}</h1>
        <div class="btn-group">
            <a href="{{ route('tech.knowledge.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
            <a href="{{ route('tech.knowledge.edit', $article) }}" class="btn btn-sm btn-outline-primary">Edit</a>
            <x-buttons.delete
                :url="route('tech.knowledge.destroy', $article)"
                :name="$article->title"
                class="btn btn-sm btn-outline-danger"
            />
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
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
@endsection

@section('rightbar')
    <livewire:system.tag-manager :model="$article" module="knowledge" />

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
            @if($article->clientScope)
                <p class="mb-1"><strong>Client:</strong> {{ $article->clientScope->name }}</p>
            @endif
            <hr>
            <p class="mb-1 text-muted">Created: {{ $article->created_at->format('d.m.Y H:i') }}</p>
            <p class="mb-1 text-muted">Updated: {{ $article->updated_at->format('d.m.Y H:i') }}</p>
            @if($article->next_review_at)
                <p class="mb-1 text-muted">Next Review: {{ $article->next_review_at->format('d.m.Y') }}</p>
            @endif
        </div>
    </div>
@endsection
