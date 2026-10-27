<?php

namespace App\Modules\CustomField\Actions;

use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldValue;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SyncCustomFieldValues
{
    public function __construct(
        private readonly NormalizeCustomFieldValue $normalizer,
        private readonly CustomFieldModelRegistry $models,
    ) {}

    public function handle(Model $model, array $values, ?object $actor = null, string $channel = 'ui'): void
    {
        // Serialize cross-record uniqueness and definition edits on the same native lock.
        DB::transaction(function () use ($model, $values, $actor, $channel): void {
            if (Schema::hasTable('custom_field_api_mutex')) {
                DB::table('custom_field_api_mutex')->where('id', 1)->lockForUpdate()->firstOrFail();
            }
            $definitions = $this->definitionsFor($model, $actor, $channel);
            foreach (array_keys($values) as $key) {
                if ($definitions->where('key', $key)->count() !== 1) {
                    throw ValidationException::withMessages(["custom_fields.{$key}" => 'This field is unavailable, ambiguous or not editable for the current actor.']);
                }
            }
            // New records must provide required editable fields. Partial updates preserve omissions.
            if ($model->wasRecentlyCreated) {
                foreach ($definitions->where('required', true) as $definition) {
                    if (! array_key_exists($definition->key, $values)) {
                        throw ValidationException::withMessages(["custom_fields.{$definition->key}" => "{$definition->label} is required."]);
                    }
                }
            }
            // Validate the complete patch before writing any value, even for direct action callers.
            $payloads = [];
            foreach ($definitions as $definition) {
                if (array_key_exists($definition->key, $values)) {
                    $payloads[$definition->id] = $this->normalizer->handle($definition, $values[$definition->key]);
                    $this->ensureUnique($definition, $model, $payloads[$definition->id]['value_text']);
                }
            }
            foreach ($definitions as $definition) {
                if (! array_key_exists($definition->id, $payloads)) {
                    continue;
                }
                $payload = $payloads[$definition->id];
                $record = CustomFieldValue::query()
                    ->where('custom_field_definition_id', $definition->id)
                    ->whereIn('model_type', $this->models->storageTypesFor($model->getMorphClass()))
                    ->where('model_id', $model->getKey())->first();
                if ($record) {
                    $record->forceFill($payload)->save();
                } else {
                    CustomFieldValue::query()->create(['custom_field_definition_id' => $definition->id,
                        'model_type' => $model->getMorphClass(), 'model_id' => $model->getKey(), ...$payload]);
                }
            }
        });
    }

    public function definitionsFor(Model $model, ?object $actor = null, string $channel = 'ui'): Collection
    {
        return CustomFieldDefinition::query()
            ->whereIn('model_type', $this->models->storageTypesFor($model->getMorphClass()))
            ->where('active', true)
            ->when($channel === 'ui', fn ($query) => $query->where('editable_in_ui', true))
            ->when($channel === 'api', fn ($query) => $query->where('editable_via_api', true))
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->filter(fn (CustomFieldDefinition $definition) => $this->canEdit($definition, $actor));
    }

    private function canEdit(CustomFieldDefinition $definition, ?object $actor): bool
    {
        if (! $actor || ! method_exists($actor, 'can')) {
            return ! $definition->edit_permission && ! $definition->admin_only;
        }

        if ($definition->admin_only && method_exists($actor, 'hasAnyRole') && ! $actor->hasAnyRole(['Admin', 'Superuser'])) {
            return false;
        }

        return ! $definition->edit_permission || $actor->can($definition->edit_permission);
    }

    private function ensureUnique(CustomFieldDefinition $definition, Model $model, ?string $value): void
    {
        if (! $definition->unique_per_model || $value === null) {
            return;
        }

        $exists = CustomFieldValue::query()
            ->where('custom_field_definition_id', $definition->id)
            ->whereIn('model_type', $this->models->storageTypesFor($model->getMorphClass()))
            ->where('value_text', $value)
            ->where('model_id', '!=', $model->getKey())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                "custom_fields.{$definition->key}" => "{$definition->label} must be unique.",
            ]);
        }
    }
}
