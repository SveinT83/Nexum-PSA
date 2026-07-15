@extends('customerportal::layouts.portal')

@section('title', 'Knowledge')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Knowledge List -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Knowledge</h1>
            <div class="small text-muted">{{ $context->client->name }}{{ $context->site ? ' - '.$context->site->name : '' }}</div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="list-group list-group-flush">
            @forelse($articles as $article)
                <a href="{{ route('customer-portal.knowledge.show', $article) }}" class="list-group-item list-group-item-action">
                    <div class="d-flex align-items-start justify-content-between gap-3">
                        <div>
                            <div class="fw-semibold">{{ $article->title }}</div>
                            <div class="small text-muted">
                                {{ $article->category?->name ?: 'Article' }}
                                @if($article->visibility === 'client-wide')
                                    &middot; {{ $article->clientScope?->name ?: 'Customer article' }}
                                @else
                                    &middot; General article
                                @endif
                            </div>
                        </div>
                        <span class="small text-muted flex-shrink-0">{{ $article->updated_at?->diffForHumans() }}</span>
                    </div>
                </a>
            @empty
                <div class="list-group-item text-center text-muted py-4">No visible knowledge articles for this portal scope.</div>
            @endforelse
        </div>
    </div>

    <div class="mt-3">
        {{ $articles->links() }}
    </div>
@endsection
