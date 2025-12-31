<?php

namespace App\Http\Requests\Tech\CS;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:255',
                Rule::unique('services')->ignore($this->route('service'))
            ],
            'status' => ['nullable', 'in:draft,published,archived'],
            'icon' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'queue_default_id' => ['nullable', 'integer'],
            'availability_addon_of_service_id' => ['nullable', 'integer'],
            'availability_audience' => ['nullable', 'in:all,business,private'],
            'orderable' => ['sometimes', 'boolean'],
            'taxable' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'setup_fee' => ['nullable', 'numeric', 'min:0'],
            'price_ex_vat' => ['required', 'numeric', 'min:0'],
            'price_including_tax' => ['nullable', 'numeric', 'min:0'],
            'costs' => ['nullable', 'array'],
            'costs.*' => ['exists:costs,id'],
            'billing_cycle' => ['required', 'in:monthly,yearly,one_time'],
            'unit_pricing' => ['nullable', 'in:none,per_user,per_device,per_server'],
            'one_time_fee' => ['nullable', 'numeric', 'min:0'],
            'one_time_fee_recurrence' => ['nullable', 'in:none,yearly,every_x_years,every_x_months'],
            'recurrence_value_x' => ['nullable', 'integer', 'min:1'],
            'default_discount_value' => ['nullable', 'numeric', 'min:0'],
            'default_discount_type' => ['nullable', 'in:amount,percent'],
            'timebank_enabled' => ['sometimes', 'boolean'],
            'timebank_minutes' => ['nullable', 'integer', 'min:0'],
            'timebank_interval' => ['nullable', 'in:monthly,yearly,one_time'],
            'short_description' => ['nullable', 'string'],
            'long_description' => ['nullable', 'string'],
            'terms' => ['nullable'],
            'published_at' => ['nullable', 'date'],
            'archived_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'sku.required' => 'SKU is required.',
            'price_ex_vat.required' => 'Price excl. VAT is required.',
            'billing_cycle.required' => 'Billing cycle is required.',
        ];
    }
}
