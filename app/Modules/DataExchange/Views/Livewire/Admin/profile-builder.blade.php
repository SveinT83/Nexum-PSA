<div>
    <!-- ------------------------------------------------- -->
    <!-- Profile Builder Form -->
    <!-- ------------------------------------------------- -->
    <form wire:submit="save" class="d-grid gap-3">
        <div class="card">
            <div class="card-header">
                <h2 class="h6 mb-0">Profile</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label" for="data_exchange_name">Name</label>
                        <input id="data_exchange_name" type="text" class="form-control" wire:model.live="name">
                        @error('name') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="data_exchange_key">Key</label>
                        <input id="data_exchange_key" type="text" class="form-control" wire:model="key">
                        @error('key') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="data_exchange_status">Status</label>
                        <select id="data_exchange_status" class="form-select" wire:model="status">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="paused">Paused</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="data_exchange_direction">Direction</label>
                        <select id="data_exchange_direction" class="form-select" wire:model.live="direction">
                            <option value="export">Export</option>
                            <option value="import">Import</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="data_exchange_format">Format</label>
                        <select id="data_exchange_format" class="form-select" wire:model="format">
                            <option value="csv">CSV</option>
                            <option value="xlsx">XLSX</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="data_exchange_source">Source or target</label>
                        <select id="data_exchange_source" class="form-select" wire:model.live="sourceKey">
                            <option value="">Choose source</option>
                            @foreach($sources as $registeredSource)
                                <option value="{{ $registeredSource->key }}">{{ $registeredSource->label }} ({{ $registeredSource->module }})</option>
                            @endforeach
                        </select>
                        @error('sourceKey') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="data_exchange_description">Description</label>
                        <textarea id="data_exchange_description" rows="2" class="form-control" wire:model="description"></textarea>
                    </div>
                </div>
            </div>
        </div>

        @if($source)
            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center justify-content-between gap-2">
                            <h2 class="h6 mb-0">Fields</h2>
                            <span class="badge text-bg-light border">{{ count($availableFields) }}</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 42px;">Use</th>
                                        <th>Field</th>
                                        <th>Output key</th>
                                        <th>Label</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($availableFields as $field)
                                        @php($fieldStateKey = $this->fieldStateKey($field->key))
                                        <tr>
                                            <td>
                                                <input class="form-check-input" type="checkbox" value="{{ $field->key }}" wire:model.live="selectedFields">
                                            </td>
                                            <td>
                                                <div class="fw-semibold small">{{ $field->label }}</div>
                                                <div class="text-muted small">{{ $field->key }} · {{ $field->type }}</div>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" wire:model="fieldOutputKeys.{{ $fieldStateKey }}" placeholder="{{ $field->key }}">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" wire:model="fieldLabels.{{ $fieldStateKey }}" placeholder="{{ $field->label }}">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @error('selectedFields') <div class="card-footer text-danger small">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center justify-content-between gap-2">
                            <h2 class="h6 mb-0">Relations</h2>
                            <span class="badge text-bg-light border">{{ count($source->relations) }}</span>
                        </div>
                        <div class="card-body">
                            @forelse($source->relations as $relation)
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <span>{{ $relation['label'] }}</span>
                                    <span class="text-muted small">{{ $relation['cardinality'] }}</span>
                                </div>
                            @empty
                                <p class="text-muted small mb-0">No related data is exposed for this source.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header d-flex align-items-center justify-content-between gap-2">
                            <h2 class="h6 mb-0">Filters</h2>
                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="addFilter">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                Add
                            </button>
                        </div>
                        <div class="card-body d-grid gap-2">
                            @forelse($filters as $index => $filter)
                                <div class="border rounded p-2">
                                    <div class="row g-2">
                                        <div class="col-12">
                                            <select class="form-select form-select-sm" wire:model="filters.{{ $index }}.field_key">
                                                <option value="">Field</option>
                                                @foreach($source->filters ?: collect($availableFields)->pluck('key')->all() as $filterField)
                                                    <option value="{{ $filterField }}">{{ $filterField }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <select class="form-select form-select-sm" wire:model="filters.{{ $index }}.operator">
                                                @foreach($operators as $operator => $label)
                                                    <option value="{{ $operator }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <input type="text" class="form-control form-control-sm" wire:model="filters.{{ $index }}.value" placeholder="Value">
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-2">
                                        <label class="form-check small mb-0">
                                            <input class="form-check-input" type="checkbox" wire:model="filters.{{ $index }}.active">
                                            Active
                                        </label>
                                        <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeFilter({{ $index }})">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                            @empty
                                <p class="text-muted small mb-0">No filters configured.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.admin.system.data-exchange.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save" aria-hidden="true"></i>
                Save profile
            </button>
        </div>
    </form>
</div>
