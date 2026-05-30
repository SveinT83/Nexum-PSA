@extends('layouts.default_tech')

{{--
    Knowledge Book Form

    Creates or edits a BookStack-style book inside the selected shelf so local
    Knowledge keeps the same hierarchy whether BookStack sync is enabled or not.
--}}

@php
    $isEditing = $book->exists;
    $isEmptyBook = $isEditing && $book->chapters()->doesntExist() && \App\Models\Knowledge\Article::query()
        ->where('knowledge_book_id', $book->id)
        ->doesntExist();
@endphp

@section('title', $isEditing ? 'Edit Book' : 'Create Book')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center w-100">
        <div>
            <h1 class="h4 mb-0">{{ $isEditing ? 'Edit Book' : 'Create Book' }}</h1>
        </div>
        <a href="{{ $isEditing ? route('tech.knowledge.book', $book) : route('tech.knowledge.shelf', $shelf) }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Book Details -->
    <!-- ------------------------------------------------- -->
    <form action="{{ $isEditing ? route('tech.knowledge.books.update', $book) : route('tech.knowledge.books.store', $shelf) }}" method="POST" class="card">
        @csrf
        @if($isEditing)
            @method('PUT')
        @endif
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Book Details</h2>
            <span class="small text-muted">{{ $shelf?->name }}</span>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label for="shelf_id" class="form-label">Shelf</label>
                <select class="form-select @error('shelf_id') is-invalid @enderror" id="shelf_id" name="shelf_id" required>
                    @foreach($shelves as $availableShelf)
                        <option value="{{ $availableShelf->id }}" {{ (int) old('shelf_id', $book->shelf_id ?: $shelf?->id) === (int) $availableShelf->id ? 'selected' : '' }}>
                            {{ $availableShelf->name }}
                        </option>
                    @endforeach
                </select>
                @error('shelf_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $book->name) }}" required autofocus>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4">{{ old('description', $book->description) }}</textarea>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="priority" class="form-label">Sort Priority</label>
                <input type="number" class="form-control @error('priority') is-invalid @enderror" id="priority" name="priority" value="{{ old('priority', $book->priority ?? 0) }}" min="0">
                @error('priority')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            @if($canSyncToBookStack)
                <div class="form-check form-switch mb-0">
                    <input type="hidden" name="sync_to_book_stack" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="sync_to_book_stack" name="sync_to_book_stack" value="1" {{ old('sync_to_book_stack', $isEditing ? $book->source_system === 'book_stack' || $book->sync_status === 'pending_push' : true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="sync_to_book_stack">Sync to BookStack</label>
                </div>
            @endif
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ $isEditing ? route('tech.knowledge.book', $book) : route('tech.knowledge.shelf', $shelf) }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi {{ $isEditing ? 'bi-save' : 'bi-plus' }}"></i> {{ $isEditing ? 'Save Book' : 'Create Book' }}
            </button>
        </div>
    </form>

    @if($isEmptyBook)
        <!-- ------------------------------------------------- -->
        <!-- Delete Book Modal -->
        <!-- ------------------------------------------------- -->
        <div class="card border-danger mt-3">
            <div class="card-header bg-danger-subtle text-danger">
                <h2 class="h6 mb-0">Delete Book</h2>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    This book has no chapters or pages. Deleting it will remove the book from NexumPSA{{ $book->source_system === 'book_stack' ? ' and BookStack' : '' }}.
                </p>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteBookModal">
                    <i class="bi bi-trash"></i> Delete Book
                </button>
            </div>
        </div>

        <div class="modal fade" id="deleteBookModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form action="{{ route('tech.knowledge.books.destroy', $book) }}" method="POST">
                    @csrf
                    @method('DELETE')
                    <div class="modal-content text-start">
                        <div class="modal-header">
                            <h5 class="modal-title text-danger">Confirm Deletion</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-dark">
                            Are you sure you want to delete <strong>{{ $book->name }}</strong>?
                            <br>
                            <span class="small text-muted">This action cannot be undone.</span>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-sm btn-danger">Permanently Delete</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @elseif($isEditing)
        <div class="alert alert-light border small mt-3 mb-0">
            Delete is available when the book has no chapters or pages.
        </div>
    @endif
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />
    <x-nav.knowledge-tree />
@endsection
