<form method="POST" action="{{ $action }}">
    @csrf
    @method($method)

    <!-- Assignment settings: these are the stable Ticket-owned fields the assignment engine can score. -->
    <x-card.default title="Assignment">
        @if($showUser)
            <div class="mb-3">
                <label class="form-label">Technician</label>
                <div class="form-control-plaintext">{{ $profile->user?->name }} - {{ $profile->user?->email }}</div>
            </div>
        @endif

        <input type="hidden" name="is_assignable" value="0">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="is_assignable" name="is_assignable" value="1" @checked(old('is_assignable', $profile->is_assignable))>
            <label class="form-check-label" for="is_assignable">Available for ticket assignment</label>
        </div>

        <div class="row g-3">
            <div class="col-md-12">
                <label for="max_open_tickets" class="form-label">Max open tickets</label>
                <input id="max_open_tickets" name="max_open_tickets" type="number" min="1" max="500" class="form-control" value="{{ old('max_open_tickets', $profile->max_open_tickets) }}" required>
            </div>
        </div>
    </x-card.default>

    <!-- Skills: categories are broad domains; tags are specific technologies, vendors, or customer traits. -->
    <x-card.default title="Skills">
        <div class="row g-4">
            <div class="col-md-6">
                <h2 class="h6">Ticket categories</h2>
                @foreach($categories as $category)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="category_{{ $category->id }}" name="category_ids[]" value="{{ $category->id }}" @checked(collect(old('category_ids', $profile->categories->pluck('id')->all()))->contains($category->id))>
                        <label class="form-check-label" for="category_{{ $category->id }}">{{ $category->name }}</label>
                    </div>
                @endforeach
            </div>
            <div class="col-md-6">
                <h2 class="h6">Tags</h2>
                @foreach($tags as $tag)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="tag_{{ $tag->id }}" name="tag_ids[]" value="{{ $tag->id }}" @checked(collect(old('tag_ids', $profile->tags->pluck('id')->all()))->contains($tag->id))>
                        <label class="form-check-label" for="tag_{{ $tag->id }}">{{ $tag->name }}</label>
                    </div>
                @endforeach
            </div>
        </div>
    </x-card.default>

    <x-card.default title="Notes">
        <textarea name="notes" class="form-control" rows="4">{{ old('notes', $profile->notes) }}</textarea>
    </x-card.default>

    <div class="text-end">
        <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
    </div>
</form>
