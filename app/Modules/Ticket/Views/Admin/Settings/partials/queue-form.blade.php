<!-- Queue form fields: queue email is stored here now and can be used by future inbound routing. -->
<div class="mb-3">
    <label class="form-label" for="queue_name_{{ $queue?->id ?? 'new' }}">Name</label>
    <input id="queue_name_{{ $queue?->id ?? 'new' }}" name="name" class="form-control" value="{{ old('name', $queue?->name) }}" required>
</div>

<div class="mb-3">
    <label class="form-label" for="queue_slug_{{ $queue?->id ?? 'new' }}">Slug</label>
    <input id="queue_slug_{{ $queue?->id ?? 'new' }}" name="slug" class="form-control" value="{{ old('slug', $queue?->slug) }}" placeholder="Generated from name if empty">
</div>

<div class="mb-3">
    <label class="form-label" for="queue_email_{{ $queue?->id ?? 'new' }}">Email address</label>
    <input id="queue_email_{{ $queue?->id ?? 'new' }}" name="email_address" type="email" class="form-control" value="{{ old('email_address', $queue?->email_address) }}">
</div>

<div class="mb-3">
    <label class="form-label" for="queue_description_{{ $queue?->id ?? 'new' }}">Description</label>
    <textarea id="queue_description_{{ $queue?->id ?? 'new' }}" name="description" class="form-control" rows="3">{{ old('description', $queue?->description) }}</textarea>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="queue_sort_order_{{ $queue?->id ?? 'new' }}">Sort order</label>
        <input id="queue_sort_order_{{ $queue?->id ?? 'new' }}" name="sort_order" type="number" min="0" class="form-control" value="{{ old('sort_order', $queue?->sort_order ?? 100) }}">
    </div>
    <div class="col-md-6">
        <div class="form-check form-switch mt-4">
            <input class="form-check-input" type="checkbox" id="queue_active_{{ $queue?->id ?? 'new' }}" name="is_active" value="1" @checked(old('is_active', $queue?->is_active ?? true))>
            <label class="form-check-label" for="queue_active_{{ $queue?->id ?? 'new' }}">Active</label>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="queue_default_{{ $queue?->id ?? 'new' }}" name="is_default" value="1" @checked(old('is_default', $queue?->is_default ?? false))>
            <label class="form-check-label" for="queue_default_{{ $queue?->id ?? 'new' }}">Default</label>
        </div>
    </div>
</div>
