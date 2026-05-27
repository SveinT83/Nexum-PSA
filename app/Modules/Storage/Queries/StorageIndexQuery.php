<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Storage\Models\Item;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StorageIndexQuery
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return Item::query()
            ->with(['warehouse', 'box', 'primaryVendor'])
            ->when(($filters['availability'] ?? 'should_order') === 'should_order', function ($query) {
                $query->where(function ($nested) {
                    $nested->where('should_order', true)
                        ->orWhere('qty_on_hand', '<=', 0)
                        ->orWhereColumn('qty_reserved', '>=', 'qty_on_hand')
                        ->orWhereColumn('qty_on_hand', '<=', 'reorder_point');
                });
            })
            ->when(($filters['availability'] ?? null) === 'all', fn ($query) => $query)
            ->when(($filters['availability'] ?? null) === 'in_stock', fn ($query) => $query->where('qty_on_hand', '>', 0))
            ->when(($filters['availability'] ?? null) === 'out_of_stock', fn ($query) => $query->where('qty_on_hand', '<=', 0))
            ->when($filters['q'] ?? null, function ($query, string $search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('sku', 'like', '%' . $search . '%')
                        ->orWhere('name', 'like', '%' . $search . '%')
                        ->orWhere('ean_number', 'like', '%' . $search . '%');
                });
            })
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('primary_vendor_id', $supplierId))
            ->latest('updated_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
