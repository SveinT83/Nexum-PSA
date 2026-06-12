<div class="card">
    <div class="card-header">
        <span class="fw-semibold">Rule</span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-8">
                <label for="name" class="form-label">Name</label>
                <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $rule->name) }}" required maxlength="255">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-lg-2">
                <label for="priority" class="form-label">Priority</label>
                <input type="number" id="priority" name="priority" class="form-control @error('priority') is-invalid @enderror" value="{{ old('priority', $rule->priority ?? 100) }}" min="1" max="10000" required>
                @error('priority')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-lg-2 d-flex align-items-end">
                <input type="hidden" name="is_active" value="0">
                <div class="form-check mb-2">
                    <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input" @checked(old('is_active', $rule->is_active ?? true))>
                    <label for="is_active" class="form-check-label">Active</label>
                </div>
            </div>
            <div class="col-12">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" rows="2" class="form-control @error('description') is-invalid @enderror">{{ old('description', $rule->description) }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-lg-6">
                <label for="conditions_json" class="form-label">Conditions JSON</label>
                <textarea id="conditions_json" name="conditions_json" rows="8" class="form-control font-monospace @error('conditions_json') is-invalid @enderror">{{ old('conditions_json', json_encode($rule->conditions ?: ['source_domain' => ['marketing'], 'signal_type' => ['unsubscribe']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea>
                @error('conditions_json')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-lg-6">
                <label for="actions_json" class="form-label">Actions JSON</label>
                <textarea id="actions_json" name="actions_json" rows="8" class="form-control font-monospace @error('actions_json') is-invalid @enderror">{{ old('actions_json', json_encode($rule->actions ?: [['type' => 'marketing_suppress_contact_email'], ['type' => 'sales_follow_up', 'follow_up_minutes_from_now' => 1440], ['type' => 'ticket_follow_up', 'subject' => 'Investigate signal']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea>
                @error('actions_json')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>
</div>

@isset($definition)
    <div class="card">
        <div class="card-header">
            <span class="fw-semibold">Rule Reference</span>
        </div>
        <div class="card-body">
            <div class="row g-3 small">
                <div class="col-lg-5">
                    <div class="text-muted text-uppercase mb-2">Conditions</div>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach($definition::CONDITION_FIELDS as $field)
                            <span class="badge text-bg-light border">{{ $field }}</span>
                        @endforeach
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="text-muted text-uppercase mb-2">Actions</div>
                    <div class="row g-2">
                        @foreach($definition::ACTION_TYPES as $type => $action)
                            <div class="col-md-6">
                                <div class="border rounded p-2 h-100">
                                    <div class="fw-semibold">{{ $type }}</div>
                                    <div class="text-muted">{{ $action['label'] }}</div>
                                    @if(! empty($action['required']))
                                        <div class="text-muted">Requires: {{ implode(', ', $action['required']) }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
@endisset

<div class="d-flex justify-content-end gap-2">
    <a href="{{ route('tech.admin.system.signals.rules.index') }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-sm btn-primary">Save Rule</button>
</div>
