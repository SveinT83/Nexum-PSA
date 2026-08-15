<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use Illuminate\Database\Eloquent\Builder;

class ScopeVisibility
{
    /**
     * Keep rows only when every populated scope dimension is allowed by the
     * grant, and never expose a fully unscoped row through a scoped read.
     *
     * @param  Builder<*>  $query
     * @param  array<string, mixed>  $scope
     * @return Builder<*>
     */
    public function apply(Builder $query, array $scope): Builder
    {
        $query->where(function (Builder $bound): void {
            $bound->whereNotNull('client_id')
                ->orWhereNotNull('client_site_id')
                ->orWhereNotNull('integration_id');
        });

        foreach ([
            'client_id' => array_map('intval', $scope['client_ids'] ?? []),
            'client_site_id' => array_map('intval', $scope['site_ids'] ?? []),
            'integration_id' => array_map('strval', $scope['integration_ids'] ?? []),
        ] as $column => $allowed) {
            $query->where(function (Builder $dimension) use ($column, $allowed): void {
                $dimension->whereNull($column);
                if ($allowed !== []) {
                    $dimension->orWhereIn($column, $allowed);
                }
            });
        }

        return $query;
    }
}
