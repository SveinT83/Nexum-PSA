<?php

namespace App\Modules\Storage\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderImportProfileVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $definition = $this->input('definition');
        if (is_string($definition)) {
            $this->merge(['definition' => json_decode($definition, true)]);
        }
    }

    public function rules(): array
    {
        return [
            'definition' => ['required', 'array'],
            'parent_version_id' => ['nullable', 'integer', 'exists:storage_purchase_order_import_profile_versions,id'],
        ];
    }
}
