<?php

namespace App\Http\Requests\Tech\CS\Requests\Tech\CS;

use Illuminate\Foundation\Http\FormRequest;

class StoreCostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'cost' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'string', 'in:client,user,site,asset,other'],
            'recurrence' => ['required', 'string', 'in:month,year,quarter,none'],
            'note' => ['nullable', 'string'],
            'vendor_id' => ['required', 'exists:vendors,id'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
