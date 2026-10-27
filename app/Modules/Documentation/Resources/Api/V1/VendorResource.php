<?php

namespace App\Modules\Documentation\Resources\Api\V1;

use App\Modules\Documentation\Support\DocumentationApiInput;
use App\Modules\Documentation\Support\VendorData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Never serialize Storage bootstrap provenance or internal identity hashes. */
class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, ...$this->resource->only(VendorData::FIELDS),
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
            'revision' => DocumentationApiInput::revision($this->resource->getAttributes()),
            'links' => ['self' => route('api.v1.documentation.vendors.show', $this->id)]];
    }
}
