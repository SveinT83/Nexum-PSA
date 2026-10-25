<?php

namespace App\Modules\Economy\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EconomyOrderResource extends JsonResource
{
    /*
    |--------------------------------------------------------------------------
    | Economy order payload
    |--------------------------------------------------------------------------
    |
    | Orders are internal invoice preparation records. They are intentionally
    | exposed with totals, period, state, client context, and line details when
    | loaded, while downstream invoice/export behavior remains outside this API.
    |
    */
    public function toArray(Request $request): array
    {
        return \App\Modules\Economy\Support\EconomyProjection::order($this->resource, $request);
    }
}
