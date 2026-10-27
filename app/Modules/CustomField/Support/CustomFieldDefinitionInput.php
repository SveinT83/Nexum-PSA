<?php

namespace App\Modules\CustomField\Support;

use App\Modules\CustomField\Models\CustomFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** One normalization boundary for the existing full web form and partial JSON requests. */
class CustomFieldDefinitionInput
{
    public const FLAGS = ['visible_in_ui', 'editable_in_ui', 'editable_via_api', 'searchable', 'unique_per_model', 'required', 'admin_only', 'active'];

    public static function validate(Request $request, ?CustomFieldDefinition $definition = null, bool $web = false): array
    {
        $models = app(CustomFieldModelRegistry::class);
        $required = $web || ! $definition ? 'required' : 'sometimes';
        $rules = [
            'model_type' => [$required, 'string', Rule::in(array_merge(array_keys($models->all()), array_values($models->all())))],
            'key' => [$required, 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => [$required, 'required', 'string', 'max:255'],
            'field_type' => [$required, Rule::in(CustomFieldDefinition::SUPPORTED_TYPES)],
            'help_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'options_text' => ['sometimes', 'nullable', 'string', 'max:10000', 'prohibits:options'],
            'options' => ['sometimes', 'nullable', 'array', 'max:500', 'prohibits:options_text'],
            'options.*' => ['required', 'string', 'max:255', 'distinct'],
            'view_permission' => ['sometimes', 'nullable', 'string', 'max:255', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
            'edit_permission' => ['sometimes', 'nullable', 'string', 'max:255', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ];
        foreach (self::FLAGS as $flag) {
            $rules[$flag] = ['sometimes', 'boolean'];
        }
        $unknown = array_diff(array_keys($request->all()), array_merge(array_keys($rules), ['_token', '_method']));
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'This field is not supported.'));
        }
        $data = $request->validate($rules);
        if (array_key_exists('model_type', $data)) {
            $canonical = $models->classFor($data['model_type']);
            if ($definition && $canonical !== $models->classFor($definition->model_type)) {
                throw ValidationException::withMessages(['model_type' => 'The target model cannot be changed.']);
            }
            // Preserve a legacy alias on edit; creating new records uses the canonical model class.
            $data['model_type'] = $definition?->model_type ?? $canonical;
        }
        $modelType = $data['model_type'] ?? $definition?->model_type;
        if (isset($data['key'])) {
            $query = CustomFieldDefinition::withTrashed()->whereIn('model_type', $models->storageTypesFor($modelType))->where('key', $data['key']);
            if ($definition) {
                $query->whereKeyNot($definition->id);
            }
            if ($query->exists()) {
                throw ValidationException::withMessages(['key' => 'This key is already reserved for the target model.']);
            }
        }
        if (array_key_exists('options', $data) && $data['options'] !== null && ! array_is_list($data['options'])) {
            throw ValidationException::withMessages(['options' => 'Options must be a list of strings.']);
        }
        if (array_key_exists('options_text', $data)) {
            $data['options'] = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $data['options_text'] ?? '') ?: []), fn ($item) => $item !== '')));
            unset($data['options_text']);
        }
        if ($web) {
            foreach (self::FLAGS as $flag) {
                $data[$flag] = $request->boolean($flag);
            }
            $data['active'] = $request->has('active') ? $request->boolean('active') : true;
            $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
            $data['options'] ??= null;
        } elseif (! $definition) {
            $data += ['visible_in_ui' => true, 'editable_in_ui' => true, 'editable_via_api' => true, 'active' => true,
                'searchable' => false, 'unique_per_model' => false, 'required' => false, 'admin_only' => false, 'sort_order' => 0];
        }

        return $data;
    }
}
