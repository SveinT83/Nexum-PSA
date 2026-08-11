<?php

namespace App\Modules\Storage\Requests\Tech;

use Illuminate\Foundation\Http\FormRequest;

class CancelPurchaseOrderLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
