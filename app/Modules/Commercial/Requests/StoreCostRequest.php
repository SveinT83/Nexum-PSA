<?php

namespace App\Modules\Commercial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCostRequest extends FormRequest
{
    public function rules(): array
    {
        return app(\App\Modules\Commercial\Actions\CostCatalogueWorkflow::class)->rules($this);
    }

    public function authorize(): bool
    {
        return true;
    }
}
