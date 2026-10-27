<?php

namespace App\Modules\CustomField\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\CustomField\Actions\SaveCustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        app(SaveCustomFieldDefinition::class)->handle($request, web: true);

        return redirect()
            ->route('tech.admin.settings.custom-fields.index')
            ->with('success', 'Custom field created.');
    }

    public function update(Request $request, CustomFieldDefinition $definition, CustomFieldModelRegistry $models): RedirectResponse
    {
        app(SaveCustomFieldDefinition::class)->handle($request, $definition->id, web: true);

        return redirect()
            ->route('tech.admin.settings.custom-fields.index', ['model' => $models->labelFor($definition->model_type)])
            ->with('success', 'Custom field updated.');
    }

    public function destroy(Request $request, CustomFieldDefinition $definition): RedirectResponse
    {
        app(SaveCustomFieldDefinition::class)->handle($request, $definition->id, web: true, delete: true);

        return redirect()
            ->route('tech.admin.settings.custom-fields.index')
            ->with('success', 'Custom field deleted.');
    }
}
