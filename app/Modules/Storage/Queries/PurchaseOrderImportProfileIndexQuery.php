<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PurchaseOrderImportProfileIndexQuery
{
    private const SORTS = [
        'updated' => 'updated_at',
        'name' => 'name',
        'state' => 'lifecycle_state',
        'health' => 'health_state',
        'priority' => 'priority',
    ];

    public function paginate(Request $request): LengthAwarePaginator
    {
        $query = PurchaseOrderImportProfile::query()
            ->with(['vendor', 'activeVersion'])
            ->withCount(['versions', 'fixtures']);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $term = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function (Builder $searchQuery) use ($term): void {
                $searchQuery
                    ->where('name', 'like', $term)
                    ->orWhere('slug', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhereHas('vendor', fn (Builder $vendorQuery) => $vendorQuery
                        ->where('name', 'like', $term)
                        ->orWhere('vendor_code', 'like', $term));
            });
        }

        $state = (string) $request->query('state', '');
        if (in_array($state, [
            PurchaseOrderImportProfile::STATE_DRAFT,
            PurchaseOrderImportProfile::STATE_SHADOW,
            PurchaseOrderImportProfile::STATE_ACTIVE,
            PurchaseOrderImportProfile::STATE_DEGRADED,
            PurchaseOrderImportProfile::STATE_PAUSED,
            PurchaseOrderImportProfile::STATE_RETIRED,
        ], true)) {
            $query->where('lifecycle_state', $state);
        }

        $vendorId = filter_var($request->query('vendor_id'), FILTER_VALIDATE_INT);
        if ($vendorId && $vendorId > 0) {
            $query->where('vendor_id', $vendorId);
        }

        $sort = (string) $request->query('sort', 'updated');
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        if ($sort === 'supplier') {
            $query->orderBy(
                Vendor::query()
                    ->select('name')
                    ->whereColumn('vendors.id', 'storage_purchase_order_import_profiles.vendor_id')
                    ->limit(1),
                $direction
            );
        } else {
            $query->orderBy(self::SORTS[$sort] ?? self::SORTS['updated'], $direction);
        }

        $query->orderByDesc('id');

        return $query->paginate(25)->withQueryString();
    }
}
