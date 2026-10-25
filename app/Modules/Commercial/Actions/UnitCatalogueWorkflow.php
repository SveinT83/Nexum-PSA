<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Support\CommercialInput;
use Illuminate\Http\Request;

/** Units retain the browser's create-placeholder/update/delete behavior and database references. */
class UnitCatalogueWorkflow
{
    public function store(Request $request): Units
    {
        $data = CommercialInput::validate($request, ['name' => 'sometimes|string|max:255', 'short' => 'sometimes|nullable|string|max:50', 'common_code' => 'sometimes|nullable|string|max:50']);

        return Units::query()->create($data + ['name' => 'xxx', 'short' => ''])->refresh();
    }

    public function update(Request $request, Units $unit): Units
    {
        $data = CommercialInput::validate($request, ['name' => 'sometimes|string|max:255', 'short' => 'sometimes|nullable|string|max:50', 'common_code' => 'sometimes|nullable|string|max:50', 'action' => 'sometimes|in:update,save,delete']);
        if (($data['action'] ?? null) === 'delete') {
            $unit->delete();

            return $unit;
        }
        $unit->update(\Illuminate\Support\Arr::except($data, ['action']));

        return $unit->refresh();
    }
}
