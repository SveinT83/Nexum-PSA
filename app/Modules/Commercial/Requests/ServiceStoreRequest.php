<?php

namespace App\Modules\Commercial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('timebank_minutes')) {
            return;
        }

        $minutes = $this->input('timebank_minutes');

        if ($minutes === '' || $minutes === null) {
            $this->merge(['timebank_minutes' => null]);

            return;
        }

        $normalized = is_string($minutes)
            ? str_replace(',', '.', $minutes)
            : $minutes;

        if (is_numeric($normalized)) {
            $this->merge(['timebank_minutes' => (string) (int) $normalized]);
        }
    }

    public function rules(): array
    {
        return app(\App\Modules\Commercial\Actions\ServiceCatalogueWorkflow::class)->rules($this);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'sku.required' => 'SKU is required.',
            'unit.required' => 'A unit is required',
            'price_ex_vat.required' => 'Price excl. VAT is required.',
            'billing_cycle.required' => 'Billing cycle is required.',
        ];
    }
}
