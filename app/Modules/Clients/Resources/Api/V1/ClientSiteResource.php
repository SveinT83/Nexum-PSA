<?php

namespace App\Modules\Clients\Resources\Api\V1;

use App\Modules\CustomField\Support\CustomFieldPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientSiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'name' => $this->name,
            'address' => $this->address,
            'co_address' => $this->co_address,
            'zip' => $this->zip,
            'city' => $this->city,
            'county' => $this->county,
            'country' => $this->country,
            'is_default' => (bool) $this->is_default,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client?->id,
                'name' => $this->client?->name,
                'client_number' => $this->client?->client_number,
            ]),
            'custom_fields' => app(CustomFieldPresenter::class)->apiFor($this->resource, $request->user()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
