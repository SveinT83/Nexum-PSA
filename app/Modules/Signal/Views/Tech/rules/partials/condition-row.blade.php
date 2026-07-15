@php
    $field = $row['field'] ?? '';
    $operator = $row['operator'] ?? 'equals';
    $prefix = "conditions[groups][{$groupIndex}][conditions][{$conditionIndex}]";
@endphp

<div class="border rounded bg-body p-2" data-signal-condition-row>
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small">Field</label>
            <select name="{{ $prefix }}[field]" class="form-select form-select-sm" data-signal-condition-field>
                <option value="">Select field</option>
                @foreach($definition::BUILDER_CONDITION_FIELDS as $value => $label)
                    <option value="{{ $value }}" @selected($field === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3 {{ $field === 'payload' ? '' : 'd-none' }}" data-signal-condition-path-wrap>
            <label class="form-label small">Payload path</label>
            <input type="text" name="{{ $prefix }}[path]" class="form-control form-control-sm" value="{{ $row['path'] ?? '' }}" placeholder="vendor or device.name">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Operator</label>
            <select name="{{ $prefix }}[operator]" class="form-select form-select-sm" data-signal-condition-operator data-current-operator="{{ $operator }}">
                @foreach($definition::CONDITION_OPERATORS as $value => $label)
                    <option value="{{ $value }}" @selected($operator === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col {{ in_array($operator, ['is_true', 'is_false', 'exists', 'missing'], true) ? 'd-none' : '' }}" data-signal-condition-value-wrap>
            <label class="form-label small">Value</label>
            <input type="text" name="{{ $prefix }}[value]" class="form-control form-control-sm" value="{{ $row['value'] ?? '' }}" placeholder="Use commas for multiple values">
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-sm btn-outline-danger" data-remove-signal-condition aria-label="Remove condition" title="Remove condition">
                <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</div>
