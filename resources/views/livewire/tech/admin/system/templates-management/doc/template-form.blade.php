<div class="container-fluid">
    <form wire:submit.prevent="save">
        <div class="card mb-4">
            <div class="card-header">
                <h4>{{ $templateId ? 'Edit' : 'Create' }} Documentation Template</h4>
            </div>
            <div class="card-body">
                @if (session()->has('success'))
                    <div class="alert alert-success">
                        {{ session('success') }}
                    </div>
                @endif

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="name" class="form-label">Template Name</label>
                        <input type="text" id="name" class="form-control" wire:model="name">
                        @error('name') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="category_id" class="form-label">Category</label>
                        <select id="category_id" class="form-select" wire:model="category_id">
                            <option value="">Select Category</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('category_id') <span class="text-danger">{{ $message }}</span> @enderror
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="is_active" class="form-label">Active</label>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" id="is_active" wire:model="is_active">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5>Fields Configuration</h5>
                <button type="button" class="btn btn-sm btn-primary" wire:click="addRow">Add New Section (Row)</button>
            </div>
            <div class="card-body">
                @foreach($fields as $index => $field)
                    <div class="border rounded p-3 mb-3 bg-light">
                        <div class="row align-items-center">
                            @if(isset($field['layout']) && $field['layout'] === 'rowStart')
                                <div class="col-md-8">
                                    <div class="input-group">
                                        <span class="input-group-text bg-info text-white">Section</span>
                                        <input type="text" class="form-control" wire:model="fields.{{ $index }}.labelName" placeholder="Section Name">
                                    </div>
                                    @error("fields.$index.labelName") <span class="text-danger">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4 text-end">
                                    <button type="button" class="btn btn-sm btn-outline-success" wire:click="addField({{ $index }})" title="Add field after this section">Add Field</button>
                                </div>
                            @else
                                <div class="col-md-3">
                                    <label class="small">Label</label>
                                    <input type="text" class="form-control form-control-sm" wire:model="fields.{{ $index }}.labelName" placeholder="Field Label">
                                    @error("fields.$index.labelName") <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-3">
                                    <label class="small">System Name (slug)</label>
                                    <input type="text" class="form-control form-control-sm" wire:model="fields.{{ $index }}.Name" placeholder="field_name">
                                    @error("fields.$index.Name") <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-3">
                                    <label class="small">Type</label>
                                    <select class="form-select form-select-sm" wire:model="fields.{{ $index }}.type">
                                        <option value="text">Text</option>
                                        <option value="textarea">Textarea</option>
                                        <option value="checkbox">Checkbox</option>
                                        <option value="select">Select</option>
                                        <option value="date">Date</option>
                                    </select>
                                    @error("fields.$index.type") <span class="text-danger small">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-3 text-end">

                                    <div class="row justify-content-end">
                                        <div class="col-md-auto text-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="moveUp({{ $index }})" {{ $index == 0 ? 'disabled' : '' }}>↑</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="moveDown({{ $index }})" {{ $index == count($fields) - 1 ? 'disabled' : '' }}>↓</button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeField({{ $index }})">×</button>
                                        </div>

                                        <div class="col-md-auto text-end">
                                            <button type="button" class="btn btn-sm btn-outline-success" wire:click="addField({{ $index }})" title="Add field after this">Add</button>
                                        </div>
                                    </div>
                                </div>
                            @endif

                        </div>
                    </div>
                @endforeach
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-success">Save Template</button>
            </div>
        </div>
    </form>
</div>
