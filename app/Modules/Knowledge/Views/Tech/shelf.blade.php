@extends('layouts.default_tech')

{{--
    Knowledge Shelf View

    Shows the books assigned to one shelf. This keeps the Knowledge navigation
    aligned with BookStack while still using tdPSA-owned local records.
--}}

@section('title', $shelf->name)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center w-100">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('tech.knowledge.index') }}">Knowledge</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $shelf->name }}</li>
                </ol>
            </nav>
            <h1 class="h4 mb-0">{{ $shelf->name }}</h1>
        </div>
        <div class="d-flex gap-2">
            @if($shelf->source_url)
                <a href="{{ $shelf->source_url }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">
                    <i class="bi bi-box-arrow-up-right"></i> Open in BookStack
                </a>
            @endif
            <a href="{{ route('tech.knowledge.shelves.edit', $shelf) }}" class="btn btn-outline-secondary">
                <i class="bi bi-pencil"></i> Edit Shelf
            </a>
            <a href="{{ route('tech.knowledge.books.create', $shelf) }}" class="btn btn-primary">
                <i class="bi bi-plus"></i> New Book
            </a>
        </div>
    </div>
@endsection

@section('content')
    <div class="row g-3">
        @forelse($shelf->books as $book)
            <div class="col-12 col-lg-6 col-xl-4">
                <a href="{{ route('tech.knowledge.book', $book) }}" class="card h-100 text-decoration-none text-reset">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <h2 class="h6 mb-1"><i class="bi bi-journal-text me-1 text-muted"></i>{{ $book->name }}</h2>
                            <span class="badge bg-light text-dark border">{{ $book->pages_count }} pages</span>
                        </div>
                        @if($book->description)
                            <p class="small text-muted mb-0">{{ $book->description }}</p>
                        @endif
                    </div>
                    <div class="card-footer small text-muted">
                        {{ $book->chapters_count }} chapters
                    </div>
                </a>
            </div>
        @empty
            <div class="col-12">
                <div class="alert alert-light border">This shelf has no books yet.</div>
            </div>
        @endforelse
    </div>
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />
    <x-nav.knowledge-tree />
@endsection

@section('rightbar')
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">Shelf Details</h2>
        </div>
        <div class="card-body small">
            @php
                $isLocalShelf = blank($shelf->source_system);
                $isEmptyShelf = $shelf->books->isEmpty();
            @endphp
            <div class="mb-2"><span class="text-muted">Source:</span> {{ $isLocalShelf ? 'Local tdPSA' : ucfirst(str_replace('_', ' ', $shelf->source_system)) }}</div>
            <div class="mb-2"><span class="text-muted">Books:</span> {{ $shelf->books->count() }}</div>
            @if($shelf->source_url)
                <a href="{{ $shelf->source_url }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mb-2">
                    <i class="bi bi-box-arrow-up-right"></i> Open in BookStack
                </a>
            @endif
            @if($isLocalShelf && ! $isEmptyShelf)
                <div class="text-muted small mt-2">Delete is available when the shelf has no books.</div>
            @elseif(! $isLocalShelf && ! $isEmptyShelf)
                <div class="text-muted small mt-2">Delete is available when the synced shelf has no books.</div>
            @endif
        </div>
    </div>
@endsection
