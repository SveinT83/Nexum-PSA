<?php

namespace App\Modules\Storage\Requests\Tech;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppendPurchaseShipmentTrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shipping_carrier_id' => ['nullable', 'integer', 'exists:shipping_carriers,id'],
            'tracking_number' => ['required', 'string', 'max:255'],
            'tracking_type' => [
                'required',
                Rule::in(['master', 'parcel', 'last_mile', 'other', 'legacy']),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'direct_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
