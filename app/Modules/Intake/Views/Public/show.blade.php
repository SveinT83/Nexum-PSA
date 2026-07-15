@extends('intake::layouts.public')

@section('title', $form->name)
@section('eyebrow', 'Public inquiry')

@php
    $hasFiles = $fields->contains(fn ($field) => $field->isFileField());
@endphp

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Public Intake Form -->
    <!-- ------------------------------------------------- -->
    <div class="card shadow-sm">
        <div class="card-header bg-body">
            <h1 class="h4 mb-0">{{ $form->name }}</h1>
        </div>
        <div class="card-body">
            @if($form->description)
                <p class="text-muted mb-4">{{ $form->description }}</p>
            @endif

            <form method="POST" action="{{ route('intake.forms.submit', $form) }}" @if($hasFiles) enctype="multipart/form-data" @endif>
                @csrf

                <div class="intake-honeypot" aria-hidden="true">
                    <label for="{{ $form->spam_honeypot_field }}">Website</label>
                    <input type="text" id="{{ $form->spam_honeypot_field }}" name="{{ $form->spam_honeypot_field }}" value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="row g-3 mb-3">
                    @foreach($fields as $field)
                        @php
                            $fieldName = 'fields['.$field->key.']';
                            $fieldId = 'intake_field_'.$field->key;
                            $errorKey = $field->isFileField() ? 'files.'.$field->key : 'fields.'.$field->key;
                            $oldValue = old('fields.'.$field->key);
                            $visibility = $field->visibility();
                        @endphp

                        <div class="{{ $field->layoutColumnClass() }}" data-intake-public-field data-intake-field-key="{{ $field->key }}" data-intake-field-required="{{ $field->is_required ? '1' : '0' }}" data-intake-field-visibility='@json($visibility)'>
                            @if($field->field_type !== \App\Modules\Intake\Models\IntakeFormField::TYPE_CONSENT)
                                <label for="{{ $fieldId }}" class="form-label">
                                    {{ $field->label }}
                                    @if($field->is_required)<span class="text-danger">*</span>@endif
                                </label>
                            @endif

                            @switch($field->field_type)
                                @case(\App\Modules\Intake\Models\IntakeFormField::TYPE_TEXTAREA)
                                    <textarea id="{{ $fieldId }}" name="{{ $fieldName }}" rows="5" class="form-control @error($errorKey) is-invalid @enderror" placeholder="{{ $field->placeholder }}" @if($field->is_required) required @endif>{{ $oldValue }}</textarea>
                                    @break

                                @case(\App\Modules\Intake\Models\IntakeFormField::TYPE_EMAIL)
                                    <input type="email" id="{{ $fieldId }}" name="{{ $fieldName }}" class="form-control @error($errorKey) is-invalid @enderror" value="{{ $oldValue }}" placeholder="{{ $field->placeholder }}" @if($field->is_required) required @endif>
                                    @break

                                @case(\App\Modules\Intake\Models\IntakeFormField::TYPE_PHONE)
                                    <input type="tel" id="{{ $fieldId }}" name="{{ $fieldName }}" class="form-control @error($errorKey) is-invalid @enderror" value="{{ $oldValue }}" placeholder="{{ $field->placeholder }}" @if($field->is_required) required @endif>
                                    @break

                                @case(\App\Modules\Intake\Models\IntakeFormField::TYPE_URL)
                                    <input type="url" id="{{ $fieldId }}" name="{{ $fieldName }}" class="form-control @error($errorKey) is-invalid @enderror" value="{{ $oldValue }}" placeholder="{{ $field->placeholder }}" @if($field->is_required) required @endif>
                                    @break

                                @case(\App\Modules\Intake\Models\IntakeFormField::TYPE_SELECT)
                                    <select id="{{ $fieldId }}" name="{{ $fieldName }}" class="form-select @error($errorKey) is-invalid @enderror" @if($field->is_required) required @endif>
                                        <option value="">Select</option>
                                        @foreach($field->options ?: [] as $option)
                                            @php($value = is_array($option) ? ($option['value'] ?? $option['label']) : $option)
                                            <option value="{{ $value }}" @selected($oldValue === $value)>{{ is_array($option) ? ($option['label'] ?? $value) : $option }}</option>
                                        @endforeach
                                    </select>
                                    @break

                                @case(\App\Modules\Intake\Models\IntakeFormField::TYPE_MULTISELECT)
                                    <select id="{{ $fieldId }}" name="{{ $fieldName }}[]" class="form-select @error($errorKey) is-invalid @enderror" multiple @if($field->is_required) required @endif>
                                        @foreach($field->options ?: [] as $option)
                                            @php($value = is_array($option) ? ($option['value'] ?? $option['label']) : $option)
                                            <option value="{{ $value }}" @selected(in_array($value, (array) $oldValue, true))>{{ is_array($option) ? ($option['label'] ?? $value) : $option }}</option>
                                        @endforeach
                                    </select>
                                    @break

                                @case(\App\Modules\Intake\Models\IntakeFormField::TYPE_CHECKBOX)
                                @case(\App\Modules\Intake\Models\IntakeFormField::TYPE_CONSENT)
                                    <div class="form-check">
                                        <input type="checkbox" id="{{ $fieldId }}" name="{{ $fieldName }}" value="1" class="form-check-input @error($errorKey) is-invalid @enderror" @checked(old('fields.'.$field->key)) @if($field->is_required) required @endif>
                                        <label for="{{ $fieldId }}" class="form-check-label">
                                            {{ $field->label }}
                                            @if($field->is_required)<span class="text-danger">*</span>@endif
                                        </label>
                                    </div>
                                    @break

                                @case(\App\Modules\Intake\Models\IntakeFormField::TYPE_FILE)
                                    <input type="file" id="{{ $fieldId }}" name="files[{{ $field->key }}][]" class="form-control @error($errorKey) is-invalid @enderror" @if($field->maxFiles() > 1) multiple @endif @if($field->is_required) required @endif accept="{{ implode(',', $field->allowedMimeTypes()) }}">
                                    <div class="form-text">{{ $field->maxFiles() }} file(s), max {{ number_format($field->maxFileSizeKb() / 1024, 0) }} MB each.</div>
                                    @break

                                @default
                                    <input type="text" id="{{ $fieldId }}" name="{{ $fieldName }}" class="form-control @error($errorKey) is-invalid @enderror" value="{{ $oldValue }}" placeholder="{{ $field->placeholder }}" @if($field->is_required) required @endif>
                            @endswitch

                            @if($field->help_text)
                                <div class="form-text">{{ $field->help_text }}</div>
                            @endif

                            @error($errorKey)
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    @endforeach
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send" aria-hidden="true"></i>
                        {{ $form->submitButtonLabel() }}
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        const intakePublicFieldRows = Array.from(document.querySelectorAll('[data-intake-public-field]'));

        function intakePublicControls(row) {
            return Array.from(row.querySelectorAll('input, select, textarea'));
        }

        function intakePublicFieldRow(key) {
            return intakePublicFieldRows.find((row) => row.dataset.intakeFieldKey === key);
        }

        function intakePublicValues(key) {
            const row = intakePublicFieldRow(key);

            if (! row || row.classList.contains('d-none')) {
                return [];
            }

            const values = [];

            intakePublicControls(row).forEach(function (control) {
                if (control.disabled) {
                    return;
                }

                if (control.type === 'checkbox') {
                    if (control.checked) {
                        values.push(control.value || '1');
                    }

                    return;
                }

                if (control.type === 'file') {
                    if (control.files.length > 0) {
                        values.push('1');
                    }

                    return;
                }

                if (control.tagName === 'SELECT' && control.multiple) {
                    Array.from(control.selectedOptions).forEach(function (option) {
                        if (option.value.trim() !== '') {
                            values.push(option.value.trim());
                        }
                    });

                    return;
                }

                if ((control.value || '').trim() !== '') {
                    values.push(control.value.trim());
                }
            });

            return values;
        }

        function intakePublicSourceUsesMultipleSelect(row) {
            return intakePublicControls(row).some(function (control) {
                return control.tagName === 'SELECT' && control.multiple;
            });
        }

        function intakePublicRuleMatches(rule) {
            const sourceRow = intakePublicFieldRow(rule.source_key);

            if (! sourceRow || sourceRow.classList.contains('d-none')) {
                return false;
            }

            const values = intakePublicValues(rule.source_key);
            const expected = String(rule.value || '');

            if (rule.operator === 'has_value') {
                return values.length > 0;
            }

            if (rule.operator === 'equals') {
                return values.includes(expected);
            }

            if (rule.operator === 'not_equals') {
                return values.length > 0 && ! values.includes(expected);
            }

            if (rule.operator === 'contains') {
                if (expected === '') {
                    return false;
                }

                return intakePublicSourceUsesMultipleSelect(sourceRow)
                    ? values.includes(expected)
                    : values.some((value) => value.includes(expected));
            }

            if (rule.operator === 'checked') {
                return values.length > 0;
            }

            if (rule.operator === 'unchecked') {
                return values.length === 0;
            }

            return false;
        }

        function intakePublicFieldVisible(row) {
            let visibility = {};

            try {
                visibility = JSON.parse(row.dataset.intakeFieldVisibility || '{}');
            } catch (error) {
                visibility = {};
            }

            if (visibility.mode !== 'conditional' || ! Array.isArray(visibility.rules) || visibility.rules.length === 0) {
                return true;
            }

            const results = visibility.rules.map((rule) => intakePublicRuleMatches(rule));

            return visibility.match === 'any'
                ? results.some(Boolean)
                : results.every(Boolean);
        }

        function intakeApplyPublicVisibility() {
            intakePublicFieldRows.forEach(function (row) {
                const visible = intakePublicFieldVisible(row);

                row.classList.toggle('d-none', ! visible);

                intakePublicControls(row).forEach(function (control) {
                    if (! control.dataset.intakeRequiredManaged) {
                        control.dataset.intakeRequiredManaged = '1';
                        control.dataset.intakeWasRequired = control.required ? '1' : '0';
                    }

                    control.disabled = ! visible;
                    control.required = visible && control.dataset.intakeWasRequired === '1';
                });
            });
        }

        document.addEventListener('input', intakeApplyPublicVisibility);
        document.addEventListener('change', intakeApplyPublicVisibility);
        intakeApplyPublicVisibility();
    </script>
@endsection
