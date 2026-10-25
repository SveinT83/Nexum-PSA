<?php

namespace App\Modules\DataExchange\Actions;

use App\Models\Core\User;
use App\Modules\DataExchange\Models\DataExchangeAuditEvent;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Support\DataExchangeAuthority;
use App\Modules\DataExchange\Support\DataExchangeInput;
use App\Modules\DataExchange\Support\DataExchangeMutex;
use App\Modules\DataExchange\Support\DataExchangeRevision;
use App\Modules\DataExchange\Support\DataExchangeSourceRegistry;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** One atomic builder operation owns the browser and HTTP profile composition contract. */
class SaveDataExchangeProfile
{
    public function handle(array $input, User $actor, ?DataExchangeProfile $profile = null, ?string $revision = null, bool $required = true): DataExchangeProfile
    {
        return app(DataExchangeMutex::class)->run(function () use ($input, $actor, $profile, $revision, $required) {
            if ($profile) {
                $profile = DataExchangeProfile::query()->lockForUpdate()->findOrFail($profile->id);
                DataExchangeRevision::match($profile, $revision, $required);
                DataExchangeAuthority::runtime($profile, $actor, 'manage');
            } else {
                abort_unless($actor->isActive() && DataExchangeAuthority::permits($actor, 'data_exchange.manage'), 403);
            }
            $input['key'] = isset($input['key']) ? Str::slug($input['key'], '_') : '';
            $data = DataExchangeInput::validate($input, [
                'name' => 'required|string|max:255',
                'key' => ['required', 'string', 'max:255', Rule::unique('data_exchange_profiles', 'key')->ignore($profile?->id)],
                'direction' => ['required', Rule::in(['export', 'import'])],
                'format' => ['required', Rule::in(['csv', 'xlsx', 'json'])],
                'status' => ['required', Rule::in(['draft', 'active', 'paused'])],
                'description' => 'nullable|string|max:10000',
                'source_key' => 'required|string|max:255',
                'fields' => 'required|array|min:1|max:200',
                'fields.*' => 'required|array:field_key,output_key,label',
                'fields.*.field_key' => 'required|string|max:255|distinct',
                'fields.*.output_key' => 'required|string|max:255|distinct',
                'fields.*.label' => 'nullable|string|max:255',
                'filters' => 'present|array|max:100',
                'filters.*' => 'required|array:field_key,operator,value,active',
                'filters.*.field_key' => 'required|string|max:255',
                'filters.*.operator' => ['required', Rule::in(DataExchangeSourceRegistry::OPERATORS)],
                'filters.*.value' => 'present',
                'filters.*.active' => 'required|boolean',
            ]);
            $source = DataExchangeAuthority::source($data['source_key'], $actor);
            abort_unless($data['direction'] === 'export' ? $source->supportsExport : $source->supportsImport, 422, 'The source does not support this direction.');
            if ($data['key'] === 'economy_orders_export' || $profile?->key === 'economy_orders_export') {
                abort_unless($data['key'] === 'economy_orders_export' && $data['source_key'] === 'economy.orders' && $data['direction'] === 'export', 422, 'The canonical Economy profile must retain its owner and export direction.');
            }
            $available = collect($data['direction'] === 'import' ? $source->importableFields() : $source->exportableFields())->keyBy('key');
            foreach ($data['fields'] as $index => $field) {
                if (! $available->has($field['field_key'])) {
                    throw ValidationException::withMessages(["fields.$index.field_key" => 'This field is not available for the selected source and direction.']);
                }
            }
            foreach ($data['filters'] as $index => $filter) {
                if (! in_array($filter['field_key'], $source->filters ?: $available->keys()->all(), true) || app(DataExchangeSourceRegistry::class)->isSecretField($filter['field_key'])) {
                    throw ValidationException::withMessages(["filters.$index.field_key" => 'This filter field is not available.']);
                }
                if ($filter['operator'] === 'in' && ! is_array($filter['value'])) {
                    throw ValidationException::withMessages(["filters.$index.value" => 'Use an array for an in-list filter.']);
                }
                if (! is_array($filter['value']) && ! is_scalar($filter['value']) && $filter['value'] !== null) {
                    throw ValidationException::withMessages(["filters.$index.value" => 'Use a scalar or a scalar list.']);
                }
                if (is_array($filter['value']) && count(array_filter($filter['value'], fn ($v) => ! is_scalar($v) && $v !== null))) {
                    throw ValidationException::withMessages(["filters.$index.value" => 'Use a scalar list.']);
                }
            }

            $oldFields = $profile?->fields()->get()->keyBy('field_key');
            $oldMappings = $profile?->mappings()->get()->keyBy('mapping_key');
            $oldSource = $profile?->sources()->where('source_key', $source->key)->first();
            abort_unless(! $profile || $profile->sources()->count() <= 1, 422, 'This builder cannot replace a multi-source configuration.');
            $profile ??= new DataExchangeProfile(['created_by' => $actor->id]);
            $profile->fill(collect($data)->only(['name', 'key', 'direction', 'format', 'status', 'description'])->all());
            $profile->settings = array_merge(['retention_days' => 90], $profile->settings ?? []);
            $profile->updated_by = $actor->id;
            $profile->save();
            foreach (['fields', 'filters', 'mappings', 'sources'] as $relation) {
                $profile->$relation()->delete();
            }
            $profileSource = $profile->sources()->create(['source_key' => $source->key, 'alias' => Str::slug($source->label, '_'), 'sort_order' => 0, 'settings' => $oldSource?->settings, 'relationship_path' => $oldSource?->relationship_path]);
            foreach ($data['fields'] as $index => $field) {
                $profile->fields()->create(['profile_source_id' => $profileSource->id, 'source_key' => $source->key, 'field_key' => $field['field_key'], 'output_key' => $field['output_key'], 'label' => $field['label'] ?? $available[$field['field_key']]->label, 'sort_order' => $index, 'active' => true, 'transform' => $oldFields?->get($field['field_key'])?->transform]);
                $profile->mappings()->create(['output_format' => $data['direction'] === 'import' ? 'import' : $data['format'], 'mapping_key' => $field['field_key'], 'source_expression' => $field['output_key'], 'sort_order' => $index, 'active' => true, 'default_value' => $oldMappings?->get($field['field_key'])?->default_value, 'settings' => $oldMappings?->get($field['field_key'])?->settings]);
            }
            foreach ($data['filters'] as $index => $filter) {
                $profile->filters()->create($filter + ['profile_source_id' => $profileSource->id, 'sort_order' => $index]);
            }
            DataExchangeAuditEvent::query()->create(['profile_id' => $profile->id, 'actor_id' => $actor->id, 'event_type' => 'profile_saved', 'outcome' => 'succeeded', 'occurred_at' => now()]);

            return $profile->fresh();
        });
    }

    public static function editable(DataExchangeProfile $profile): array
    {
        return [
            'name' => $profile->name, 'key' => $profile->key, 'direction' => $profile->direction, 'format' => $profile->format,
            'status' => $profile->status, 'description' => $profile->description, 'source_key' => $profile->sources()->orderBy('sort_order')->value('source_key'),
            'fields' => $profile->fields()->where('active', true)->orderBy('sort_order')->get()->map(fn ($f) => ['field_key' => $f->field_key, 'output_key' => $f->output_key ?: $f->field_key, 'label' => $f->label])->all(),
            'filters' => $profile->filters()->orderBy('sort_order')->get()->map(fn ($f) => ['field_key' => $f->field_key, 'operator' => $f->operator, 'value' => $f->value, 'active' => $f->active])->all(),
        ];
    }
}
