<?php

namespace App\Modules\CustomField\Support;

use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class CustomFieldPresenter
{
    public function __construct(private readonly CustomFieldModelRegistry $models)
    {
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function visibleFor(Model $model, ?object $actor = null): Collection
    {
        return $this->definitionsFor($model, $actor, onlyVisible: true)
            ->map(fn (CustomFieldDefinition $definition) => $this->entry($model, $definition, $actor));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function editableFor(Model $model, ?object $actor = null): Collection
    {
        return $this->definitionsFor($model, $actor, onlyVisible: false)
            ->filter(fn (CustomFieldDefinition $definition) => $definition->editable_in_ui && $this->canEdit($definition, $actor))
            ->map(fn (CustomFieldDefinition $definition) => $this->entry($model, $definition, $actor));
    }

    public function apiFor(Model $model, ?object $actor = null): array
    {
        return $this->definitionsFor($model, $actor, onlyVisible: false)
            ->mapWithKeys(fn (CustomFieldDefinition $definition) => [
                $definition->key => $this->entry($model, $definition, $actor)['value'],
            ])
            ->all();
    }

    private function definitionsFor(Model $model, ?object $actor, bool $onlyVisible): Collection
    {
        return CustomFieldDefinition::query()
            ->whereIn('model_type', $this->models->storageTypesFor($model->getMorphClass()))
            ->where('active', true)
            ->when($onlyVisible, fn ($query) => $query->where('visible_in_ui', true))
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->filter(fn (CustomFieldDefinition $definition) => $this->canView($definition, $actor));
    }

    private function entry(Model $model, CustomFieldDefinition $definition, ?object $actor): array
    {
        $value = CustomFieldValue::query()
            ->where('custom_field_definition_id', $definition->id)
            ->whereIn('model_type', $this->models->storageTypesFor($model->getMorphClass()))
            ->where('model_id', $model->getKey())
            ->first();

        return [
            'definition' => $definition,
            'key' => $definition->key,
            'label' => $definition->label,
            'type' => $definition->field_type,
            'help_text' => $definition->help_text,
            'options' => $definition->options ?? [],
            'required' => $definition->required,
            'can_edit' => $definition->editable_in_ui && $this->canEdit($definition, $actor),
            'value' => $this->displayValue($definition, $value),
        ];
    }

    private function displayValue(CustomFieldDefinition $definition, ?CustomFieldValue $value): mixed
    {
        if (! $value) {
            return null;
        }

        return match ($definition->field_type) {
            CustomFieldDefinition::TYPE_NUMBER => $value->value_number === null ? null : (float) $value->value_number,
            CustomFieldDefinition::TYPE_CHECKBOX => $value->value_boolean,
            CustomFieldDefinition::TYPE_DATE => $value->value_date?->toDateString(),
            CustomFieldDefinition::TYPE_DATETIME => $value->value_datetime?->toIso8601String(),
            CustomFieldDefinition::TYPE_MULTISELECT => $value->value_json ?? [],
            default => $value->value_text,
        };
    }

    private function canView(CustomFieldDefinition $definition, ?object $actor): bool
    {
        if (! $actor || ! method_exists($actor, 'can')) {
            return ! $definition->view_permission && ! $definition->admin_only;
        }

        if ($definition->admin_only && method_exists($actor, 'hasAnyRole') && ! $actor->hasAnyRole(['Admin', 'Superuser'])) {
            return false;
        }

        return ! $definition->view_permission || $actor->can($definition->view_permission);
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
}
