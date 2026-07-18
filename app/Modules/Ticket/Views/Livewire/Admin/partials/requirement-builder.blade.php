@php
    $secondaryArgument = $secondary !== null ? "'".$secondary."'" : 'null';
    $operatorLabels = [
        'is_true' => 'must be true',
        'is_false' => 'must be false',
        'equals' => 'equals',
        'not_equals' => 'does not equal',
        'contains' => 'contains',
        'present' => 'is filled',
        'not_present' => 'is empty',
        'greater_or_equal' => 'is at least',
        'less_or_equal' => 'is at most',
        'gte' => 'is at least',
        'lte' => 'is at most',
    ];
    $requirementCatalogForBrowser = collect($requirementCatalog)->mapWithKeys(
        fn (array $fact, string $factKey) => [$factKey => [
            'operators' => array_values(array_unique($fact['operators'] ?? array_keys($operatorLabels))),
            'value_type' => $fact['value_type'] ?? 'text',
        ]],
    )->all();
@endphp

<div class="border rounded bg-light p-2">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <label class="small fw-semibold mb-0">Requirement groups</label>
        <div class="d-flex align-items-center gap-2">
            <span class="small text-muted">Between groups:</span>
            <select name="{{ $nameBase }}[match]" wire:model="{{ $wireBase }}.match" class="form-select form-select-sm" style="width: auto;">
                <option value="all">All groups</option>
                <option value="any">At least one group</option>
            </select>
            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="addRequirementGroup('{{ $scope }}', {{ $primary }}, {{ $secondaryArgument }})">Add group</button>
        </div>
    </div>

    @forelse($tree['groups'] as $groupIndex => $group)
        <div class="card mb-2" wire:key="{{ $wireBase }}-group-{{ $groupIndex }}">
            <div class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge text-bg-secondary">Group {{ $groupIndex + 1 }}</span>
                    <select name="{{ $nameBase }}[groups][{{ $groupIndex }}][match]" wire:model="{{ $wireBase }}.groups.{{ $groupIndex }}.match" class="form-select form-select-sm" style="width: auto;">
                        <option value="all">Require all</option>
                        <option value="any">Require at least one</option>
                    </select>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeRequirementGroup('{{ $scope }}', {{ $primary }}, {{ $groupIndex }}, {{ $secondaryArgument }})">Remove group</button>
            </div>
            <div class="card-body p-2">
                @foreach($group['conditions'] as $conditionIndex => $condition)
                    <div class="row g-2 align-items-center mb-2" wire:key="{{ $wireBase }}-group-{{ $groupIndex }}-condition-{{ $conditionIndex }}"
                         x-data="{ fact: @js($condition['fact']), operator: @js($condition['operator']), catalog: @js($requirementCatalogForBrowser), get operators() { return this.catalog[this.fact]?.operators ?? []; }, get valueType() { return this.catalog[this.fact]?.value_type ?? 'text'; }, normalizeFact() { if (!this.operators.includes(this.operator)) { this.operator = this.operators[0] ?? 'is_true'; } this.$nextTick(() => { this.$refs.operator.dispatchEvent(new Event('change', { bubbles: true })); if (this.valueType === 'none') { this.$refs.value.value = ''; this.$refs.value.dispatchEvent(new Event('input', { bubbles: true })); } }); } }">
                        <div class="col-lg-5">
                            <select name="{{ $nameBase }}[groups][{{ $groupIndex }}][conditions][{{ $conditionIndex }}][fact]" wire:model="{{ $wireBase }}.groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.fact" x-model="fact" x-on:change="normalizeFact()" class="form-select form-select-sm">
                                @foreach($requirementCatalog as $factKey => $fact)
                                    <option value="{{ $factKey }}">{{ $fact['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <select name="{{ $nameBase }}[groups][{{ $groupIndex }}][conditions][{{ $conditionIndex }}][operator]" wire:model="{{ $wireBase }}.groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.operator" x-ref="operator" x-model="operator" class="form-select form-select-sm">
                                @foreach($operatorLabels as $operator => $operatorLabel)
                                    <option value="{{ $operator }}" x-bind:disabled="!operators.includes(@js($operator))" x-show="operators.includes(@js($operator))">{{ $operatorLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <input name="{{ $nameBase }}[groups][{{ $groupIndex }}][conditions][{{ $conditionIndex }}][value]" wire:model="{{ $wireBase }}.groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.value" x-ref="value" x-show="valueType !== 'none'" x-bind:disabled="valueType === 'none'" class="form-control form-control-sm" placeholder="Value">
                            <span class="small text-muted" x-show="valueType === 'none'">No value needed</span>
                            <input type="hidden" name="{{ $nameBase }}[groups][{{ $groupIndex }}][conditions][{{ $conditionIndex }}][schema_version]" value="1">
                        </div>
                        <div class="col-lg-1 text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Remove requirement" wire:click="removeRequirementCondition('{{ $scope }}', {{ $primary }}, {{ $groupIndex }}, {{ $conditionIndex }}, {{ $secondaryArgument }})">×</button>
                        </div>
                    </div>
                @endforeach
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="addRequirementCondition('{{ $scope }}', {{ $primary }}, {{ $groupIndex }}, {{ $secondaryArgument }})">Add requirement to group</button>
            </div>
        </div>
    @empty
        <p class="small text-muted mb-0">No requirements. This part is available without an additional workflow gate.</p>
    @endforelse
</div>
