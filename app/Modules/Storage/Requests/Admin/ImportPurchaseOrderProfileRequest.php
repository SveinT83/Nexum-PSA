<?php

namespace App\Modules\Storage\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ImportPurchaseOrderProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $export = $this->input('export');
        if (is_string($export)) {
            $this->merge(['export' => json_decode($export, true)]);
        }
    }

    public function rules(): array
    {
        return [
            'export' => ['required', 'array'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
        ];
    }
}
