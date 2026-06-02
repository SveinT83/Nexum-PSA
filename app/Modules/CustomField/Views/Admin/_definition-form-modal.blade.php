@php
    $isEdit = isset($definition) && $definition->exists;
    $modalTitle = $isEdit ? 'Edit custom field' : 'New custom field';
    $formAction = $isEdit
        ? route('tech.admin.settings.custom-fields.update', $definition)
        : route('tech.admin.settings.custom-fields.store');
    $modalLabelId = $modalId.'Label';
    $currentModel = $isEdit ? $modelRegistry->labelFor($definition->model_type) : old('model_type', 'client');
    $checkboxDefaults = ['visible_in_ui', 'editable_in_ui', 'editable_via_api', 'active'];
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalLabelId }}" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" action="{{ $formAction }}" class="modal-content">
            @csrf
            @if($isEdit)
                @method('PATCH')
                <input type="hidden" name="model_type" value="{{ $definition->model_type }}">
            @endif

            <div class="modal-header">
                <h2 class="modal-title fs-5" id="{{ $modalLabelId }}">{{ $modalTitle }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Applies to</label>
                        <select name="model_type" class="form-select @error('model_type') is-invalid @enderror" @disabled($isEdit) required>
                            @foreach($models as $alias => $class)
                                <option value="{{ $alias }}" @selected($currentModel === $alias)>{{ ucfirst($alias) }}</option>
                            @endforeach
                        </select>
                        @error('model_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Key</label>
                        <input type="text" name="key" value="{{ old('key', $definition?->key) }}" class="form-control @error('key') is-invalid @enderror" placeholder="msp_manager_id" required>
                        @error('key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Label</label>
                        <input type="text" name="label" value="{{ old('label', $definition?->label) }}" class="form-control @error('label') is-invalid @enderror" placeholder="MSP Manager ID" required>
                        @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Type</label>
                        <select name="field_type" class="form-select @error('field_type') is-invalid @enderror" required>
                            @foreach($fieldTypes as $type)
                                <option value="{{ $type }}" @selected(old('field_type', $definition?->field_type ?? 'text') === $type)>{{ ucfirst($type) }}</option>
                            @endforeach
                        </select>
                        @error('field_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Help text</label>
                        <input type="text" name="help_text" value="{{ old('help_text', $definition?->help_text) }}" class="form-control @error('help_text') is-invalid @enderror">
                        @error('help_text')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">View permission</label>
                        <input type="text" name="view_permission" value="{{ old('view_permission', $definition?->view_permission) }}" class="form-control" placeholder="Optional">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Edit permission</label>
                        <input type="text" name="edit_permission" value="{{ old('edit_permission', $definition?->edit_permission) }}" class="form-control" placeholder="Optional">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Options</label>
                        <textarea name="options_text" rows="3" class="form-control" placeholder="One option per line">{{ old('options_text', implode("\n", $definition?->options ?? [])) }}</textarea>
                        <div class="form-text">Used by select and multiselect fields.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Sort order</label>
                        <input type="number" name="sort_order" value="{{ old('sort_order', $definition?->sort_order ?? 0) }}" min="0" class="form-control">
                    </div>
                    <div class="col-12">
                        <div class="d-flex flex-wrap gap-3">
                            @foreach(['visible_in_ui' => 'Visible in UI', 'editable_in_ui' => 'Editable in UI', 'editable_via_api' => 'Editable via API', 'searchable' => 'Searchable', 'unique_per_model' => 'Unique per model', 'required' => 'Required', 'admin_only' => 'Admin only', 'active' => 'Active'] as $field => $label)
                                <div class="form-check">
                                    <input type="hidden" name="{{ $field }}" value="0">
                                    <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1" id="{{ $modalId }}_{{ $field }}" @checked(old($field, $isEdit ? $definition->{$field} : in_array($field, $checkboxDefaults, true)))>
                                    <label class="form-check-label" for="{{ $modalId }}_{{ $field }}">{{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Save field' : 'Create field' }}</button>
            </div>
        </form>
    </div>
</div>
