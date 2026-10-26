<?php

namespace App\Modules\Asset\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Asset\Actions\ExecuteAssetCommand;
use App\Modules\Asset\Actions\SaveManualAsset;
use App\Modules\Asset\Queries\AssetQuery;
use App\Modules\Asset\Resources\Api\V1\AssetResource;
use App\Modules\Asset\Support\AssetInput;
use Illuminate\Http\Request;

/** Typed Asset operations delegate domain queries and shared writes. */
class AssetController extends Controller
{
    public function index(Request $request, AssetQuery $query)
    {
        AssetInput::allowed($request, ['client_id', 'work_context_id', 'context_type', 'search', 'type', 'status',
            'sensitivity_level', 'criticality_level', 'has_alerts', 'sort', 'direction', 'page', 'per_page']);

        return AssetResource::collection($query->paginateForApi($request));
    }

    public function show(Request $request, Asset $asset)
    {
        AssetInput::allowed($request, []);

        return (new AssetResource($asset->load('workContext', 'rmmLinks')))->response()
            ->header('ETag', AssetInput::revision($asset->getAttributes()));
    }

    public function store(Request $request, ExecuteAssetCommand $commands)
    {
        $result = $commands->handle($request, 'create', function () use ($request) {
            $asset = app(SaveManualAsset::class)->handle($request, api: true);

            return ['data' => (new AssetResource($asset->load('workContext', 'rmmLinks')))->resolve($request)];
        });

        return response()->json($result['result'] + ['operation_id' => $result['operation_id'], 'replayed' => $result['replayed']], 201);
    }

    public function update(Request $request, Asset $asset, ExecuteAssetCommand $commands)
    {
        $result = $commands->handle($request, 'update:'.$asset->id, function () use ($request, $asset) {
            $asset = app(SaveManualAsset::class)->handle($request, $asset, api: true);

            return ['data' => (new AssetResource($asset->load('workContext', 'rmmLinks')))->resolve($request)];
        });

        return response()->json($result['result'] + ['operation_id' => $result['operation_id'], 'replayed' => $result['replayed']]);
    }
}
