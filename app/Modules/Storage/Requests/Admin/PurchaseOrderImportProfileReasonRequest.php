<?php

namespace App\Modules\Storage\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseOrderImportProfileReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:245'],
        ];
    }
}
