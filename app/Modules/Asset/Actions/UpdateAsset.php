<?php

namespace App\Modules\Asset\Actions;

use App\Models\Tech\Work\Assets\Asset;
use Illuminate\Http\Request;

/** Browser fallback and API share the manual Asset domain action. */
class UpdateAsset
{
    public function handle(Request $request, Asset $asset): Asset
    {
        return app(SaveManualAsset::class)->handle($request, $asset);
    }
}
