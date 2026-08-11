<?php

namespace App\Modules\Storage\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderImportProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['matching_scope', 'policy_overrides', 'definition'] as $field) {
            $value = $this->input($field);
            if (! is_string($value)) {
                continue;
            }

            $this->merge([
                $field => trim($value) === '' && $field === 'policy_overrides'
                    ? []
                    : json_decode($value, true),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'vendor_id' => [
                'nullable',
                'integer',
                Rule::exists('vendors', 'id')
                    ->where('is_supplier', true)
                    ->where('is_active', true),
            ],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', 'unique:storage_purchase_order_import_profiles,slug'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', 'integer', 'between:0,1000000'],
            'matching_scope' => ['required', 'array'],
            'policy_overrides' => ['nullable', 'array'],
            'definition' => ['required', 'array'],
        ];
    }
}
