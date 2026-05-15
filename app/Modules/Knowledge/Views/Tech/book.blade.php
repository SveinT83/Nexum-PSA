@extends('layouts.default_tech')

{{--
    Knowledge Book View

    Lists direct pages and chapter pages in BookStack-like reading order.
--}}

@section('title', $book->name)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center w-100">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('tech.knowledge.index') }}">Knowledge</a></li>
                    @if($book->shelf)
                        <li class="breadcrumb-item"><a href="{{ route('tech.knowledge.shelf', $book->shelf) }}">{{ $book->shelf->name }}</a></li>
                    @endif
                    <li class="breadcrumb-item active" aria-current="page">{{ $book->name }}</li>
                </ol>
            </nav>
            <h1 class="h4 mb-0">{{ $book->name }}</h1>
        </div>
        <div class="d-flex gap-2">
            @if($book->source_url)
                <a href="{{ $book->source_url }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">
                    <i class="bi bi-box-arrow-up-right"></i> Open in BookStack
                </a>
            @endif
            <a href="{{ route('tech.knowledge.chapters.create', $book) }}" class="btn btn-outline-primary">
                <i class="bi bi-collection"></i> New Chapter
            </a>
            <a href="{{ route('tech.knowledge.books.edit', $book) }}" class="btn btn-outline-secondary">
                <i class="bi bi-pencil"></i> Edit Book
            </a>
            <a href="{{ route('tech.knowledge.books.pages.create', $book) }}" class="btn btn-primary">
                <i class="bi bi-plus"></i> New Page
            </a>
        </div>
    </div>
@endsection

@section('content')
    @if($book->description)
        <div class="alert alert-light border small">{{ $book->description }}</div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Direct Book Pages -->
    <!-- ------------------------------------------------- -->
    @if($book->pages->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Pages</h2>
            </div>
            <div class="list-group list-group-flush">
                @foreach($book->pages as $page)
                    <a href="{{ route('tech.knowledge.show', $page) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-file-earmark-text me-1 text-muted"></i>{{ $page->title }}</span>
                        <span class="small text-muted">{{ $page->updated_at->diffForHumans() }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Chapter Pages -->
    <!-- ------------------------------------------------- -->
    @forelse($book->chapters as $chapter)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <h2 class="h6 mb-0"><i class="bi bi-collection me-1 text-muted"></i>{{ $chapter->name }}</h2>
                <a href="{{ route('tech.knowledge.chapters.edit', $chapter) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-pencil"></i> Edit
                </a>
            </div>
            <div class="list-group list-group-flush">
                @forelse($chapter->pages as $page)
                    <a href="{{ route('tech.knowledge.show', $page) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-file-earmark-text me-1 text-muted"></i>{{ $page->title }}</span>
                        <span class="small text-muted">{{ $page->updated_at->diffForHumans() }}</span>
                    </a>
                @empty
                    <div class="list-group-item text-muted small">No pages in this chapter.</div>
                @endforelse
            </div>
        </div>
    @empty
        @if($book->pages->isEmpty())
            <div class="alert alert-light border">This book has no pages yet.</div>
        @endif
    @endforelse
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />
    <x-nav.knowledge-tree />
@endsection

@section('rightbar')
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">Book Details</h2>
        </div>
        <div class="card-body small">
            @php
                $isLocalBook = blank($book->source_system);
                $isEmptyBook = $book->chapters->isEmpty() && $book->pages->isEmpty();
            @endphp
            @if($book->shelf)
                <div class="mb-2"><span class="text-muted">Shelf:</span> {{ $book->shelf->name }}</div>
            @endif
            <div class="mb-2"><span class="text-muted">Source:</span> {{ $isLocalBook ? 'Local tdPSA' : ucfirst(str_replace('_', ' ', $book->source_system)) }}</div>
            <div class="mb-2"><span class="text-muted">Chapters:</span> {{ $book->chapters->count() }}</div>
            <div class="mb-3"><span class="text-muted">Direct pages:</span> {{ $book->pages->count() }}</div>
            @if($book->source_url)
                <a href="{{ $book->source_url }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mb-2">
                    <i class="bi bi-box-arrow-up-right"></i> Open in BookStack
                </a>
            @endif
            @if($isLocalBook && ! $isEmptyBook)
                <div class="text-muted small mt-2">Delete is available when the book has no chapters or pages.</div>
            @elseif(! $isLocalBook && ! $isEmptyBook)
                <div class="text-muted small mt-2">Delete is available when the synced book has no chapters or pages.</div>
            @endif
        </div>
    </div>
@endsection
