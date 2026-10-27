<?php

namespace App\Modules\Documentation\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Return only the fields rendered by the customer portal's captured template. */
class PortalDocumentationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fields = [];
        foreach ($this->template_snapshot_json ?? [] as $field) {
            if (! empty($field['Name'])) {
                $fields[] = ['name' => $field['Name'], 'label' => $field['labelName'] ?? $field['Name'],
                    'type' => $field['type'] ?? 'text', 'options' => $field['options'] ?? null,
                    'value' => ($this->data_json ?? [])[$field['Name']] ?? null];
            }
        }

        return ['id' => $this->id, 'title' => $this->title,
            'category' => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null,
            'site' => $this->site ? ['id' => $this->site->id, 'name' => $this->site->name] : null,
            'fields' => $fields, 'updated_at' => $this->updated_at,
            'links' => ['self' => route('api.v1.documentation.portal.show', $this->id)]];
    }
}
