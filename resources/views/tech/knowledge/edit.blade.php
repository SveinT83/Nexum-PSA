@extends('layouts.default_tech')

@section('title', 'Edit: ' . $article->title)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h1 class="h4 mb-0">Edit: {{ $article->title }}</h1>
        <a href="{{ route('tech.knowledge.show', $article->id) }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form action="{{ route('tech.knowledge.update', $article->id) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="title" class="form-label">Title</label>
                            <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', $article->title) }}" required>
                            @error('title')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="body_markdown" class="form-label">Content (Markdown)</label>
                            <textarea class="form-control @error('body_markdown') is-invalid @enderror" id="body_markdown" name="body_markdown" rows="15" required>{{ old('body_markdown', $article->body_markdown) }}</textarea>
                            @error('body_markdown')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="category_id" class="form-label">Category</label>
                                <select class="form-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id">
                                    <option value="">Select Category</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}" {{ old('category_id', $article->category_id) == $category->id ? 'selected' : '' }}>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('category_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="visibility" class="form-label">Visibility</label>
                                <select class="form-select @error('visibility') is-invalid @enderror" id="visibility" name="visibility" required>
                                    <option value="internal" {{ old('visibility', $article->visibility) == 'internal' ? 'selected' : '' }}>Internal</option>
                                    <option value="client-wide" {{ old('visibility', $article->visibility) == 'client-wide' ? 'selected' : '' }}>Client-wide</option>
                                    <option value="public" {{ old('visibility', $article->visibility) == 'public' ? 'selected' : '' }}>Public</option>
                                </select>
                                @error('visibility')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select @error('status') is-invalid @enderror" id="status" name="status" required>
                                    <option value="draft" {{ old('status', $article->status) == 'draft' ? 'selected' : '' }}>Draft</option>
                                    <option value="published" {{ old('status', $article->status) == 'published' ? 'selected' : '' }}>Published</option>
                                    <option value="archived" {{ old('status', $article->status) == 'archived' ? 'selected' : '' }}>Archived</option>
                                    <option value="needs_review" {{ old('status', $article->status) == 'needs_review' ? 'selected' : '' }}>Needs Review</option>
                                </select>
                                @error('status')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="client_scope_id" class="form-label">Client Scope (if applicable)</label>
                                <select class="form-select @error('client_scope_id') is-invalid @enderror" id="client_scope_id" name="client_scope_id">
                                    <option value="">No Client Scope</option>
                                    @foreach($clients as $client)
                                        <option value="{{ $client->id }}" {{ old('client_scope_id', $article->client_scope_id) == $client->id ? 'selected' : '' }}>
                                            {{ $client->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('client_scope_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="next_review_at" class="form-label">Next Review Date</label>
                            <input type="date" class="form-control @error('next_review_at') is-invalid @enderror" id="next_review_at" name="next_review_at" value="{{ old('next_review_at', $article->next_review_at ? $article->next_review_at->format('Y-m-d') : '') }}">
                            @error('next_review_at')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="submit" class="btn btn-primary">Update Article</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">Markdown Tips</h5>
                </div>
                <div class="card-body small">
                    <ul class="mb-0">
                        <li># Heading 1</li>
                        <li>## Heading 2</li>
                        <li>**Bold Text**</li>
                        <li>*Italic Text*</li>
                        <li>[Link Text](URL)</li>
                        <li>- Bullet Point</li>
                        <li>1. Numbered List</li>
                        <li>`Inline Code`</li>
                        <li>```Code Block```</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
