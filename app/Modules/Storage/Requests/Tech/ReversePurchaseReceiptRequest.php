<?php

namespace App\Modules\Storage\Requests\Tech;

use Illuminate\Foundation\Http\FormRequest;

class ReversePurchaseReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_token' => ['required', 'uuid', 'max:36'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'reversed_at' => ['nullable', 'date'],
        ];
    }
}
