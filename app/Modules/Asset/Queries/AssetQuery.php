<?php

namespace App\Modules\Asset\Queries;

use App\Models\Clients\Client;
use App\Models\Tech\Work\Assets\Asset;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Builds Asset list queries for UI and future API reuse.
 *
 * Asset listing has two independent scopes:
 * - an optional route context, such as `/tech/clients/{client}/assets`
 * - optional filters submitted from the global list screen
 *
 * Keeping that logic here prevents the Tech controller from becoming the owner
 * of domain filtering rules.
 */
class AssetQuery
{
    public function paginateForTechIndex(Request $request, ?Client $client = null): LengthAwarePaginator
    {
        $query = $this->baseDisplayQuery();

        $this->applyClientContext($query, $client);
        $this->applyRequestFilters($query, $request);
        $this->applySorting($query, $request);

        return $query->paginate(25)->withQueryString();
    }

    private function baseDisplayQuery(): Builder
    {
        return Asset::query()->with(['client', 'site', 'user', 'vendorRelation']);
    }

    private function applyClientContext(Builder $query, ?Client $client): void
    {
        if ($client && $client->exists) {
            $query->where('client_id', $client->id);
        }
    }

    private function applyRequestFilters(Builder $query, Request $request): void
    {
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->boolean('has_alerts')) {
            $query->whereHas('alerts', function (Builder $alertQuery): void {
                $alertQuery->where('status', 'active');
            });
        }
    }

    private function applySorting(Builder $query, Request $request): void
    {
        $sort = $request->get('sort', 'last_seen_at');
        $direction = $request->get('direction') === 'asc' ? 'asc' : 'desc';
        $sortableColumns = ['name', 'type', 'client_site', 'status', 'last_seen_at'];

        if (! in_array($sort, $sortableColumns, true)) {
            $sort = 'last_seen_at';
        }

        if ($sort === 'client_site') {
            $query->leftJoin('clients', 'assets.client_id', '=', 'clients.id')
                ->leftJoin('client_sites', 'assets.site_id', '=', 'client_sites.id')
                ->select('assets.*')
                ->orderBy('clients.name', $direction)
                ->orderBy('client_sites.name', $direction)
                ->orderBy('assets.name');

            return;
        }

        $query->orderBy('assets.'.$sort, $direction)
            ->orderBy('assets.name');
    }
}
