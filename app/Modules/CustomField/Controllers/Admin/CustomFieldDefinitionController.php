<?php

namespace App\Modules\CustomField\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomFieldDefinitionController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Custom field definition admin
    |--------------------------------------------------------------------------
    |
    | Admins configure which domains a custom field belongs to, who can view or
    | edit it, and whether the field is available through UI, API, search, and
    | uniqueness checks.
    |
    */
    public function index(Request $request, CustomFieldModelRegistry $models): View
    {
        $query = CustomFieldDefinition::query()
            ->orderBy('model_type')
            ->orderBy('sort_order')
            ->orderBy('label');

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($query) use ($search): void {
                $query->where('key', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%")
                    ->orWhere('help_text', 'like', "%{$search}%")
                    ->orWhere('model_type', 'like', "%{$search}%");
            });
        }

        if ($modelType = $models->classFor($request->string('model')->toString())) {
            $query->where('model_type', $modelType);
        }

        return view('customfield::Admin.index', [
            'definitions' => $query->paginate(25)->withQueryString(),
            'fieldTypes' => CustomFieldDefinition::SUPPORTED_TYPES,
            'models' => $models->all(),
            'modelRegistry' => $models,
            'activeModel' => $request->string('model')->toString(),
            'search' => $search ?? '',
        ]);
    }

    public function store(Request $request, CustomFieldModelRegistry $models): RedirectResponse
    {
        $data = $this->validated($request, $models);

        CustomFieldDefinition::query()->create($data);

        return redirect()
            ->route('tech.admin.settings.custom-fields.index')
            ->with('success', 'Custom field created.');
    }

    public function update(Request $request, CustomFieldDefinition $definition, CustomFieldModelRegistry $models): RedirectResponse
    {
        $data = $this->validated($request, $models, $definition);

        $definition->forceFill($data)->save();

        return redirect()
            ->route('tech.admin.settings.custom-fields.index', ['model' => $models->labelFor($definition->model_type)])
            ->with('success', 'Custom field updated.');
    }

    public function destroy(CustomFieldDefinition $definition): RedirectResponse
    {
        $definition->forceFill(['active' => false])->save();
        $definition->delete();

        return redirect()
            ->route('tech.admin.settings.custom-fields.index')
            ->with('success', 'Custom field deleted.');
    }

    private function validated(Request $request, CustomFieldModelRegistry $models, ?CustomFieldDefinition $definition = null): array
    {
        $modelType = $models->classFor($request->string('model_type')->toString()) ?? $definition?->model_type;

        $data = $request->validate([
            'model_type' => ['required', 'string'],
            'key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('custom_field_definitions', 'key')
                    ->where('model_type', $modelType)
                    ->ignore($definition?->id),
            ],
            'label' => ['required', 'string', 'max:255'],
            'field_type' => ['required', Rule::in(CustomFieldDefinition::SUPPORTED_TYPES)],
            'help_text' => ['nullable', 'string', 'max:2000'],
            'options_text' => ['nullable', 'string', 'max:10000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'visible_in_ui' => ['nullable', 'boolean'],
            'editable_in_ui' => ['nullable', 'boolean'],
            'editable_via_api' => ['nullable', 'boolean'],
            'searchable' => ['nullable', 'boolean'],
            'unique_per_model' => ['nullable', 'boolean'],
            'required' => ['nullable', 'boolean'],
            'admin_only' => ['nullable', 'boolean'],
            'view_permission' => ['nullable', 'string', 'max:255'],
            'edit_permission' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['model_type'] = $modelType;
        $data['key'] = Str::snake($data['key']);
        $data['options'] = $this->optionsFromText($data['options_text'] ?? '');
        unset($data['options_text']);

        foreach (['visible_in_ui', 'editable_in_ui', 'editable_via_api', 'searchable', 'unique_per_model', 'required', 'admin_only', 'active'] as $field) {
            $data[$field] = $request->boolean($field);
        }

        $data['active'] = $request->has('active') ? $request->boolean('active') : true;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function optionsFromText(string $text): ?array
    {
        $options = collect(preg_split('/\R/', $text) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $options === [] ? null : $options;
    }
}
