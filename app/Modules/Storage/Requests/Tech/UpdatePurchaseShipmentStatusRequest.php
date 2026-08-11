<?php

namespace App\Modules\Storage\Requests\Tech;

use App\Modules\Storage\Models\PurchaseShipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseShipmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    PurchaseShipment::STATUS_IN_TRANSIT,
                    PurchaseShipment::STATUS_DELIVERED,
                    PurchaseShipment::STATUS_CANCELLED,
                ]),
            ],
            'occurred_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
