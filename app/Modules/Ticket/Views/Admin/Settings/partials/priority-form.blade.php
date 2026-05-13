<!-- Priority form fields: level controls sorting and escalation semantics across ticket views. -->
<div class="mb-3">
    <label class="form-label" for="priority_name_{{ $priority?->id ?? 'new' }}">Name</label>
    <input id="priority_name_{{ $priority?->id ?? 'new' }}" name="name" class="form-control" value="{{ old('name', $priority?->name) }}" required>
</div>

<div class="mb-3">
    <label class="form-label" for="priority_slug_{{ $priority?->id ?? 'new' }}">Slug</label>
    <input id="priority_slug_{{ $priority?->id ?? 'new' }}" name="slug" class="form-control" value="{{ old('slug', $priority?->slug) }}" placeholder="Generated from name if empty">
</div>

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="priority_level_{{ $priority?->id ?? 'new' }}">Level</label>
        <input id="priority_level_{{ $priority?->id ?? 'new' }}" name="level" type="number" min="1" max="255" class="form-control" value="{{ old('level', $priority?->level ?? 3) }}" required>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="priority_sort_order_{{ $priority?->id ?? 'new' }}">Sort order</label>
        <input id="priority_sort_order_{{ $priority?->id ?? 'new' }}" name="sort_order" type="number" min="0" class="form-control" value="{{ old('sort_order', $priority?->sort_order ?? 100) }}">
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-md-6">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="priority_active_{{ $priority?->id ?? 'new' }}" name="is_active" value="1" @checked(old('is_active', $priority?->is_active ?? true))>
            <label class="form-check-label" for="priority_active_{{ $priority?->id ?? 'new' }}">Active</label>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="priority_default_{{ $priority?->id ?? 'new' }}" name="is_default" value="1" @checked(old('is_default', $priority?->is_default ?? false))>
            <label class="form-check-label" for="priority_default_{{ $priority?->id ?? 'new' }}">Default</label>
        </div>
    </div>
</div>
