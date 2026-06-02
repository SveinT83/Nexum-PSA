<?php

namespace App\Modules\Report\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

class ReportResource extends JsonResource
{
    /*
    |--------------------------------------------------------------------------
    | Report discovery payload
    |--------------------------------------------------------------------------
    |
    | Report entries describe available reports and their owning domain. The
    | detail calculation is deliberately not embedded here because each domain
    | owns its own query rules and filters.
    |
    */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'domain' => $this->domain,
            'permission' => $this->permission,
            'icon' => $this->icon,
            'tags' => $this->tags,
            'ui_route_name' => $this->routeName,
            'ui_url' => Route::has($this->routeName) ? route($this->routeName) : null,
            'links' => [
                'self' => route('api.v1.reports.show', ['reportKey' => $this->key]),
            ],
        ];
    }
}
