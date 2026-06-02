@php
    $definition = $field['definition'];
    $value = old($inputName, $field['value']);
    $inputClasses = 'form-control';
@endphp

@if($definition->field_type === \App\Modules\CustomField\Models\CustomFieldDefinition::TYPE_TEXTAREA)
    <textarea name="{{ $inputName }}" id="{{ $inputId }}" rows="4" class="{{ $inputClasses }}">{{ is_array($value) ? implode(', ', $value) : $value }}</textarea>
@elseif($definition->field_type === \App\Modules\CustomField\Models\CustomFieldDefinition::TYPE_SELECT)
    <select name="{{ $inputName }}" id="{{ $inputId }}" class="form-select">
        <option value="">—</option>
        @foreach($field['options'] as $option)
            <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
        @endforeach
    </select>
@elseif($definition->field_type === \App\Modules\CustomField\Models\CustomFieldDefinition::TYPE_MULTISELECT)
    @php $selectedValues = is_array($value) ? $value : array_filter(explode(',', (string) $value)); @endphp
    <select name="{{ $inputName }}[]" id="{{ $inputId }}" class="form-select" multiple>
        @foreach($field['options'] as $option)
            <option value="{{ $option }}" @selected(in_array($option, $selectedValues, true))>{{ $option }}</option>
        @endforeach
    </select>
@elseif($definition->field_type === \App\Modules\CustomField\Models\CustomFieldDefinition::TYPE_CHECKBOX)
    <input type="hidden" name="{{ $inputName }}" value="0">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="{{ $inputName }}" value="1" id="{{ $inputId }}" @checked((bool) $value)>
        <label class="form-check-label" for="{{ $inputId }}">Enabled</label>
    </div>
@else
    @php
        $type = match ($definition->field_type) {
            \App\Modules\CustomField\Models\CustomFieldDefinition::TYPE_NUMBER => 'number',
            \App\Modules\CustomField\Models\CustomFieldDefinition::TYPE_DATE => 'date',
            \App\Modules\CustomField\Models\CustomFieldDefinition::TYPE_DATETIME => 'datetime-local',
            \App\Modules\CustomField\Models\CustomFieldDefinition::TYPE_EMAIL => 'email',
            \App\Modules\CustomField\Models\CustomFieldDefinition::TYPE_URL => 'url',
            default => 'text',
        };
    @endphp
    <input type="{{ $type }}" name="{{ $inputName }}" id="{{ $inputId }}" value="{{ is_array($value) ? implode(', ', $value) : $value }}" class="{{ $inputClasses }}">
@endif

@if(filled($field['help_text']))
    <div class="form-text">{{ $field['help_text'] }}</div>
@endif
