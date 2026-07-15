@php
    $fieldType = $row['field_type'] ?? \App\Modules\Intake\Models\IntakeFormField::TYPE_TEXT;
    $mapsTo = $row['maps_to'] ?? null;
    $safeIndex = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $index);
    $fieldPanelId = 'intake_field_'.$safeIndex.'_panel';
    $fieldSummaryId = 'intake_field_'.$safeIndex.'_summary';
    $fieldLabel = trim((string) ($row['label'] ?? '')) ?: 'New field';
    $fieldTypeLabel = ucfirst(str_replace('_', ' ', $fieldType));
    $layoutWidths = [
        12 => 'Full',
        6 => 'Half',
        4 => 'Third',
        3 => 'Quarter',
    ];
    $visibilityMode = $row['visibility_mode'] ?? \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MODE_ALWAYS;
    $visibilityMatch = $row['visibility_match'] ?? \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MATCH_ALL;
    $visibilityRules = array_values(array_filter($row['visibility_rules'] ?? [], fn ($rule): bool => is_array($rule)));
    $visibilityOperatorLabels = [
        \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_OPERATOR_HAS_VALUE => 'Has value',
        \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_OPERATOR_EQUALS => 'Equals',
        \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_OPERATOR_NOT_EQUALS => 'Does not equal',
        \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_OPERATOR_CONTAINS => 'Contains',
        \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_OPERATOR_CHECKED => 'Checked',
        \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_OPERATOR_UNCHECKED => 'Unchecked',
    ];

    if (! in_array($visibilityMode, \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MODES, true)) {
        $visibilityMode = \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MODE_ALWAYS;
    }

    if (! in_array($visibilityMatch, \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MATCH_MODES, true)) {
        $visibilityMatch = \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MATCH_ALL;
    }

    if ($visibilityRules === []) {
        $visibilityRules = [[
            'source_key' => '',
            'operator' => \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_OPERATOR_HAS_VALUE,
            'value' => '',
        ]];
    }

    $isConditionalVisibility = $visibilityMode === \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MODE_CONDITIONAL;
    $sourceFields = [];

    foreach (($allRows ?? []) as $sourceIndex => $sourceRow) {
        if ((string) $sourceIndex === (string) $index) {
            break;
        }

        $sourceKey = trim((string) ($sourceRow['key'] ?? ''));

        if ($sourceKey === '') {
            continue;
        }

        $sourceFields[] = [
            'key' => $sourceKey,
            'label' => trim((string) ($sourceRow['label'] ?? '')) ?: $sourceKey,
            'field_type' => (string) ($sourceRow['field_type'] ?? \App\Modules\Intake\Models\IntakeFormField::TYPE_TEXT),
            'options_text' => (string) ($sourceRow['options_text'] ?? ''),
        ];
    }
    $layoutWidth = (int) ($row['layout_width'] ?? 12);

    if (! array_key_exists($layoutWidth, $layoutWidths)) {
        $layoutWidth = 12;
    }

    $choiceFieldTypes = [
        \App\Modules\Intake\Models\IntakeFormField::TYPE_SELECT,
        \App\Modules\Intake\Models\IntakeFormField::TYPE_MULTISELECT,
    ];
    $isChoiceField = in_array($fieldType, $choiceFieldTypes, true);
    $isFileField = $fieldType === \App\Modules\Intake\Models\IntakeFormField::TYPE_FILE;
    $optionRows = array_values(array_filter(
        array_map('trim', preg_split('/\R+/', (string) ($row['options_text'] ?? '')) ?: []),
        fn (string $option): bool => $option !== ''
    ));

    if ($optionRows === []) {
        $optionRows = [''];
    }
@endphp

<div class="col-12 col-md-{{ $layoutWidth }}" data-intake-field-row data-layout-width="{{ $layoutWidth }}" draggable="true">
    <input type="hidden" name="fields[{{ $index }}][id]" value="{{ $row['id'] ?? '' }}">

    <div class="border rounded bg-body" data-intake-field-card>
        <div class="d-flex align-items-center gap-2 p-2 bg-body-tertiary border-bottom">
            <button type="button" class="btn btn-sm btn-light text-muted px-2" data-intake-drag-handle aria-label="Drag field" title="Drag field">
                <i class="bi bi-grip-vertical" aria-hidden="true"></i>
            </button>
            <button type="button" id="{{ $fieldSummaryId }}" class="btn btn-sm flex-grow-1 d-flex align-items-center gap-2 text-start px-2 py-1" data-toggle-intake-field aria-expanded="false" aria-controls="{{ $fieldPanelId }}">
                <i class="bi bi-chevron-right text-muted" aria-hidden="true" data-intake-field-chevron></i>
                <span class="fw-semibold text-truncate" data-intake-field-summary>{{ $fieldLabel }}</span>
                <span class="badge text-bg-light border ms-auto" data-intake-field-type-summary>{{ $fieldTypeLabel }}</span>
                <span class="badge text-bg-danger border {{ ($row['is_required'] ?? false) ? '' : 'd-none' }}" data-intake-required-summary>Required</span>
                <span class="badge text-bg-warning border {{ $isConditionalVisibility ? '' : 'd-none' }}" data-intake-conditional-summary>Conditional</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle-intake-field-settings aria-label="Field settings" title="Field settings">
                <i class="bi bi-gear" aria-hidden="true"></i>
            </button>
        </div>

        <div id="{{ $fieldPanelId }}" class="p-3 d-none" data-intake-field-panel aria-labelledby="{{ $fieldSummaryId }}">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Label</label>
                    <input type="text" name="fields[{{ $index }}][label]" class="form-control form-control-sm" value="{{ $row['label'] ?? '' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Key</label>
                    <input type="text" name="fields[{{ $index }}][key]" class="form-control form-control-sm" value="{{ $row['key'] ?? '' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Type</label>
                    <select name="fields[{{ $index }}][field_type]" class="form-select form-select-sm">
                        @foreach($fieldTypes as $type)
                            <option value="{{ $type }}" @selected($fieldType === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Maps to</label>
                    <select name="fields[{{ $index }}][maps_to]" class="form-select form-select-sm">
                        <option value="">None</option>
                        @foreach($mapTargets as $target)
                            <option value="{{ $target }}" @selected($mapsTo === $target)>{{ ucfirst(str_replace('_', ' ', $target)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Placeholder</label>
                    <input type="text" name="fields[{{ $index }}][placeholder]" class="form-control form-control-sm" value="{{ $row['placeholder'] ?? '' }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Help text</label>
                    <input type="text" name="fields[{{ $index }}][help_text]" class="form-control form-control-sm" value="{{ $row['help_text'] ?? '' }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Layout width</label>
                    <select name="fields[{{ $index }}][layout_width]" class="form-select form-select-sm" data-intake-layout-width>
                        @foreach($layoutWidths as $width => $label)
                            <option value="{{ $width }}" @selected($layoutWidth === $width)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 d-none" data-intake-field-settings>
                    <div class="border rounded bg-body-tertiary p-3">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label">Visibility</label>
                                <select name="fields[{{ $index }}][visibility_mode]" class="form-select form-select-sm" data-intake-visibility-mode>
                                    <option value="{{ \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MODE_ALWAYS }}" @selected(! $isConditionalVisibility)>Always visible</option>
                                    <option value="{{ \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MODE_CONDITIONAL }}" @selected($isConditionalVisibility)>Show when conditions match</option>
                                </select>
                            </div>
                            <div class="col-md-4 {{ $isConditionalVisibility ? '' : 'd-none' }}" data-intake-visibility-match-wrap>
                                <label class="form-label">Match</label>
                                <select name="fields[{{ $index }}][visibility_match]" class="form-select form-select-sm">
                                    <option value="{{ \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MATCH_ALL }}" @selected($visibilityMatch === \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MATCH_ALL)>All conditions</option>
                                    <option value="{{ \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MATCH_ANY }}" @selected($visibilityMatch === \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_MATCH_ANY)>Any condition</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-3 {{ $isConditionalVisibility ? '' : 'd-none' }}" data-intake-visibility-rules>
                            <div class="vstack gap-2" data-intake-visibility-rule-list>
                                @foreach($visibilityRules as $ruleIndex => $rule)
                                    @php
                                        $ruleOperator = $rule['operator'] ?? \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_OPERATOR_HAS_VALUE;

                                        if (! array_key_exists($ruleOperator, $visibilityOperatorLabels)) {
                                            $ruleOperator = \App\Modules\Intake\Models\IntakeFormField::VISIBILITY_OPERATOR_HAS_VALUE;
                                        }
                                    @endphp
                                    <div class="row g-2 align-items-end" data-intake-visibility-rule>
                                        <div class="col-md-4">
                                            <label class="form-label">Field</label>
                                            <select name="fields[{{ $index }}][visibility_rules][{{ $ruleIndex }}][source_key]" class="form-select form-select-sm" data-intake-visibility-source>
                                                <option value="">Select field</option>
                                                @foreach($sourceFields as $sourceField)
                                                    @php
                                                        $sourceOptions = array_values(array_filter(
                                                            array_map('trim', preg_split('/\R+/', $sourceField['options_text']) ?: []),
                                                            fn (string $option): bool => $option !== ''
                                                        ));
                                                    @endphp
                                                    <option value="{{ $sourceField['key'] }}" data-field-type="{{ $sourceField['field_type'] }}" data-options='@json($sourceOptions)' @selected(($rule['source_key'] ?? '') === $sourceField['key'])>{{ $sourceField['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Operator</label>
                                            <select name="fields[{{ $index }}][visibility_rules][{{ $ruleIndex }}][operator]" class="form-select form-select-sm" data-intake-visibility-operator>
                                                @foreach($visibilityOperatorLabels as $operator => $label)
                                                    <option value="{{ $operator }}" @selected($ruleOperator === $operator)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-4" data-intake-visibility-value-wrap>
                                            <label class="form-label">Value</label>
                                            <input type="text" name="fields[{{ $index }}][visibility_rules][{{ $ruleIndex }}][value]" class="form-control form-control-sm" value="{{ $rule['value'] ?? '' }}" data-intake-visibility-value>
                                            <select class="form-select form-select-sm d-none mt-1" data-intake-visibility-value-select aria-label="Value"></select>
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-intake-visibility-rule aria-label="Remove condition" title="Remove condition">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-add-intake-visibility-rule aria-label="Add condition" title="Add condition">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-12 {{ $isChoiceField ? '' : 'd-none' }}" data-intake-choice-options>
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <label class="form-label mb-0">Options</label>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-add-intake-option aria-label="Add option" title="Add option">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                    <textarea name="fields[{{ $index }}][options_text]" class="d-none" data-intake-options-text>{{ $row['options_text'] ?? '' }}</textarea>
                    <div class="vstack gap-2" data-intake-option-list>
                        @foreach($optionRows as $option)
                            <div class="input-group input-group-sm" data-intake-option-row>
                                <input type="text" class="form-control" value="{{ $option }}" placeholder="Option" data-intake-option-input>
                                <button type="button" class="btn btn-outline-danger" data-remove-intake-option aria-label="Remove option" title="Remove option">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="col-12 {{ $isFileField ? '' : 'd-none' }}" data-intake-file-settings>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label">Max files</label>
                            <input type="number" min="0" max="20" name="fields[{{ $index }}][max_files]" class="form-control form-control-sm" value="{{ $row['max_files'] ?? '' }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">File size KB</label>
                            <input type="number" min="1" max="51200" name="fields[{{ $index }}][max_file_size_kb]" class="form-control form-control-sm" value="{{ $row['max_file_size_kb'] ?? '' }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Allowed MIME types</label>
                            <textarea name="fields[{{ $index }}][allowed_mime_types_text]" rows="3" class="form-control form-control-sm">{{ $row['allowed_mime_types_text'] ?? '' }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 border-top pt-3 mt-1">
                        <div class="d-flex flex-wrap align-items-center gap-4">
                            <div class="form-check form-switch mb-0">
                                <input type="checkbox" role="switch" id="field_{{ $index }}_required" name="fields[{{ $index }}][is_required]" value="1" class="form-check-input" @checked($row['is_required'] ?? false)>
                                <label for="field_{{ $index }}_required" class="form-check-label">Required</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input type="checkbox" role="switch" id="field_{{ $index }}_active" name="fields[{{ $index }}][is_active]" value="1" class="form-check-input" @checked($row['is_active'] ?? true)>
                                <label for="field_{{ $index }}_active" class="form-check-label">Visible on form</label>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-remove-intake-field aria-label="Remove field" title="Remove field">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
