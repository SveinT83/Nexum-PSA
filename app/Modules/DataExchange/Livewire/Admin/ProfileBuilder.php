<?php

namespace App\Modules\DataExchange\Livewire\Admin;

use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Support\DataExchangeFieldDefinition;
use App\Modules\DataExchange\Support\DataExchangeSourceRegistry;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ProfileBuilder extends Component
{
    public ?int $profileId = null;
    public string $name = '';
    public string $key = '';
    public string $direction = DataExchangeProfile::DIRECTION_EXPORT;
    public string $format = 'csv';
    public string $status = DataExchangeProfile::STATUS_DRAFT;
    public ?string $description = null;
    public ?string $sourceKey = null;
    public array $selectedFields = [];
    public array $fieldLabels = [];
    public array $fieldOutputKeys = [];
    public array $filters = [];

    public function mount(?DataExchangeProfile $profile = null): void
    {
        if ($profile?->exists) {
            $profile->load(['sources', 'fields', 'filters']);
            $this->profileId = $profile->id;
            $this->name = $profile->name;
            $this->key = $profile->key;
            $this->direction = $profile->direction;
            $this->format = $profile->format ?: 'csv';
            $this->status = $profile->status;
            $this->description = $profile->description;
            $this->sourceKey = $profile->sources->sortBy('sort_order')->first()?->source_key;
            $this->selectedFields = $profile->fields->where('active', true)->sortBy('sort_order')->pluck('field_key')->values()->all();

            foreach ($profile->fields as $field) {
                $stateKey = $this->fieldStateKey($field->field_key);
                $this->fieldLabels[$stateKey] = $field->label;
                $this->fieldOutputKeys[$stateKey] = $field->output_key ?: $field->field_key;
            }

            $this->filters = $profile->filters->sortBy('sort_order')->map(fn ($filter): array => [
                'field_key' => $filter->field_key,
                'operator' => $filter->operator,
                'value' => is_array($filter->value) ? implode(',', $filter->value) : (string) $filter->value,
                'active' => (bool) $filter->active,
            ])->values()->all();
        } else {
            $this->filters = [];
        }
    }

    public function updatedName(): void
    {
        if ($this->profileId === null && $this->key === '') {
            $this->key = Str::slug($this->name, '_');
        }
    }

    public function updatedSourceKey(): void
    {
        $this->selectedFields = [];
        $this->fieldLabels = [];
        $this->fieldOutputKeys = [];
        $this->filters = [];
    }

    public function addFilter(): void
    {
        $this->filters[] = [
            'field_key' => '',
            'operator' => 'equals',
            'value' => '',
            'active' => true,
        ];
    }

    public function removeFilter(int $index): void
    {
        unset($this->filters[$index]);
        $this->filters = array_values($this->filters);
    }

    public function fieldStateKey(string $fieldKey): string
    {
        return str_replace('.', '__', $fieldKey);
    }

    public function save(DataExchangeSourceRegistry $sources): void
    {
        abort_unless(auth()->user()?->can('data_exchange.manage'), 403);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'key' => [
                'required',
                'string',
                'max:255',
                Rule::unique('data_exchange_profiles', 'key')->ignore($this->profileId),
            ],
            'direction' => ['required', Rule::in([DataExchangeProfile::DIRECTION_EXPORT, DataExchangeProfile::DIRECTION_IMPORT])],
            'format' => ['required', Rule::in(['csv', 'xlsx', 'json'])],
            'status' => ['required', Rule::in([DataExchangeProfile::STATUS_DRAFT, DataExchangeProfile::STATUS_ACTIVE, DataExchangeProfile::STATUS_PAUSED])],
            'description' => ['nullable', 'string'],
            'sourceKey' => ['required', 'string'],
            'selectedFields' => ['required', 'array', 'min:1'],
            'selectedFields.*' => ['required', 'string'],
            'filters' => ['array'],
            'filters.*.field_key' => ['nullable', 'string'],
            'filters.*.operator' => ['nullable', 'string'],
            'filters.*.value' => ['nullable', 'string'],
            'filters.*.active' => ['boolean'],
        ]);

        $source = $sources->get($this->sourceKey);
        abort_unless($source, 422, 'Unknown source.');

        $availableFieldKeys = collect($this->direction === DataExchangeProfile::DIRECTION_IMPORT ? $source->importableFields() : $source->exportableFields())
            ->map(fn (DataExchangeFieldDefinition $field): string => $field->key)
            ->all();

        foreach ($this->selectedFields as $fieldKey) {
            abort_unless(in_array($fieldKey, $availableFieldKeys, true), 422, 'Selected field is not available for this profile direction.');
        }

        $profile = $this->profileId
            ? DataExchangeProfile::query()->findOrFail($this->profileId)
            : new DataExchangeProfile(['created_by' => auth()->id()]);

        $profile->forceFill([
            'name' => $data['name'],
            'key' => Str::slug($data['key'], '_'),
            'direction' => $data['direction'],
            'format' => $data['format'],
            'status' => $data['status'],
            'description' => $data['description'],
            'settings' => array_merge(['retention_days' => 90], (array) $profile->settings),
            'updated_by' => auth()->id(),
        ])->save();

        $this->profileId = $profile->id;
        $profile->sources()->delete();
        $profile->fields()->delete();
        $profile->filters()->delete();
        $profile->mappings()->delete();

        $profileSource = $profile->sources()->create([
            'source_key' => $this->sourceKey,
            'alias' => Str::slug($source->label, '_'),
            'sort_order' => 0,
        ]);

        foreach (array_values($this->selectedFields) as $index => $fieldKey) {
            $field = $source->field($fieldKey);
            $stateKey = $this->fieldStateKey($fieldKey);
            $outputKey = $this->fieldOutputKeys[$stateKey] ?? $fieldKey;
            $label = $this->fieldLabels[$stateKey] ?? $field?->label ?? $fieldKey;

            $profile->fields()->create([
                'profile_source_id' => $profileSource->id,
                'source_key' => $this->sourceKey,
                'field_key' => $fieldKey,
                'output_key' => $outputKey,
                'label' => $label,
                'sort_order' => $index,
                'active' => true,
            ]);

            $profile->mappings()->create([
                'output_format' => $this->direction === DataExchangeProfile::DIRECTION_IMPORT ? 'import' : $this->format,
                'mapping_key' => $fieldKey,
                'source_expression' => $outputKey ?: $fieldKey,
                'sort_order' => $index,
                'active' => true,
            ]);
        }

        foreach (array_values($this->filters) as $index => $filter) {
            if (blank($filter['field_key'] ?? null)) {
                continue;
            }

            $operator = $filter['operator'] ?: 'equals';
            $value = $operator === 'in'
                ? array_values(array_filter(array_map('trim', explode(',', (string) ($filter['value'] ?? '')))))
                : ($filter['value'] ?? null);

            $profile->filters()->create([
                'profile_source_id' => $profileSource->id,
                'field_key' => $filter['field_key'],
                'operator' => $operator,
                'value' => $value,
                'sort_order' => $index,
                'active' => (bool) ($filter['active'] ?? true),
            ]);
        }

        session()->flash('success', 'Data Exchange profile saved.');
        $this->redirectRoute('tech.admin.system.data-exchange.profiles.edit', $profile, navigate: false);
    }

    public function render(DataExchangeSourceRegistry $sources)
    {
        $visibleSources = $sources->visibleFor(auth()->user());
        $source = $this->sourceKey ? $sources->get($this->sourceKey) : null;
        $availableFields = $source
            ? ($this->direction === DataExchangeProfile::DIRECTION_IMPORT ? $source->importableFields() : $source->exportableFields())
            : [];

        return view('dataexchange::Livewire.Admin.profile-builder', [
            'sources' => $visibleSources,
            'source' => $source,
            'availableFields' => $availableFields,
            'operators' => [
                'equals' => 'Equals',
                'not_equals' => 'Not equals',
                'contains' => 'Contains',
                'starts_with' => 'Starts with',
                'greater_than' => 'Greater than',
                'less_than' => 'Less than',
                'in' => 'In list',
                'is_empty' => 'Is empty',
                'is_not_empty' => 'Is not empty',
            ],
        ]);
    }
}
