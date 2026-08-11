<?php

namespace App\Modules\Storage\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreProtectedSupplierOrderProfileFixtureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('storage.purchase_import_profile_manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'fixture_name' => ['required', 'string', 'max:255'],
            'profile_version_id' => [
                'required',
                'integer',
                'exists:storage_purchase_order_import_profile_versions,id',
            ],
            'purchase_order_import_id' => [
                'required',
                'integer',
                'exists:storage_purchase_order_imports,id',
            ],
        ];
    }
}
