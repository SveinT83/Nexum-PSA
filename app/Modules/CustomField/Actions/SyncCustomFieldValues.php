<?php

namespace App\Modules\CustomField\Actions;

use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SyncCustomFieldValues
{
    public function __construct(private readonly NormalizeCustomFieldValue $normalizer)
    {
    }

    public function handle(Model $model, array $values, ?object $actor = null, string $channel = 'ui'): void
    {
        $definitions = $this->definitionsFor($model, $actor, $channel);

        foreach ($definitions as $definition) {
            if (! array_key_exists($definition->key, $values)) {
                continue;
            }

            $payload = $this->normalizer->handle($definition, $values[$definition->key]);
            $this->ensureUnique($definition, $model, $payload['value_text']);

            CustomFieldValue::query()->updateOrCreate(
                [
                    'custom_field_definition_id' => $definition->id,
                    'model_type' => $model->getMorphClass(),
                    'model_id' => $model->getKey(),
                ],
                $payload
            );
        }
    }

    public function definitionsFor(Model $model, ?object $actor = null, string $channel = 'ui'): Collection
    {
        return CustomFieldDefinition::query()
            ->where('model_type', $model->getMorphClass())
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
            return ! $definition->edit_permission;
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
            ->where('model_type', $model->getMorphClass())
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
