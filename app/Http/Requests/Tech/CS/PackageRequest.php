<?php

namespace App\Http\Requests\Tech\CS;

use Illuminate\Foundation\Http\FormRequest;

class PackageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sales_price_user' => 'nullable|numeric',
            'sales_price_asset' => 'nullable|numeric',
            'sales_price_site' => 'nullable|numeric',
            'sales_price_client' => 'nullable|numeric',
            'sales_price_other' => 'nullable|numeric',
            'services' => 'nullable|array',
            'services.*' => 'exists:services,id',
            'terms' => 'nullable|array',
            'terms.*' => 'exists:terms,id',
        ];
    }
}
