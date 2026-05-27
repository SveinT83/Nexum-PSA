@extends('layouts.default_tech')

{{--
    Knowledge Library Index

    Presents Knowledge as a BookStack-style library of shelves and books.
--}}

@section('title', 'Knowledge')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center w-100">
        <h1>Knowledge</h1>
        <a href="{{ route('tech.knowledge.shelves.create') }}" class="btn btn-primary">
            <i class="bi bi-plus"></i> New Shelf
        </a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Knowledge Shelves -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3 mb-4" id="knowledgeShelfAccordion">
        @forelse($shelves as $shelf)
            @php
                $shelfBooksCollapseId = 'knowledgeShelfBooks' . $shelf->id;
                $shelfBooksHeadingId = 'knowledgeShelfBooksHeading' . $shelf->id;
            @endphp
            <div class="col-12 col-xl-6">
                <div class="card h-100 knowledge-shelf-card">
                    <div class="card-header d-flex justify-content-between align-items-start gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('tech.knowledge.shelf', $shelf) }}" class="d-block fw-semibold text-decoration-none text-truncate">
                                <i class="bi bi-bookshelf me-1"></i>{{ $shelf->name }}
                            </a>
                            <div class="small text-muted">
                                {{ $shelf->source_system ? ucfirst(str_replace('_', ' ', $shelf->source_system)) . ' synced shelf' : 'Local shelf' }}
                            </div>
                        </div>
                        <span class="badge bg-light text-dark border">{{ $shelf->books_count }} books</span>
                    </div>
                    <div class="card-body d-flex flex-column">
                        @if($shelf->description)
                            <p class="text-muted small mb-3 knowledge-shelf-description">{{ $shelf->description }}</p>
                        @else
                            <p class="text-muted small mb-3 knowledge-shelf-description">No shelf description.</p>
                        @endif

                        <div class="d-flex align-items-center justify-content-between small mt-auto">
                            <span class="text-muted">Open the shelf for the full BookStack structure.</span>
                            <a href="{{ route('tech.knowledge.shelf', $shelf) }}" class="text-decoration-none">View shelf</a>
                        </div>
                    </div>
                    <div class="card-footer bg-white">
                        <h2 class="accordion-header" id="{{ $shelfBooksHeadingId }}">
                            <button
                                class="accordion-button collapsed px-0 py-1 bg-white shadow-none small"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#{{ $shelfBooksCollapseId }}"
                                aria-expanded="false"
                                aria-controls="{{ $shelfBooksCollapseId }}">
                                <span class="d-flex align-items-center gap-2 w-100 pe-2">
                                    <i class="bi bi-journal-text text-muted" aria-hidden="true"></i>
                                    <span class="fw-semibold">Books on this shelf</span>
                                    <span class="badge bg-light text-dark border ms-auto">{{ $shelf->books_count }}</span>
                                </span>
                            </button>
                        </h2>
                        <div
                            id="{{ $shelfBooksCollapseId }}"
                            class="accordion-collapse collapse"
                            aria-labelledby="{{ $shelfBooksHeadingId }}"
                            data-bs-parent="#knowledgeShelfAccordion">
                            <div class="list-group list-group-flush pt-2">
                                @forelse($shelf->books as $book)
                                    <a href="{{ route('tech.knowledge.book', $book) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center px-0">
                                        <span>
                                            <i class="bi bi-journal-text me-1 text-muted"></i>
                                            {{ $book->name }}
                                        </span>
                                        <span class="small text-muted">
                                            {{ $book->chapters_count }} chapters / {{ $book->pages_count }} pages
                                        </span>
                                    </a>
                                @empty
                                    <div class="text-muted small">No books on this shelf yet.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="alert alert-light border mb-0">
                    No shelves yet. Run BookStack sync or create books to start organizing Knowledge.
                </div>
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
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <h2 class="h6 mb-0">Library Status</h2>
            @if($bookStackCanSync)
                <form action="{{ route('tech.admin.system.integrations.book_stack.sync') }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-repeat"></i> Sync Now
                    </button>
                </form>
            @endif
        </div>
        <div class="card-body small">
            <div class="row g-2 text-center">
                <div class="col-6">
                    <div class="text-muted text-uppercase fw-semibold">Shelves</div>
                    <div class="fs-5 fw-semibold">{{ $shelves->count() }}</div>
                </div>
                <div class="col-6">
                    <div class="text-muted text-uppercase fw-semibold">Books</div>
                    <div class="fs-5 fw-semibold">{{ $shelves->sum(fn ($shelf) => $shelf->books->count()) }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
