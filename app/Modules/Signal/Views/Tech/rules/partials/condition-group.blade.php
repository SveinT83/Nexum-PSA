<div class="border rounded bg-body" data-signal-condition-group data-group-index="{{ $groupIndex }}">
    <div class="d-flex flex-wrap align-items-center gap-2 p-2 bg-body-tertiary border-bottom">
        <span class="fw-semibold small" data-signal-group-title>Condition group {{ is_numeric($groupIndex) ? ((int) $groupIndex + 1) : '' }}</span>
        <select name="conditions[groups][{{ $groupIndex }}][match]" class="form-select form-select-sm w-auto ms-auto" aria-label="Condition matching">
            <option value="all" @selected(($group['match'] ?? 'all') === 'all')>All conditions</option>
            <option value="any" @selected(($group['match'] ?? 'all') === 'any')>At least one condition</option>
        </select>
        <button type="button" class="btn btn-sm btn-outline-danger" data-remove-signal-group aria-label="Remove group" title="Remove group">
            <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
    </div>
    <div class="p-2">
        <div class="vstack gap-2" data-signal-condition-list>
            @foreach(($group['conditions'] ?? []) as $conditionIndex => $row)
                @include('signal::Tech.rules.partials.condition-row', compact('definition', 'groupIndex', 'conditionIndex', 'row'))
            @endforeach
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-add-signal-condition>
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            Add condition
        </button>
    </div>
</div>
