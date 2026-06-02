<?php

namespace App\Modules\CustomField\Resources\Api\V1;

use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomFieldDefinitionResource extends JsonResource
{
    /*
    |--------------------------------------------------------------------------
    | Custom field definition payload
    |--------------------------------------------------------------------------
    |
    | This resource describes definitions, not values. Values are read and
    | written through the owning domain APIs such as Clients.
    |
    */
    public function toArray(Request $request): array
    {
        $registry = app(CustomFieldModelRegistry::class);

        return [
            'id' => $this->id,
            'model' => $registry->labelFor($this->model_type),
            'model_type' => $this->model_type,
            'key' => $this->key,
            'label' => $this->label,
            'field_type' => $this->field_type,
            'help_text' => $this->help_text,
            'options' => $this->options ?? [],
            'sort_order' => $this->sort_order,
            'visible_in_ui' => $this->visible_in_ui,
            'editable_in_ui' => $this->editable_in_ui,
            'editable_via_api' => $this->editable_via_api,
            'searchable' => $this->searchable,
            'unique_per_model' => $this->unique_per_model,
            'required' => $this->required,
            'admin_only' => $this->admin_only,
            'view_permission' => $this->view_permission,
            'edit_permission' => $this->edit_permission,
            'active' => $this->active,
            'links' => [
                'self' => route('api.v1.custom-fields.show', $this->resource),
            ],
        ];
    }
}
