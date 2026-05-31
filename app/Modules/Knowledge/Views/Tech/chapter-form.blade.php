@extends('layouts.default_tech')

{{--
    Knowledge Chapter Form

    Creates or edits a BookStack-style chapter inside the selected book.
--}}

@php
    $isEditing = $chapter->exists;
    $isEmptyChapter = $isEditing && $chapter->pages->isEmpty();
@endphp

@section('title', $isEditing ? 'Edit Chapter' : 'Create Chapter')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center w-100">
        <div>
            <h1 class="h4 mb-0">{{ $isEditing ? 'Edit Chapter' : 'Create Chapter' }}</h1>
        </div>
        <a href="{{ route('tech.knowledge.book', $book) }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Chapter Details -->
    <!-- ------------------------------------------------- -->
    <form action="{{ $isEditing ? route('tech.knowledge.chapters.update', $chapter) : route('tech.knowledge.chapters.store', $book) }}" method="POST" class="card">
        @csrf
        @if($isEditing)
            @method('PUT')
        @endif
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Chapter Details</h2>
            <span class="small text-muted">{{ $book->name }}</span>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $chapter->name) }}" required autofocus>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="4">{{ old('description', $chapter->description) }}</textarea>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="priority" class="form-label">Sort Priority</label>
                <input type="number" class="form-control @error('priority') is-invalid @enderror" id="priority" name="priority" value="{{ old('priority', $chapter->priority ?? 0) }}" min="0">
                @error('priority')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            @if($canSyncToBookStack && (! $isEditing || blank($chapter->source_system)))
                <div class="form-check form-switch mb-0">
                    <input type="hidden" name="sync_to_book_stack" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="sync_to_book_stack" name="sync_to_book_stack" value="1" {{ old('sync_to_book_stack', $isEditing ? $chapter->sync_status === 'pending_push' : true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="sync_to_book_stack">Sync to BookStack</label>
                    <div class="form-text">Queues this chapter for the BookStack worker after it is saved.</div>
                </div>
            @elseif($isEditing && $chapter->source_system)
                <div class="text-muted small">This chapter is owned by BookStack. Saving changes queues an update back to BookStack.</div>
            @endif
        </div>
        <div class="card-footer d-flex justify-content-between gap-2">
            <div>
                @if($isEmptyChapter)
                    <!-- The delete form lives outside the save form to avoid nested-form browser bugs. -->
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteChapterModal">
                        Delete
                    </button>
                @elseif($isEditing && blank($chapter->source_system))
                    <span class="text-muted small">Delete is available when the chapter has no pages.</span>
                @endif
            </div>
            <div class="d-flex justify-content-end gap-2">
                <a href="{{ route('tech.knowledge.book', $book) }}" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi {{ $isEditing ? 'bi-save' : 'bi-plus' }}"></i> {{ $isEditing ? 'Save Chapter' : 'Create Chapter' }}
                </button>
            </div>
        </div>
    </form>

    @if($isEmptyChapter)
        <!-- ------------------------------------------------- -->
        <!-- Delete Chapter Modal -->
        <!-- ------------------------------------------------- -->
        <div class="modal fade" id="deleteChapterModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form action="{{ route('tech.knowledge.chapters.destroy', $chapter) }}" method="POST">
                    @csrf
                    @method('DELETE')
                    <div class="modal-content text-start">
                        <div class="modal-header">
                            <h5 class="modal-title text-danger">Confirm Deletion</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-dark">
                            Are you sure you want to delete <strong>{{ $chapter->name }}</strong>?
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
    @endif
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />
    <x-nav.knowledge-tree />
@endsection
