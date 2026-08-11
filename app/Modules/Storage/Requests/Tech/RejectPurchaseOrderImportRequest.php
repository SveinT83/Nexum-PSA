<?php

namespace App\Modules\Storage\Requests\Tech;

use Illuminate\Foundation\Http\FormRequest;

class RejectPurchaseOrderImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
