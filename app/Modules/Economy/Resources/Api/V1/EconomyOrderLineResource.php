<?php

namespace App\Modules\Economy\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EconomyOrderLineResource extends JsonResource
{
    /*
    |--------------------------------------------------------------------------
    | Economy order line payload
    |--------------------------------------------------------------------------
    |
    | Lines are generated from ticket time and ticket cost sources. The API keeps
    | source metadata visible so external agents can trace invoice preparation
    | back to the originating operational record.
    |
    */
    public function toArray(Request $request): array
    {
        return \App\Modules\Economy\Support\EconomyProjection::line($this->resource);
    }
}
