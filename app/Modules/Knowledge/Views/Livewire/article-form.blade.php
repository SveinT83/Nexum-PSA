<div>
    {{--
        Livewire article editor.

        The component class owns persistence and validation. This view only
        binds form controls to public component properties.
    --}}
    <form wire:submit.prevent="save">
        <div class="mb-3">
            <label for="title" class="form-label fw-bold text-muted small text-uppercase">Title</label>
            <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" wire:model="title" placeholder="e.g. How to set up a new VPN connection" required>
            @error('title')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="body_markdown" class="form-label fw-bold text-muted small text-uppercase">Content (Markdown)</label>
            <textarea class="form-control @error('body_markdown') is-invalid @enderror" id="body_markdown" wire:model="body_markdown" rows="15" placeholder="Write your article content here using Markdown..." required></textarea>
            @error('body_markdown')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="category_id" class="form-label fw-bold text-muted small text-uppercase">Category</label>
                <select class="form-select @error('category_id') is-invalid @enderror" id="category_id" wire:model="category_id">
                    <option value="">Select Category</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('category_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-md-6 mb-3">
                <label for="visibility" class="form-label fw-bold text-muted small text-uppercase">Visibility</label>
                <select class="form-select @error('visibility') is-invalid @enderror" id="visibility" wire:model.live="visibility" required>
                    <option value="internal">Internal</option>
                    <option value="client-wide">Client-wide</option>
                    <option value="public">Public</option>
                </select>
                @error('visibility')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Knowledge Library Placement -->
        <!-- ------------------------------------------------- -->
        <div class="border rounded p-3 mb-3">
            <div class="small fw-bold text-uppercase text-muted mb-3">Library Placement</div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="knowledge_shelf_id" class="form-label fw-bold text-muted small text-uppercase">Shelf</label>
                    <select class="form-select @error('knowledge_shelf_id') is-invalid @enderror" id="knowledge_shelf_id" wire:model="knowledge_shelf_id">
                        <option value="">No Shelf</option>
                        @foreach($shelves as $shelf)
                            <option value="{{ $shelf->id }}">{{ $shelf->name }}</option>
                        @endforeach
                    </select>
                    @error('knowledge_shelf_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-md-4 mb-3">
                    <label for="knowledge_book_id" class="form-label fw-bold text-muted small text-uppercase">Book</label>
                    <select class="form-select @error('knowledge_book_id') is-invalid @enderror" id="knowledge_book_id" wire:model="knowledge_book_id">
                        <option value="">No Book</option>
                        @foreach($books as $book)
                            <option value="{{ $book->id }}">{{ $book->name }}</option>
                        @endforeach
                    </select>
                    @error('knowledge_book_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-md-4 mb-3">
                    <label for="knowledge_chapter_id" class="form-label fw-bold text-muted small text-uppercase">Chapter</label>
                    <select class="form-select @error('knowledge_chapter_id') is-invalid @enderror" id="knowledge_chapter_id" wire:model="knowledge_chapter_id">
                        <option value="">No Chapter</option>
                        @foreach($chapters as $chapter)
                            <option value="{{ $chapter->id }}">{{ $chapter->book->name ?? 'Book' }} / {{ $chapter->name }}</option>
                        @endforeach
                    </select>
                    @error('knowledge_chapter_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="mb-0">
                <label for="priority" class="form-label fw-bold text-muted small text-uppercase">Sort Priority</label>
                <input type="number" min="0" class="form-control @error('priority') is-invalid @enderror" id="priority" wire:model="priority">
                @error('priority')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="status" class="form-label fw-bold text-muted small text-uppercase">Status</label>
                <select class="form-select @error('status') is-invalid @enderror" id="status" wire:model="status" required>
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                    <option value="archived">Archived</option>
                    <option value="needs_review">Needs Review</option>
                </select>
                @error('status')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            @if($visibility === 'client-wide')
                <div class="col-md-6 mb-3">
                    <label for="client_scope_id" class="form-label fw-bold text-muted small text-uppercase">Client Scope</label>
                    <select class="form-select @error('client_scope_id') is-invalid @enderror" id="client_scope_id" wire:model="client_scope_id">
                        <option value="">Select Client</option>
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                    @error('client_scope_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            @endif
        </div>

        <div class="mb-3">
            <label for="next_review_at" class="form-label fw-bold text-muted small text-uppercase">Next Review Date</label>
            <input type="date" class="form-control @error('next_review_at') is-invalid @enderror" id="next_review_at" wire:model="next_review_at">
            @error('next_review_at')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
            <button type="submit" class="btn btn-primary px-4">
                <i class="fas fa-save me-1"></i> {{ $article->exists ? 'Update Article' : 'Create Article' }}
            </button>
        </div>
    </form>
</div>
