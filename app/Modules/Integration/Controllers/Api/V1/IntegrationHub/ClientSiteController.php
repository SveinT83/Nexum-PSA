<?php

namespace App\Modules\Integration\Controllers\Api\V1\IntegrationHub;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientSiteController extends HubController
{
    public function clients(Request $request): JsonResponse
    {
        $page = $this->pagination($request);
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:name,-name,id,-id'],
        ]);
        $scope = $this->scope($request);
        $query = Client::query()->whereIn('id', $scope['client_ids'] ?? []);
        if (isset($validated['q'])) {
            $query->where('name', 'like', '%'.addcslashes(trim($validated['q']), '%_').'%');
        }
        if (array_key_exists('active', $validated)) {
            $query->where('active', (bool) $validated['active']);
        }
        $this->applySort($query, $validated['sort'] ?? 'name');
        $paginator = $query->paginate($page['per_page'], ['id', 'name', 'client_number', 'org_no', 'website', 'active'], 'page', $page['page']);

        return $this->result($request, 'ok', collect($paginator->items())->map(fn (Client $client): array => $this->clientPayload($client))->all(), [
            'observed_at' => now(), 'meta' => $this->paginationMeta($paginator),
        ]);
    }

    public function client(Request $request, int $client): JsonResponse
    {
        $model = Client::query()->whereIn('id', $this->scope($request)['client_ids'] ?? [])->whereKey($client)
            ->first(['id', 'name', 'client_number', 'org_no', 'website', 'active']);

        return $model ? $this->result($request, 'ok', $this->clientPayload($model), ['observed_at' => now()]) : $this->notFound($request);
    }

    public function sites(Request $request): JsonResponse
    {
        $page = $this->pagination($request);
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'in:name,-name,id,-id'],
        ]);
        $scope = $this->scope($request);
        $query = ClientSite::query()->whereIn('id', $scope['site_ids'] ?? [])->whereIn('client_id', $scope['client_ids'] ?? []);
        if (isset($validated['client_id'])) {
            $query->where('client_id', (int) $validated['client_id']);
        }
        if (isset($validated['q'])) {
            $query->where('name', 'like', '%'.addcslashes(trim($validated['q']), '%_').'%');
        }
        $this->applySort($query, $validated['sort'] ?? 'name');
        $paginator = $query->paginate($page['per_page'], ['id', 'client_id', 'name', 'address', 'zip', 'city', 'country', 'is_default'], 'page', $page['page']);

        return $this->result($request, 'ok', collect($paginator->items())->map(fn (ClientSite $site): array => $this->sitePayload($site))->all(), [
            'observed_at' => now(), 'meta' => $this->paginationMeta($paginator),
        ]);
    }

    public function site(Request $request, int $site): JsonResponse
    {
        $scope = $this->scope($request);
        $model = ClientSite::query()->whereIn('id', $scope['site_ids'] ?? [])->whereIn('client_id', $scope['client_ids'] ?? [])->whereKey($site)
            ->first(['id', 'client_id', 'name', 'address', 'zip', 'city', 'country', 'is_default']);

        return $model ? $this->result($request, 'ok', $this->sitePayload($model), ['observed_at' => now()]) : $this->notFound($request);
    }

    private function applySort($query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction)->orderBy('id');
    }

    /** @return array<string, mixed> */
    private function clientPayload(Client $client): array
    {
        return [
            'id' => $client->id, 'name' => $client->name, 'client_number' => $client->client_number,
            'organization_number' => $client->org_no, 'website' => $client->website, 'active' => (bool) $client->active,
        ];
    }

    /** @return array<string, mixed> */
    private function sitePayload(ClientSite $site): array
    {
        return [
            'id' => $site->id, 'client_id' => $site->client_id, 'name' => $site->name,
            'location' => ['address' => $site->address, 'postal_code' => $site->zip, 'city' => $site->city, 'country' => $site->country],
            'is_default' => (bool) $site->is_default,
        ];
    }
}
