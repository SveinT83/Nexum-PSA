@extends('layouts.default_tech')

@section('title', $article->title)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h1 class="h4 mb-0">{{ $article->title }}</h1>
        <div class="btn-group">
            <a href="{{ route('tech.knowledge.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
            <a href="{{ route('tech.knowledge.edit', $article->id) }}" class="btn btn-sm btn-outline-primary">Edit</a>
            <form action="{{ route('tech.knowledge.destroy', $article->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this article?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="article-content">
                        {{-- In a real app, we would use a Markdown parser to render body_markdown here --}}
                        {!! nl2br(e($article->body_markdown)) !!}
                    </div>
                </div>
            </div>
        </div>

    </div>
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
