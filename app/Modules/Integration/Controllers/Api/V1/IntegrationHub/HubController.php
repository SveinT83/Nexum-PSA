<?php

namespace App\Modules\Integration\Controllers\Api\V1\IntegrationHub;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\IntegrationHubResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class HubController extends Controller
{
    /** @param array<string, mixed> $options */
    protected function result(Request $request, string $status, mixed $data, array $options = [], int $httpStatus = 200): JsonResponse
    {
        return IntegrationHubResult::response($status, $data, array_merge([
            'correlation_id' => $this->correlation($request),
            'capability_key' => $request->attributes->get('integration_hub_capability_key'),
            'capability_version' => $request->attributes->get('integration_hub_capability_version'),
            'scope' => $this->scope($request),
        ], $options), $httpStatus);
    }

    protected function notFound(Request $request): JsonResponse
    {
        return $this->result($request, 'unknown', null, [
            'reason_code' => 'record_not_found_or_out_of_scope',
            'reason_message' => 'The record is unavailable in the effective scope.',
        ], 404);
    }

    /** @return array{page:int,per_page:int} */
    protected function pagination(Request $request): array
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) config('integration-hub.max_page_size', 50))],
        ]);

        return ['page' => (int) ($validated['page'] ?? 1), 'per_page' => (int) ($validated['per_page'] ?? 25)];
    }

    /** @return array<string, mixed> */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function scope(Request $request): array
    {
        $claims = (array) $request->attributes->get('integration_hub_claims', []);

        return (array) ($claims['scope'] ?? ['installation' => config('integration-hub.installation_key')]);
    }

    protected function correlation(Request $request): string
    {
        return (string) $request->attributes->get('integration_hub_correlation_id');
    }
}
