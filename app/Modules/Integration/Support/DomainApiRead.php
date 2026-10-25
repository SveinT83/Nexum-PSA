<?php

namespace App\Modules\Integration\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Bounded pagination and truthful discovery for the typed, registered parity surface. */
final class DomainApiRead
{
    /** Accept OpenAPI query booleans while preserving invalid input for Laravel validation. */
    public static function normalizeQueryBooleans(Request $request, array $names): void
    {
        foreach ($names as $name) {
            if ($request->query($name) === 'true') {
                $request->merge([$name => true]);
            } elseif ($request->query($name) === 'false') {
                $request->merge([$name => false]);
            }
        }
    }

    public static function pageSize(Request $request): int
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return (int) ($data['per_page'] ?? 25);
    }

    public static function paginateCollection(\Illuminate\Support\Collection $items, Request $request): \Illuminate\Pagination\LengthAwarePaginator
    {
        $size = self::pageSize($request);
        $page = max(1, $request->integer('page', 1));

        return new \Illuminate\Pagination\LengthAwarePaginator($items->slice(($page - 1) * $size, $size)->values(), $items->count(), $size, $page, ['path' => $request->url(), 'query' => $request->query()]);
    }

    public static function capabilities(Request $request, string $domain): array
    {
        $operations = [];
        foreach (Route::getRoutes() as $route) {
            if (($route->defaults['parity_domain'] ?? null) !== $domain) {
                continue;
            }
            $scope = $route->defaults['parity_scope'];
            $permissions = $route->defaults['parity_permissions'];
            $scopes = array_values(array_unique(array_merge([$scope], $route->defaults['parity_additional_scopes'] ?? [])));
            $permissionGroups = array_values(array_unique(array_merge([$permissions], $route->defaults['parity_additional_permissions'] ?? [])));
            $coordinator = $route->defaults['parity_coordinator_profile'] ?? null;
            $operations[] = [
                'name' => $route->getName(),
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'path' => '/'.$route->uri(),
                'ability' => $scope,
                'required_scopes' => $scopes, 'required_permissions' => $permissionGroups,
                'token_authorized' => collect($scopes)->every(fn ($ability) => (bool) $request->user()?->tokenCan($ability)),
                'actor_authorized' => collect($permissionGroups)->every(fn ($group) => app(DomainApiAuthorization::class)->permits($request, $group)),
                'coordinator_profile' => $coordinator,
                'conditional_authorization' => $route->defaults['parity_conditional_authorization'] ?? [],
                'object_and_runtime_checks_required' => true,
            ];
        }

        return ['domain' => $domain, 'surface' => 'domain_api', 'operations' => $operations,
            'contract' => 'All registered domain operations are documented in OpenAPI; object, conditional grants and runtime gates remain authoritative.',
            'limits' => ['default_per_page' => 25, 'maximum_per_page' => 100],
        ];
    }
}
