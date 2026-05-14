<!-- Ticket type form fields: shared by create and edit modals to keep request payloads consistent. -->
<div class="mb-3">
    <label class="form-label" for="type_name_{{ $type?->id ?? 'new' }}">Name</label>
    <input id="type_name_{{ $type?->id ?? 'new' }}" name="name" class="form-control" value="{{ old('name', $type?->name) }}" required>
</div>

<div class="mb-3">
    <label class="form-label" for="type_slug_{{ $type?->id ?? 'new' }}">Slug</label>
    <input id="type_slug_{{ $type?->id ?? 'new' }}" name="slug" class="form-control" value="{{ old('slug', $type?->slug) }}" placeholder="Generated from name if empty">
</div>

<div class="mb-3">
    <label class="form-label" for="type_description_{{ $type?->id ?? 'new' }}">Description</label>
    <textarea id="type_description_{{ $type?->id ?? 'new' }}" name="description" class="form-control" rows="3">{{ old('description', $type?->description) }}</textarea>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="type_sort_order_{{ $type?->id ?? 'new' }}">Sort order</label>
        <input id="type_sort_order_{{ $type?->id ?? 'new' }}" name="sort_order" type="number" min="0" class="form-control" value="{{ old('sort_order', $type?->sort_order ?? 100) }}">
    </div>
    <div class="col-md-6">
        <div class="form-check form-switch mt-4">
            <input class="form-check-input" type="checkbox" id="type_active_{{ $type?->id ?? 'new' }}" name="is_active" value="1" @checked(old('is_active', $type?->is_active ?? true))>
            <label class="form-check-label" for="type_active_{{ $type?->id ?? 'new' }}">Active</label>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="type_deletable_{{ $type?->id ?? 'new' }}" name="is_deletable" value="1" @checked(old('is_deletable', $type?->is_deletable ?? true)) @disabled($type?->is_system && ! $type?->is_deletable)>
            <label class="form-check-label" for="type_deletable_{{ $type?->id ?? 'new' }}">Deletable</label>
        </div>
    </div>
</div>
