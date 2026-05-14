<!-- Status form fields: shared by create and edit modals so validation names stay identical. -->
<div class="mb-3">
    <label class="form-label" for="status_name_{{ $status?->id ?? 'new' }}">Name</label>
    <input id="status_name_{{ $status?->id ?? 'new' }}" name="name" class="form-control" value="{{ old('name', $status?->name) }}" required>
</div>

<div class="mb-3">
    <label class="form-label" for="status_slug_{{ $status?->id ?? 'new' }}">Slug</label>
    <input id="status_slug_{{ $status?->id ?? 'new' }}" name="slug" class="form-control" value="{{ old('slug', $status?->slug) }}" placeholder="Generated from name if empty">
</div>

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="status_state_{{ $status?->id ?? 'new' }}">Lifecycle state</label>
        <select id="status_state_{{ $status?->id ?? 'new' }}" name="state" class="form-select" required>
            @foreach (['open' => 'Open', 'waiting' => 'Waiting', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $value => $label)
                <option value="{{ $value }}" @selected(old('state', $status?->state ?? 'open') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="status_sort_order_{{ $status?->id ?? 'new' }}">Sort order</label>
        <input id="status_sort_order_{{ $status?->id ?? 'new' }}" name="sort_order" type="number" min="0" class="form-control" value="{{ old('sort_order', $status?->sort_order ?? 100) }}">
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-md-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="status_active_{{ $status?->id ?? 'new' }}" name="is_active" value="1" @checked(old('is_active', $status?->is_active ?? true))>
            <label class="form-check-label" for="status_active_{{ $status?->id ?? 'new' }}">Active</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="status_default_{{ $status?->id ?? 'new' }}" name="is_default" value="1" @checked(old('is_default', $status?->is_default ?? false))>
            <label class="form-check-label" for="status_default_{{ $status?->id ?? 'new' }}">Default</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="status_closed_{{ $status?->id ?? 'new' }}" name="is_closed" value="1" @checked(old('is_closed', $status?->is_closed ?? false))>
            <label class="form-check-label" for="status_closed_{{ $status?->id ?? 'new' }}">Closed</label>
        </div>
    </div>
</div>
