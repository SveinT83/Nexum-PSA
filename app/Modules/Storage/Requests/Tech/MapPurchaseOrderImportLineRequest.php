<?php

namespace App\Modules\Storage\Requests\Tech;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MapPurchaseOrderImportLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => [
                'required',
                'integer',
                Rule::exists('storage_items', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
