@extends('layouts.default_tech')

{{--
    Knowledge Shelf Form

    Creates or edits the top-level Knowledge container used by both local
    content and BookStack synchronization.
--}}

@php
    $isEditing = $shelf->exists;
    $isEmptyShelf = $isEditing && $shelf->books()->doesntExist();
@endphp

@section('title', $isEditing ? 'Edit Shelf' : 'Create Shelf')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center w-100">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('tech.knowledge.index') }}">Knowledge</a></li>
                    @if($isEditing)
                        <li class="breadcrumb-item"><a href="{{ route('tech.knowledge.shelf', $shelf) }}">{{ $shelf->name }}</a></li>
                    @endif
                    <li class="breadcrumb-item active" aria-current="page">{{ $isEditing ? 'Edit Shelf' : 'Create Shelf' }}</li>
                </ol>
            </nav>
            <h1 class="h4 mb-0">{{ $isEditing ? 'Edit Shelf' : 'Create Shelf' }}</h1>
        </div>
        <a href="{{ $isEditing ? route('tech.knowledge.shelf', $shelf) : route('tech.knowledge.index') }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Shelf Details -->
    <!-- ------------------------------------------------- -->
    <form action="{{ $isEditing ? route('tech.knowledge.shelves.update', $shelf) : route('tech.knowledge.shelves.store') }}" method="POST" class="card">
        @csrf
        @if($isEditing)
            @method('PUT')
        @endif
        <div class="card-header">
            <h2 class="h6 mb-0">Shelf Details</h2>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $shelf->name) }}" required autofocus>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4">{{ old('description', $shelf->description) }}</textarea>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            @if($canSyncToBookStack)
                <div class="form-check form-switch mb-0">
                    <input type="hidden" name="sync_to_book_stack" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="sync_to_book_stack" name="sync_to_book_stack" value="1" {{ old('sync_to_book_stack', $isEditing ? $shelf->source_system === 'book_stack' || $shelf->sync_status === 'pending_push' : true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="sync_to_book_stack">Sync to BookStack</label>
                    <div class="form-text">Queues this shelf for the BookStack worker after it is saved.</div>
                </div>
            @endif
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ $isEditing ? route('tech.knowledge.shelf', $shelf) : route('tech.knowledge.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi {{ $isEditing ? 'bi-save' : 'bi-plus' }}"></i> {{ $isEditing ? 'Save Shelf' : 'Create Shelf' }}
            </button>
        </div>
    </form>

    @if($isEmptyShelf)
        <!-- ------------------------------------------------- -->
        <!-- Delete Shelf Modal -->
        <!-- ------------------------------------------------- -->
        <div class="card border-danger mt-3">
            <div class="card-header bg-danger-subtle text-danger">
                <h2 class="h6 mb-0">Delete Shelf</h2>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    This shelf has no books. Deleting it will remove the shelf from NexumPSA{{ $shelf->source_system === 'book_stack' ? ' and BookStack' : '' }}.
                </p>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteShelfModal">
                    <i class="bi bi-trash"></i> Delete Shelf
                </button>
            </div>
        </div>

        <div class="modal fade" id="deleteShelfModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form action="{{ route('tech.knowledge.shelves.destroy', $shelf) }}" method="POST">
                    @csrf
                    @method('DELETE')
                    <div class="modal-content text-start">
                        <div class="modal-header">
                            <h5 class="modal-title text-danger">Confirm Deletion</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-dark">
                            Are you sure you want to delete <strong>{{ $shelf->name }}</strong>?
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
            Delete is available when the shelf has no books.
        </div>
    @endif
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />
    <x-nav.knowledge-tree />
@endsection
