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
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('tech.knowledge.index') }}">Knowledge</a></li>
                    @if($article->knowledgeShelf)
                        <li class="breadcrumb-item"><a href="{{ route('tech.knowledge.shelf', $article->knowledgeShelf) }}">{{ $article->knowledgeShelf->name }}</a></li>
                    @endif
                    @if($article->knowledgeBook)
                        <li class="breadcrumb-item"><a href="{{ route('tech.knowledge.book', $article->knowledgeBook) }}">{{ $article->knowledgeBook->name }}</a></li>
                    @endif
                    @if($article->knowledgeChapter)
                        <li class="breadcrumb-item">{{ $article->knowledgeChapter->name }}</li>
                    @endif
                    <li class="breadcrumb-item active" aria-current="page">{{ $article->title }}</li>
                </ol>
            </nav>
            <h1 class="h4 mb-0">{{ $article->title }}</h1>
        </div>
        <div class="btn-group">
            <a href="{{ route('tech.knowledge.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
            @if($canEditArticle)
                <a href="{{ route('tech.knowledge.edit', $article) }}" class="btn btn-sm btn-outline-primary">Edit</a>
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
                <p class="mb-1 text-muted">Source: Local tdPSA</p>
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
