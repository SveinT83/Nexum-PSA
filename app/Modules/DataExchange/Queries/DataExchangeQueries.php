<?php

namespace App\Modules\DataExchange\Queries;

use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Support\DataExchangeAuthority;
use App\Modules\DataExchange\Support\DataExchangeInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Scope before pagination/counting so hidden profiles cannot leak through totals or history. */
class DataExchangeQueries
{
    public function profileIds(Request $request): array
    {
        return DataExchangeProfile::withTrashed()->orderBy('id')->get()->filter(fn ($p) => DataExchangeAuthority::allowed($request, $p))->pluck('id')->all();
    }

    public function page(Request $request, Builder $query, callable $project, bool $profileTable = false, bool $global = false): array
    {
        $data = DataExchangeInput::validate($request, [
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|between:1,100',
            'profile_id' => 'sometimes|integer|min:1', 'status' => 'sometimes|string|max:40', 'q' => 'sometimes|string|max:255',
        ]);
        $ids = $this->profileIds($request);
        if ($profileTable) {
            $query->whereIn('id', $ids);
        } else {
            $query->where(function ($q) use ($ids, $global) {
                $q->whereIn('profile_id', $ids);
                if ($global) {
                    $q->orWhereNull('profile_id');
                }
            });
        }
        if (isset($data['profile_id'])) {
            $query->where($profileTable ? 'id' : 'profile_id', $data['profile_id']);
        }
        if (isset($data['status'])) {
            abort_unless(in_array('status', $query->getModel()->getConnection()->getSchemaBuilder()->getColumnListing($query->getModel()->getTable()), true), 422, 'This list does not support status.');
            $query->where('status', $data['status']);
        }
        if (isset($data['q'])) {
            abort_unless($profileTable, 422, 'This list does not support text search.');
            $query->where('name', 'like', '%'.$data['q'].'%');
        }
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return ['data' => $page->getCollection()->map($project)->values()->all(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]];
    }
}
