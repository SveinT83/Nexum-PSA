<?php

namespace App\Http\Resources\Api\V1\Clients;

use App\Modules\Clients\Resources\Api\V1\ClientSiteResource;
use App\Modules\CustomField\Support\CustomFieldPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client_number' => $this->client_number,
            'org_no' => $this->org_no,
            'billing_email' => $this->billing_email,
            'active' => (bool) $this->active,
            'website' => $this->website,
            'notes' => $this->notes,
            'client_format_id' => $this->client_format_id,
            'client_format' => $this->clientFormat?->only(['id', 'name', 'code']),
            'sales_category_id' => $this->sales_category_id,
            'lead_temperature' => $this->lead_temperature,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'revision' => \App\Modules\Clients\Support\ClientRevision::of($this->resource),
            'risk_score' => $this->when($request->user()?->can('risk.view') === true && $request->user()?->tokenCan('risk.read'), fn () => $this->risk_score),
            'custom_fields' => app(CustomFieldPresenter::class)->apiFor($this->resource, $request->user()),
            'sites' => ClientSiteResource::collection($this->whenLoaded('sites')),
            'links' => [
                'self' => route('api.v1.clients.show', $this->id),
                'assets' => route('api.v1.clients.assets', $this->id),
                'sites' => route('api.v1.clients.sites.index', $this->id),
            ],
        ];
    }
}
