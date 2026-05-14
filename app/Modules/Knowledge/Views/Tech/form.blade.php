@extends('layouts.default_tech')

{{--
    Knowledge Article Form Page

    This page wraps the module-local Livewire article form. It is used for both
    create and edit routes; the Article instance determines the mode.
--}}

@section('title', $article->exists ? 'Edit: ' . $article->title : 'Create Knowledge Article')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h1 class="h4 mb-0">{{ $article->exists ? 'Edit Article' : 'Create Knowledge Article' }}</h1>
        <a href="{{ $article->exists ? route('tech.knowledge.show', $article) : route('tech.knowledge.index') }}" class="btn btn-sm btn-outline-secondary">
            {{ $article->exists ? 'Cancel' : 'Back to List' }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <livewire:knowledge.article-form :article="$article" />
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

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-light border-0">
            <h5 class="card-title mb-0 small fw-bold text-uppercase text-muted">Markdown Tips</h5>
        </div>
        <div class="card-body small">
            <ul class="list-unstyled mb-0">
                <li class="mb-1"><code># Heading 1</code></li>
                <li class="mb-1"><code>## Heading 2</code></li>
                <li class="mb-1"><code>**Bold Text**</code></li>
                <li class="mb-1"><code>*Italic Text*</code></li>
                <li class="mb-1"><code>[Link Text](URL)</code></li>
                <li class="mb-1"><code>- Bullet Point</code></li>
                <li class="mb-1"><code>1. Numbered List</code></li>
                <li class="mb-1"><code>`Inline Code`</code></li>
                <li class="mb-0"><code>```Code Block```</code></li>
            </ul>
        </div>
    </div>

    @if($article->exists)
        <div class="card shadow-sm border-0">
            <div class="card-header bg-light border-0">
                <h5 class="card-title mb-0 small fw-bold text-uppercase text-muted">Article Info</h5>
            </div>
            <div class="card-body small">
                <div class="mb-2">
                    <span class="text-muted">Created:</span><br>
                    {{ $article->created_at->format('d.m.Y H:i') }}
                </div>
                <div class="mb-2">
                    <span class="text-muted">Last Updated:</span><br>
                    {{ $article->updated_at->format('d.m.Y H:i') }}
                </div>
                <div>
                    <span class="text-muted">Views:</span><br>
                    {{ $article->view_count }}
                </div>
            </div>
        </div>
    @endif
@endsection
