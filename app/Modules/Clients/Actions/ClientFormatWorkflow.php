<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\ClientFormat;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Current format rules are shared; API patch omission is translated before validation. */
class ClientFormatWorkflow
{
    public function data(Request $request, ?ClientFormat $clientFormat = null): array
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('client_formats', 'name')->ignore($clientFormat?->id),
            ],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('client_formats', 'code')->ignore($clientFormat?->id),
            ],
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0|max:1000000',
            'is_active' => 'sometimes|boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
