<?php

namespace App\Modules\Integration\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Http\JsonResponse;

class IntegrationHubProtectedResourceController extends Controller
{
    public function __invoke(ApiAbilityCatalog $abilities): JsonResponse
    {
        $scopes = collect($abilities->values())
            ->filter(fn (string $ability): bool => str_starts_with($ability, 'integration-hub.'))
            ->values()->all();

        return response()->json([
            'resource' => (string) config('integration-hub.audience'),
            'authorization_servers' => [(string) config('integration-hub.authorization_server')],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => $scopes,
            'resource_documentation' => url('/docs/api#/Integration%20Hub'),
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
