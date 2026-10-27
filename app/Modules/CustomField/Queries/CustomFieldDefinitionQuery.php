<?php

namespace App\Modules\CustomField\Queries;

use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Support\CustomFieldApiAccess;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Apply field authorization before totals and pages so hidden definitions do not leak counts. */
class CustomFieldDefinitionQuery
{
    public function handle(Request $request): LengthAwarePaginator
    {
        $models = app(CustomFieldModelRegistry::class);
        $data = $request->validate([
            'model' => ['sometimes', 'string', Rule::in(array_merge(array_keys($models->all()), array_values($models->all())))],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'], 'active' => ['sometimes', 'boolean'],
            'editable_via_api' => ['sometimes', 'boolean'], 'searchable' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $extra = array_diff(array_keys($request->query()), ['model', 'q', 'active', 'editable_via_api', 'searchable', 'per_page', 'page']);
        if ($extra) {
            throw ValidationException::withMessages(array_fill_keys($extra, 'This filter is not supported.'));
        }
        $query = CustomFieldDefinition::query()->orderBy('model_type')->orderBy('sort_order')->orderBy('label')->orderBy('id');
        if (isset($data['model'])) {
            $query->whereIn('model_type', $models->storageTypesFor($data['model']));
        }
        if (($data['q'] ?? '') !== '') {
            $query->where(function ($q) use ($data) {
                foreach (['key', 'label', 'help_text', 'model_type'] as $column) {
                    $q->orWhere($column, 'like', '%'.$data['q'].'%');
                }
            });
        }
        foreach (['active', 'editable_via_api', 'searchable'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $query->where($flag, $request->boolean($flag));
            }
        }
        $rows = $query->get()->filter(fn ($definition) => CustomFieldApiAccess::canDiscover($request->user(), $definition))->values();
        $perPage = (int) ($data['per_page'] ?? 25);
        $page = (int) ($data['page'] ?? 1);

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]);
    }
}
