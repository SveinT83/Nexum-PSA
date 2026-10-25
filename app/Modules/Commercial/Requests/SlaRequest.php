<?php

namespace App\Modules\Commercial\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SlaRequest extends FormRequest
{
    public function rules(): array
    {
        return app(\App\Modules\Commercial\Actions\SlaCatalogueWorkflow::class)->rules($this);
    }

    public function authorize(): bool
    {
        return true;
    }
}
