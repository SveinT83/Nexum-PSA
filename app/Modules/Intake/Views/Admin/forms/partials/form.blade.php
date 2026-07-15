@php
    $fieldTypes = \App\Modules\Intake\Models\IntakeFormField::FIELD_TYPES;
    $mapTargets = \App\Modules\Intake\Models\IntakeFormField::MAP_TARGETS;
    $rows = old('fields', $fieldRows);
    $formMimeTypes = old('allowed_mime_types_text', implode("\n", $form->allowed_mime_types ?: \App\Modules\Intake\Models\IntakeForm::DEFAULT_ALLOWED_MIME_TYPES));
    $submitButtonLabel = old('submit_button_label', $form->submitButtonLabel());
    $settingsErrorFields = [
        'name',
        'slug',
        'description',
        'status',
        'success_message',
        'target_type',
        'owner_id',
        'spam_honeypot_field',
        'max_files',
        'max_file_size_kb',
        'allowed_mime_types_text',
    ];
    $settingsExpanded = ($mode ?? 'edit') === 'create';

    foreach ($settingsErrorFields as $settingsErrorField) {
        if ($errors->has($settingsErrorField)) {
            $settingsExpanded = true;
            break;
        }
    }
@endphp

<form method="POST" action="{{ $action }}">
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Intake Form Settings -->
    <!-- ------------------------------------------------- -->
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-body p-0">
            <button type="button" class="btn w-100 d-flex align-items-center justify-content-between gap-2 text-start px-3 py-2" data-bs-toggle="collapse" data-bs-target="#intake-form-settings-panel" aria-expanded="{{ $settingsExpanded ? 'true' : 'false' }}" aria-controls="intake-form-settings-panel">
                <span class="h6 mb-0">Form settings</span>
                <i class="bi {{ $settingsExpanded ? 'bi-chevron-down' : 'bi-chevron-right' }} text-muted" aria-hidden="true" data-intake-collapse-icon></i>
            </button>
        </div>
        <div id="intake-form-settings-panel" class="collapse {{ $settingsExpanded ? 'show' : '' }}">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $form->name) }}" required>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="slug" class="form-label">URL slug</label>
                        <input type="text" id="slug" name="slug" class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug', $form->slug) }}">
                        @error('slug') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" rows="2" class="form-control @error('description') is-invalid @enderror">{{ old('description', $form->description) }}</textarea>
                        @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-select @error('status') is-invalid @enderror">
                            @foreach(['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', $form->status) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="target_type" class="form-label">Target</label>
                        <select id="target_type" name="target_type" class="form-select @error('target_type') is-invalid @enderror">
                            <option value="review_only" @selected(old('target_type', $form->target_type) === 'review_only')>Review only</option>
                            <option value="sales_lead" @selected(old('target_type', $form->target_type) === 'sales_lead')>Sales lead</option>
                        </select>
                        @error('target_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="owner_id" class="form-label">Owner</label>
                        <select id="owner_id" name="owner_id" class="form-select @error('owner_id') is-invalid @enderror">
                            <option value="">No owner</option>
                            @foreach($owners as $owner)
                                <option value="{{ $owner->id }}" @selected((string) old('owner_id', $form->owner_id) === (string) $owner->id)>{{ $owner->name }} ({{ $owner->email }})</option>
                            @endforeach
                        </select>
                        @error('owner_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="spam_honeypot_field" class="form-label">Honeypot field</label>
                        <input type="text" id="spam_honeypot_field" name="spam_honeypot_field" class="form-control @error('spam_honeypot_field') is-invalid @enderror" value="{{ old('spam_honeypot_field', $form->spam_honeypot_field ?: 'intake_website') }}">
                        @error('spam_honeypot_field') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="max_files" class="form-label">Default max files</label>
                        <input type="number" min="0" max="20" id="max_files" name="max_files" class="form-control @error('max_files') is-invalid @enderror" value="{{ old('max_files', $form->max_files ?: 5) }}">
                        @error('max_files') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label for="max_file_size_kb" class="form-label">Default file size KB</label>
                        <input type="number" min="1" max="51200" id="max_file_size_kb" name="max_file_size_kb" class="form-control @error('max_file_size_kb') is-invalid @enderror" value="{{ old('max_file_size_kb', $form->max_file_size_kb ?: 20480) }}">
                        @error('max_file_size_kb') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="allowed_mime_types_text" class="form-label">Default MIME types</label>
                        <textarea id="allowed_mime_types_text" name="allowed_mime_types_text" rows="4" class="form-control @error('allowed_mime_types_text') is-invalid @enderror">{{ $formMimeTypes }}</textarea>
                        @error('allowed_mime_types_text') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="success_message" class="form-label">Success message</label>
                        <textarea id="success_message" name="success_message" rows="4" class="form-control @error('success_message') is-invalid @enderror">{{ old('success_message', $form->success_message) }}</textarea>
                        @error('success_message') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input type="checkbox" id="auto_create_contact" name="auto_create_contact" value="1" class="form-check-input" @checked(old('auto_create_contact', $form->auto_create_contact))>
                            <label for="auto_create_contact" class="form-check-label">Auto-create or link contact on Sales routing</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input type="checkbox" id="auto_create_client" name="auto_create_client" value="1" class="form-check-input" @checked(old('auto_create_client', $form->auto_create_client))>
                            <label for="auto_create_client" class="form-check-label">Auto-create client when no match exists</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($form->exists)
        <!-- ------------------------------------------------- -->
        <!-- Intake Submission Automation -->
        <!-- ------------------------------------------------- -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-body d-flex align-items-center justify-content-between gap-2">
                <h2 class="h6 mb-0">After submission</h2>
                <a href="{{ route('tech.admin.system.signals.rules.create', ['source_domain' => 'intake', 'intake_form_id' => $form->id]) }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-lightning-charge" aria-hidden="true"></i>
                    New automation
                </a>
            </div>
            <div class="card-body py-2">
                <div class="small text-muted">
                    Signal rules decide what happens after this form is submitted, such as creating tickets, tasks, portal invitations, Sales follow-up, or webhooks.
                </div>
            </div>
        </div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Intake Field Builder -->
    <!-- ------------------------------------------------- -->
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-body">
            <h2 class="h6 mb-0">Fields</h2>
        </div>
        <div class="card-body">
            @error('fields')
                <div class="alert alert-danger">{{ $message }}</div>
            @enderror

            <div class="border rounded bg-body-tertiary text-muted small p-3 mb-3 {{ count($rows) === 0 ? '' : 'd-none' }}" data-intake-field-empty>
                No fields yet.
            </div>

            <div class="row g-3" data-intake-field-list>
                @foreach($rows as $index => $row)
                    @include('intake::Admin.forms.partials.field-row', ['index' => $index, 'row' => $row, 'allRows' => $rows, 'fieldTypes' => $fieldTypes, 'mapTargets' => $mapTargets])
                @endforeach
            </div>

            <div class="d-flex justify-content-center mt-3" data-intake-field-add-row>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle d-inline-flex align-items-center justify-content-center p-0" style="width: 2rem; height: 2rem;" data-add-intake-field aria-label="New field" title="New field">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                </button>
            </div>

            <div class="mt-3" data-intake-submit-row>
                <div class="border rounded bg-body">
                    <div class="d-flex align-items-center gap-2 p-2 bg-body-tertiary border-bottom">
                        <button type="button" id="intake_submit_summary" class="btn btn-sm flex-grow-1 d-flex align-items-center gap-2 text-start px-2 py-1" data-toggle-intake-submit-row aria-expanded="false" aria-controls="intake_submit_panel">
                            <i class="bi bi-chevron-right text-muted" aria-hidden="true" data-intake-submit-chevron></i>
                            <span class="fw-semibold text-truncate">Submit button</span>
                            <span class="badge text-bg-light border ms-auto">Button</span>
                        </button>
                    </div>
                    <div id="intake_submit_panel" class="p-3 d-none" data-intake-submit-panel aria-labelledby="intake_submit_summary">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-8">
                                <label for="submit_button_label" class="form-label">Submit button text</label>
                                <input type="text" id="submit_button_label" name="submit_button_label" maxlength="120" class="form-control form-control-sm @error('submit_button_label') is-invalid @enderror" value="{{ $submitButtonLabel }}">
                                @error('submit_button_label') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-primary w-100" tabindex="-1">
                                    <i class="bi bi-send" aria-hidden="true"></i>
                                    <span data-intake-submit-preview>{{ $submitButtonLabel ?: \App\Modules\Intake\Models\IntakeForm::DEFAULT_SUBMIT_BUTTON_LABEL }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-4">
        <a href="{{ route('tech.admin.system.intake.index') }}" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save" aria-hidden="true"></i>
            Save form
        </button>
    </div>
</form>

<template id="intake-field-row-template">
    @include('intake::Admin.forms.partials.field-row', [
        'index' => '__INDEX__',
        'row' => [
            'id' => null,
            'key' => '',
            'label' => '',
            'field_type' => \App\Modules\Intake\Models\IntakeFormField::TYPE_TEXT,
            'maps_to' => null,
            'help_text' => '',
            'placeholder' => '',
            'options_text' => '',
            'is_required' => false,
            'is_active' => true,
            'max_files' => null,
            'max_file_size_kb' => null,
            'allowed_mime_types_text' => '',
            'layout_width' => 12,
            'visibility_mode' => \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MODE_ALWAYS,
            'visibility_match' => \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MATCH_ALL,
            'visibility_rules' => [],
        ],
        'fieldTypes' => $fieldTypes,
        'mapTargets' => $mapTargets,
        'allRows' => $rows,
    ])
</template>

@section('scripts')
    <script>
        const intakeChoiceFieldTypes = ['select', 'multiselect'];
        const intakeLayoutWidths = ['12', '6', '4', '3'];
        const intakeVisibilityOperatorsNeedingValue = ['equals', 'not_equals', 'contains'];
        let draggedIntakeField = null;
        let intakeDragHandleActive = false;

        function setIntakeFieldExpanded(row, expanded) {
            const panel = row.querySelector('[data-intake-field-panel]');
            const toggle = row.querySelector('[data-toggle-intake-field]');
            const chevron = row.querySelector('[data-intake-field-chevron]');

            panel?.classList.toggle('d-none', ! expanded);
            toggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            chevron?.classList.toggle('bi-chevron-right', ! expanded);
            chevron?.classList.toggle('bi-chevron-down', expanded);
        }

        function setIntakeSubmitExpanded(expanded) {
            const row = document.querySelector('[data-intake-submit-row]');
            const panel = row?.querySelector('[data-intake-submit-panel]');
            const toggle = row?.querySelector('[data-toggle-intake-submit-row]');
            const chevron = row?.querySelector('[data-intake-submit-chevron]');

            panel?.classList.toggle('d-none', ! expanded);
            toggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            chevron?.classList.toggle('bi-chevron-right', ! expanded);
            chevron?.classList.toggle('bi-chevron-down', expanded);
        }

        function expandIntakeField(row) {
            const list = document.querySelector('[data-intake-field-list]');

            list?.querySelectorAll('[data-intake-field-row]').forEach(function (fieldRow) {
                setIntakeFieldExpanded(fieldRow, fieldRow === row);
            });
            setIntakeSubmitExpanded(false);
        }

        function expandIntakeSubmit() {
            document.querySelectorAll('[data-intake-field-row]').forEach(function (fieldRow) {
                setIntakeFieldExpanded(fieldRow, false);
            });
            setIntakeSubmitExpanded(true);
        }

        function setIntakeFieldLayout(row, width) {
            const safeWidth = intakeLayoutWidths.includes(String(width)) ? String(width) : '12';
            const layoutSelect = row.querySelector('[data-intake-layout-width]');

            intakeLayoutWidths.forEach(function (layoutWidth) {
                row.classList.remove('col-md-' + layoutWidth);
            });

            row.classList.add('col-md-' + safeWidth);
            row.dataset.layoutWidth = safeWidth;

            if (layoutSelect) {
                layoutSelect.value = safeWidth;
            }
        }

        function updateIntakeRequiredSummary(input) {
            const row = input.closest('[data-intake-field-row]');
            const summary = row?.querySelector('[data-intake-required-summary]');

            if (summary) {
                summary.classList.toggle('d-none', ! input.checked);
            }
        }

        function closestIntakeFieldAfter(list, x, y) {
            const candidates = Array.from(list.querySelectorAll('[data-intake-field-row]:not(.opacity-50)'));

            return candidates.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const sameRow = y >= box.top && y <= box.bottom;
                const offset = sameRow ? x - box.left - (box.width / 2) : y - box.top - (box.height / 2);

                if (offset < 0 && offset > closest.offset) {
                    return { offset, element: child };
                }

                return closest;
            }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
        }

        function updateIntakeFieldSummary(input) {
            const row = input.closest('[data-intake-field-row]');
            const summary = row?.querySelector('[data-intake-field-summary]');

            if (summary) {
                summary.textContent = input.value.trim() || 'New field';
            }
        }

        function updateIntakeFieldTypeSummary(select) {
            const row = select.closest('[data-intake-field-row]');
            const summary = row?.querySelector('[data-intake-field-type-summary]');
            const option = select.selectedOptions?.[0];

            if (summary && option) {
                summary.textContent = option.textContent.trim();
            }
        }

        function createIntakeOptionRow(value = '') {
            const row = document.createElement('div');
            row.className = 'input-group input-group-sm';
            row.setAttribute('data-intake-option-row', '');

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.placeholder = 'Option';
            input.value = value;
            input.setAttribute('data-intake-option-input', '');

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'btn btn-outline-danger';
            removeButton.setAttribute('data-remove-intake-option', '');
            removeButton.setAttribute('aria-label', 'Remove option');
            removeButton.setAttribute('title', 'Remove option');
            removeButton.innerHTML = '<i class="bi bi-trash" aria-hidden="true"></i>';

            row.append(input, removeButton);

            return row;
        }

        function syncIntakeOptions(row) {
            const textarea = row.querySelector('[data-intake-options-text]');
            const values = Array.from(row.querySelectorAll('[data-intake-option-input]'))
                .map((input) => input.value.trim())
                .filter(Boolean);

            if (textarea) {
                textarea.value = values.join('\n');
            }
        }

        function ensureIntakeOptionRow(row) {
            const list = row.querySelector('[data-intake-option-list]');

            if (list && ! list.querySelector('[data-intake-option-row]')) {
                list.append(createIntakeOptionRow());
            }
        }

        function intakeFieldIndex(row) {
            const labelInput = row.querySelector('input[name$="[label]"]');
            const match = labelInput?.name.match(/^fields\[(.+)]\[label]$/);

            return match?.[1] || Date.now().toString();
        }

        function intakeOptionValues(row) {
            const textarea = row.querySelector('[data-intake-options-text]');

            return (textarea?.value || '')
                .split(/\r?\n/)
                .map((value) => value.trim())
                .filter(Boolean);
        }

        function intakeFieldMeta(row) {
            const key = row.querySelector('input[name$="[key]"]')?.value.trim() || '';
            const label = row.querySelector('input[name$="[label]"]')?.value.trim() || key;
            const type = row.querySelector('select[name$="[field_type]"]')?.value || 'text';

            return {
                key,
                label,
                type,
                options: intakeOptionValues(row),
            };
        }

        function intakeSourceFieldsBefore(row) {
            const rows = Array.from(document.querySelectorAll('[data-intake-field-row]'));
            const sources = [];

            for (const candidate of rows) {
                if (candidate === row) {
                    break;
                }

                const meta = intakeFieldMeta(candidate);

                if (meta.key) {
                    sources.push(meta);
                }
            }

            return sources;
        }

        function syncIntakeVisibilityRuleControls(ruleRow) {
            const operator = ruleRow.querySelector('[data-intake-visibility-operator]')?.value || 'has_value';
            const sourceSelect = ruleRow.querySelector('[data-intake-visibility-source]');
            const valueWrap = ruleRow.querySelector('[data-intake-visibility-value-wrap]');
            const valueInput = ruleRow.querySelector('[data-intake-visibility-value]');
            const valueSelect = ruleRow.querySelector('[data-intake-visibility-value-select]');
            const needsValue = intakeVisibilityOperatorsNeedingValue.includes(operator);

            valueWrap?.classList.toggle('d-none', ! needsValue);

            if (! valueInput || ! valueSelect) {
                return;
            }

            if (! needsValue) {
                valueInput.value = '';
                valueInput.disabled = true;
                valueInput.classList.remove('d-none');
                valueSelect.classList.add('d-none');
                valueSelect.replaceChildren();
                return;
            }

            valueInput.disabled = false;

            let sourceOptions = [];

            try {
                sourceOptions = JSON.parse(sourceSelect?.selectedOptions?.[0]?.dataset.options || '[]');
            } catch (error) {
                sourceOptions = [];
            }

            if (sourceOptions.length > 0) {
                const currentValue = valueInput.value;
                valueSelect.replaceChildren(new Option('Select value', ''));
                sourceOptions.forEach(function (optionValue) {
                    valueSelect.append(new Option(optionValue, optionValue));
                });
                valueSelect.value = sourceOptions.includes(currentValue) ? currentValue : '';
                valueInput.classList.add('d-none');
                valueSelect.classList.remove('d-none');
                return;
            }

            valueSelect.classList.add('d-none');
            valueSelect.replaceChildren();
            valueInput.classList.remove('d-none');
        }

        function syncIntakeVisibilitySourceOptions(row) {
            const sources = intakeSourceFieldsBefore(row);

            row.querySelectorAll('[data-intake-visibility-source]').forEach(function (select) {
                const currentValue = select.value;
                select.replaceChildren(new Option('Select field', ''));

                sources.forEach(function (source) {
                    const option = new Option(source.label || source.key, source.key);
                    option.dataset.fieldType = source.type;
                    option.dataset.options = JSON.stringify(source.options);
                    select.append(option);
                });

                select.value = sources.some((source) => source.key === currentValue) ? currentValue : '';
                syncIntakeVisibilityRuleControls(select.closest('[data-intake-visibility-rule]'));
            });
        }

        function createIntakeVisibilityRuleRow(row) {
            const fieldIndex = intakeFieldIndex(row);
            const ruleIndex = Date.now().toString();
            const ruleRow = document.createElement('div');
            ruleRow.className = 'row g-2 align-items-end';
            ruleRow.setAttribute('data-intake-visibility-rule', '');
            ruleRow.innerHTML = `
                <div class="col-md-4">
                    <label class="form-label">Field</label>
                    <select name="fields[${fieldIndex}][visibility_rules][${ruleIndex}][source_key]" class="form-select form-select-sm" data-intake-visibility-source>
                        <option value="">Select field</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Operator</label>
                    <select name="fields[${fieldIndex}][visibility_rules][${ruleIndex}][operator]" class="form-select form-select-sm" data-intake-visibility-operator>
                        <option value="has_value">Has value</option>
                        <option value="equals">Equals</option>
                        <option value="not_equals">Does not equal</option>
                        <option value="contains">Contains</option>
                        <option value="checked">Checked</option>
                        <option value="unchecked">Unchecked</option>
                    </select>
                </div>
                <div class="col-md-4 d-none" data-intake-visibility-value-wrap>
                    <label class="form-label">Value</label>
                    <input type="text" name="fields[${fieldIndex}][visibility_rules][${ruleIndex}][value]" class="form-control form-control-sm" data-intake-visibility-value disabled>
                    <select class="form-select form-select-sm d-none mt-1" data-intake-visibility-value-select aria-label="Value"></select>
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-intake-visibility-rule aria-label="Remove condition" title="Remove condition">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                    </button>
                </div>
            `;

            return ruleRow;
        }

        function ensureIntakeVisibilityRule(row) {
            const list = row.querySelector('[data-intake-visibility-rule-list]');

            if (list && ! list.querySelector('[data-intake-visibility-rule]')) {
                list.append(createIntakeVisibilityRuleRow(row));
            }
        }

        function syncIntakeVisibilitySettings(row) {
            if (! row) {
                return;
            }

            const mode = row.querySelector('[data-intake-visibility-mode]')?.value || 'always';
            const isConditional = mode === 'conditional';

            row.querySelector('[data-intake-visibility-match-wrap]')?.classList.toggle('d-none', ! isConditional);
            row.querySelector('[data-intake-visibility-rules]')?.classList.toggle('d-none', ! isConditional);
            row.querySelector('[data-intake-conditional-summary]')?.classList.toggle('d-none', ! isConditional);

            if (isConditional) {
                ensureIntakeVisibilityRule(row);
            }

            syncIntakeVisibilitySourceOptions(row);
        }

        function syncAllIntakeVisibilitySourceOptions() {
            document.querySelectorAll('[data-intake-field-row]').forEach(function (row) {
                syncIntakeVisibilitySettings(row);
            });
        }

        function updateIntakeSubmitPreview(input) {
            document.querySelectorAll('[data-intake-submit-preview]').forEach(function (preview) {
                preview.textContent = input.value.trim() || 'Send inquiry';
            });
        }

        function syncIntakeFieldType(row) {
            if (! row) {
                return;
            }

            const typeSelect = row.querySelector('select[name$="[field_type]"]');
            const selectedType = typeSelect?.value || 'text';
            const isChoiceField = intakeChoiceFieldTypes.includes(selectedType);
            const isFileField = selectedType === 'file';

            row.querySelector('[data-intake-choice-options]')?.classList.toggle('d-none', ! isChoiceField);
            row.querySelector('[data-intake-file-settings]')?.classList.toggle('d-none', ! isFileField);

            if (isChoiceField) {
                ensureIntakeOptionRow(row);
            }
        }

        function updateIntakeCollapseIcon(panel, expanded) {
            if (! panel.id) {
                return;
            }

            const button = document.querySelector('[data-bs-target="#' + panel.id + '"]');
            const icon = button?.querySelector('[data-intake-collapse-icon]');

            icon?.classList.toggle('bi-chevron-right', ! expanded);
            icon?.classList.toggle('bi-chevron-down', expanded);
        }

        document.addEventListener('click', function (event) {
            const addButton = event.target.closest('[data-add-intake-field]');
            const removeButton = event.target.closest('[data-remove-intake-field]');
            const toggleButton = event.target.closest('[data-toggle-intake-field]');
            const settingsButton = event.target.closest('[data-toggle-intake-field-settings]');
            const submitToggleButton = event.target.closest('[data-toggle-intake-submit-row]');
            const addOptionButton = event.target.closest('[data-add-intake-option]');
            const removeOptionButton = event.target.closest('[data-remove-intake-option]');
            const addVisibilityRuleButton = event.target.closest('[data-add-intake-visibility-rule]');
            const removeVisibilityRuleButton = event.target.closest('[data-remove-intake-visibility-rule]');

            if (addButton) {
                const list = document.querySelector('[data-intake-field-list]');
                const template = document.getElementById('intake-field-row-template');
                const emptyState = document.querySelector('[data-intake-field-empty]');
                const index = Date.now().toString();
                list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
                const newRow = list.lastElementChild;
                emptyState?.classList.add('d-none');
                setIntakeFieldLayout(newRow, 12);
                syncIntakeFieldType(newRow);
                syncIntakeVisibilitySettings(newRow);
                syncAllIntakeVisibilitySourceOptions();
                expandIntakeField(newRow);
                newRow?.querySelector('input[name$="[label]"]')?.focus();
            }

            if (removeButton) {
                const list = document.querySelector('[data-intake-field-list]');
                const emptyState = document.querySelector('[data-intake-field-empty]');
                removeButton.closest('[data-intake-field-row]')?.remove();
                emptyState?.classList.toggle('d-none', Boolean(list?.querySelector('[data-intake-field-row]')));
                syncAllIntakeVisibilitySourceOptions();
            }

            if (toggleButton) {
                const row = toggleButton.closest('[data-intake-field-row]');

                if (row) {
                    expandIntakeField(row);
                }
            }

            if (settingsButton) {
                const row = settingsButton.closest('[data-intake-field-row]');
                const settings = row?.querySelector('[data-intake-field-settings]');

                if (row && settings) {
                    expandIntakeField(row);
                    settings.classList.toggle('d-none');
                }
            }

            if (submitToggleButton) {
                expandIntakeSubmit();
            }

            if (addOptionButton) {
                const row = addOptionButton.closest('[data-intake-field-row]');
                const list = row?.querySelector('[data-intake-option-list]');

                if (row && list) {
                    list.append(createIntakeOptionRow());
                    syncIntakeOptions(row);
                    syncAllIntakeVisibilitySourceOptions();
                    list.lastElementChild?.querySelector('[data-intake-option-input]')?.focus();
                }
            }

            if (removeOptionButton) {
                const row = removeOptionButton.closest('[data-intake-field-row]');
                const list = row?.querySelector('[data-intake-option-list]');

                removeOptionButton.closest('[data-intake-option-row]')?.remove();

                if (row && list && ! list.querySelector('[data-intake-option-row]')) {
                    list.append(createIntakeOptionRow());
                }

                if (row) {
                    syncIntakeOptions(row);
                    syncAllIntakeVisibilitySourceOptions();
                }
            }

            if (addVisibilityRuleButton) {
                const row = addVisibilityRuleButton.closest('[data-intake-field-row]');
                const list = row?.querySelector('[data-intake-visibility-rule-list]');

                if (row && list) {
                    const ruleRow = createIntakeVisibilityRuleRow(row);
                    list.append(ruleRow);
                    syncIntakeVisibilitySourceOptions(row);
                    syncIntakeVisibilityRuleControls(ruleRow);
                    ruleRow.querySelector('[data-intake-visibility-source]')?.focus();
                }
            }

            if (removeVisibilityRuleButton) {
                const row = removeVisibilityRuleButton.closest('[data-intake-field-row]');
                removeVisibilityRuleButton.closest('[data-intake-visibility-rule]')?.remove();

                if (row) {
                    ensureIntakeVisibilityRule(row);
                    syncIntakeVisibilitySourceOptions(row);
                }
            }
        });

        document.addEventListener('focusin', function (event) {
            const row = event.target.closest('[data-intake-field-row]');
            const submitRow = event.target.closest('[data-intake-submit-row]');

            if (row && event.target.matches('input, select, textarea')) {
                expandIntakeField(row);
            }

            if (submitRow && event.target.matches('input, select, textarea')) {
                expandIntakeSubmit();
            }
        });

        document.addEventListener('input', function (event) {
            if (event.target.name?.endsWith('[label]')) {
                updateIntakeFieldSummary(event.target);
                syncAllIntakeVisibilitySourceOptions();
            }

            if (event.target.name?.endsWith('[key]')) {
                syncAllIntakeVisibilitySourceOptions();
            }

            if (event.target.matches('#submit_button_label')) {
                updateIntakeSubmitPreview(event.target);
            }

            if (event.target.matches('[data-intake-option-input]')) {
                const row = event.target.closest('[data-intake-field-row]');

                if (row) {
                    syncIntakeOptions(row);
                    syncAllIntakeVisibilitySourceOptions();
                }
            }
        });

        document.addEventListener('change', function (event) {
            if (event.target.name?.endsWith('[field_type]')) {
                updateIntakeFieldTypeSummary(event.target);
                syncIntakeFieldType(event.target.closest('[data-intake-field-row]'));
                syncAllIntakeVisibilitySourceOptions();
            }

            if (event.target.name?.endsWith('[is_required]')) {
                updateIntakeRequiredSummary(event.target);
            }

            if (event.target.matches('[data-intake-layout-width]')) {
                const row = event.target.closest('[data-intake-field-row]');

                if (row) {
                    setIntakeFieldLayout(row, event.target.value);
                }
            }

            if (event.target.matches('[data-intake-visibility-mode]')) {
                syncIntakeVisibilitySettings(event.target.closest('[data-intake-field-row]'));
            }

            if (event.target.matches('[data-intake-visibility-source], [data-intake-visibility-operator]')) {
                syncIntakeVisibilityRuleControls(event.target.closest('[data-intake-visibility-rule]'));
            }

            if (event.target.matches('[data-intake-visibility-value-select]')) {
                const ruleRow = event.target.closest('[data-intake-visibility-rule]');
                const valueInput = ruleRow?.querySelector('[data-intake-visibility-value]');

                if (valueInput) {
                    valueInput.value = event.target.value;
                }
            }
        });

        document.addEventListener('mousedown', function (event) {
            intakeDragHandleActive = Boolean(event.target.closest('[data-intake-drag-handle]'));
        });

        document.addEventListener('mouseup', function () {
            intakeDragHandleActive = false;
        });

        document.addEventListener('dragstart', function (event) {
            const row = event.target.closest('[data-intake-field-row]');

            if (! row || ! intakeDragHandleActive) {
                event.preventDefault();
                return;
            }

            draggedIntakeField = row;
            row.classList.add('opacity-50');
            event.dataTransfer.effectAllowed = 'move';
        });

        document.addEventListener('dragover', function (event) {
            const list = event.target.closest('[data-intake-field-list]');

            if (! list || ! draggedIntakeField) {
                return;
            }

            event.preventDefault();
            const afterElement = closestIntakeFieldAfter(list, event.clientX, event.clientY);

            if (afterElement) {
                list.insertBefore(draggedIntakeField, afterElement);
            } else {
                list.appendChild(draggedIntakeField);
            }
        });

        document.addEventListener('dragend', function () {
            draggedIntakeField?.classList.remove('opacity-50');
            draggedIntakeField = null;
            intakeDragHandleActive = false;
            syncAllIntakeVisibilitySourceOptions();
        });

        document.addEventListener('shown.bs.collapse', function (event) {
            updateIntakeCollapseIcon(event.target, true);
        });

        document.addEventListener('hidden.bs.collapse', function (event) {
            updateIntakeCollapseIcon(event.target, false);
        });

        document.querySelectorAll('[data-intake-field-row]').forEach(function (row) {
            syncIntakeFieldType(row);
            syncIntakeVisibilitySettings(row);
        });

        syncAllIntakeVisibilitySourceOptions();
    </script>
@endsection
