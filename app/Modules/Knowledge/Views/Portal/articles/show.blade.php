@extends('customerportal::layouts.portal')

@section('title', $article->title)

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Knowledge Article -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $article->title }}</h1>
            <div class="small text-muted">
                {{ $article->category?->name ?: 'Knowledge article' }}
                @if($article->visibility === 'client-wide')
                    &middot; {{ $context->client->name }}
                @endif
            </div>
        </div>
        <a href="{{ route('customer-portal.knowledge.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            Knowledge
        </a>
    </div>

    <article class="card shadow-sm">
        <div class="card-body">
            <div class="prose">
                {!! $article->body_html !!}
            </div>
        </div>
        <div class="card-footer text-muted small">
            Updated {{ $article->updated_at?->format('Y-m-d H:i') }}
        </div>
    </article>
@endsection
