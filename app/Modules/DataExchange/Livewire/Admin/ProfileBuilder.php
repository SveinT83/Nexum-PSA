<?php

namespace App\Modules\DataExchange\Livewire\Admin;

use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Support\DataExchangeSourceRegistry;
use Illuminate\Support\Str;
use Livewire\Component;

class ProfileBuilder extends Component
{
    #[\Livewire\Attributes\Locked]
    public ?int $profileId = null;

    #[\Livewire\Attributes\Locked]
    public ?string $revision = null;

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
            $this->revision = \App\Modules\DataExchange\Support\DataExchangeRevision::of($profile);
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
        $fields = [];
        foreach ($this->selectedFields as $key) {
            $stateKey = $this->fieldStateKey($key);
            $fields[] = ['field_key' => $key, 'output_key' => $this->fieldOutputKeys[$stateKey] ?? $key, 'label' => $this->fieldLabels[$stateKey] ?? null];
        }
        $filters = collect($this->filters)->filter(fn ($f) => filled($f['field_key'] ?? null))->map(function ($f) {
            if (($f['operator'] ?? null) === 'in' && ! is_array($f['value'] ?? null)) {
                $f['value'] = array_values(array_filter(array_map('trim', explode(',', (string) ($f['value'] ?? ''))), fn ($v) => $v !== ''));
            }

            return $f;
        })->values()->all();
        try {
            $profile = app(\App\Modules\DataExchange\Actions\SaveDataExchangeProfile::class)->handle([
                'name' => $this->name, 'key' => $this->key, 'direction' => $this->direction, 'format' => $this->format,
                'status' => $this->status, 'description' => $this->description, 'source_key' => $this->sourceKey,
                'fields' => $fields, 'filters' => $filters,
            ], auth()->user(), $this->profileId ? DataExchangeProfile::findOrFail($this->profileId) : null, $this->revision);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }
            $this->addError('revision', $error->getMessage());

            return;
        }
        $this->profileId = $profile->id;
        $this->revision = \App\Modules\DataExchange\Support\DataExchangeRevision::of($profile);

        session()->flash('success', 'Data Exchange profile saved.');
        $this->redirectRoute('tech.admin.system.data-exchange.profiles.edit', $profile, navigate: false);
    }

    public function render(DataExchangeSourceRegistry $sources)
    {
        $visibleSources = $sources->visibleFor(auth()->user());
        $source = $visibleSources->firstWhere('key', $this->sourceKey);
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
