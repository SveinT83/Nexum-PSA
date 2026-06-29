<?php

namespace App\Modules\Storage\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\AdjustItemStock;
use App\Modules\Storage\Actions\DeleteItem;
use App\Modules\Storage\Actions\StoreBox;
use App\Modules\Storage\Actions\StoreItem;
use App\Modules\Storage\Actions\StoreWarehouse;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\ItemVendor;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Resources\Api\V1\StorageBoxResource;
use App\Modules\Storage\Resources\Api\V1\StorageItemResource;
use App\Modules\Storage\Resources\Api\V1\StorageWarehouseResource;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Storage',
    description: 'API endpoints for storage inventory, warehouses, boxes, and stock adjustments.'
)]
class StorageController extends Controller
{
    #[OA\Get(
        path: '/api/v1/storage/items',
        operationId: 'getStorageItemList',
        description: 'Returns a paginated list of storage items. Use q, sku, ean_number, warehouse_id, box_id, and status to narrow results.',
        summary: 'Get list of storage items',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'ean_number', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'box_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing storage.read scope'),
        ]
    )]
    public function items(Request $request)
    {
        $query = Item::query()
            ->with(['warehouse', 'box'])
            ->orderBy('name');

        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('sku', 'like', '%'.$needle.'%')
                    ->orWhere('ean_number', 'like', '%'.$needle.'%')
                    ->orWhere('manufacturer_part_number', 'like', '%'.$needle.'%');
            });
        }

        foreach (['sku', 'ean_number', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $field === 'sku' ? Str::upper((string) $request->input($field)) : $request->input($field));
            }
        }

        foreach (['warehouse_id', 'box_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->integer($field));
            }
        }

        return StorageItemResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Get(
        path: '/api/v1/storage/items/{item}',
        operationId: 'getStorageItemById',
        summary: 'Get storage item',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing storage.read scope'),
            new OA\Response(response: 404, description: 'Item not found'),
        ]
    )]
    public function showItem(Item $item)
    {
        return new StorageItemResource($this->loadItem($item));
    }

    #[OA\Post(
        path: '/api/v1/storage/items',
        operationId: 'createStorageItem',
        summary: 'Create storage item',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        responses: [
            new OA\Response(response: 201, description: 'Item created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing storage.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeItem(Request $request, StoreItem $storeItem)
    {
        $data = $this->validatedItem($request, null);
        $this->ensureBoxBelongsToWarehouse($data['box_id'] ?? null, (int) $data['warehouse_id']);

        $item = DB::transaction(function () use ($data, $request, $storeItem) {
            $item = $storeItem->handle($this->prepareItemData($data), $request->user());
            $this->syncPrimarySupplier($item, $this->supplierData($data));

            return $item;
        });

        return (new StorageItemResource($this->loadItem($item)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/storage/items/{item}',
        operationId: 'replaceStorageItem',
        summary: 'Update storage item',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing storage.update scope'),
            new OA\Response(response: 404, description: 'Item not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[OA\Patch(
        path: '/api/v1/storage/items/{item}',
        operationId: 'patchStorageItem',
        summary: 'Partially update storage item',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing storage.update scope'),
            new OA\Response(response: 404, description: 'Item not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateItem(Request $request, Item $item)
    {
        $data = $this->validatedItem($request, $item);
        $warehouseId = (int) ($data['warehouse_id'] ?? $item->warehouse_id);
        $boxId = array_key_exists('box_id', $data) ? $data['box_id'] : $item->box_id;
        $this->ensureBoxBelongsToWarehouse($boxId, $warehouseId);

        $itemData = $this->prepareItemData($data);
        if (array_key_exists('sku', $itemData)) {
            $itemData['sku'] = Str::upper($itemData['sku']);
        }
        $itemData['updated_by'] = $request->user()?->id;

        DB::transaction(function () use ($item, $itemData, $data): void {
            $item->update($itemData);
            if ($this->hasSupplierPayload($data)) {
                $this->syncPrimarySupplier($item, $this->supplierData($data));
            }
        });

        return new StorageItemResource($this->loadItem($item));
    }

    #[OA\Post(
        path: '/api/v1/storage/items/{item}/adjust',
        operationId: 'adjustStorageItemStock',
        summary: 'Adjust storage item stock',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock adjusted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing storage.update scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function adjustItem(Request $request, Item $item, AdjustItemStock $adjustItemStock)
    {
        $data = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $item = $adjustItemStock->handle($item, (int) $data['delta'], $data['reason'], $data['note'] ?? null, $request->user());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['delta' => $exception->getMessage()]);
        }

        return new StorageItemResource($this->loadItem($item));
    }

    #[OA\Delete(
        path: '/api/v1/storage/items/{item}',
        operationId: 'deleteStorageItem',
        description: 'Soft-deletes a storage item when on-hand quantity, reserved quantity, active reservations, and stock unit quantities are all zero.',
        summary: 'Delete zero-stock storage item',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Item deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing storage.update scope'),
            new OA\Response(response: 404, description: 'Item not found'),
            new OA\Response(response: 422, description: 'Item still has stock or reservations'),
        ]
    )]
    public function destroyItem(Request $request, Item $item, DeleteItem $deleteItem)
    {
        try {
            $deleteItem->handle($item, $request->user());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['item' => $exception->getMessage()]);
        }

        return response()->noContent();
    }

    #[OA\Get(
        path: '/api/v1/storage/warehouses',
        operationId: 'getStorageWarehouseList',
        summary: 'Get list of storage warehouses',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing storage.read scope'),
        ]
    )]
    public function warehouses(Request $request)
    {
        $query = Warehouse::query()
            ->withCount(['items', 'boxes'])
            ->orderBy('name');

        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('code', 'like', '%'.$needle.'%');
            });
        }

        return StorageWarehouseResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Post(
        path: '/api/v1/storage/warehouses',
        operationId: 'createStorageWarehouse',
        summary: 'Create storage warehouse',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        responses: [
            new OA\Response(response: 201, description: 'Warehouse created'),
            new OA\Response(response: 403, description: 'Missing storage.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeWarehouse(Request $request, StoreWarehouse $storeWarehouse)
    {
        $warehouse = $storeWarehouse->handle($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('storage_warehouses', 'code')],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return (new StorageWarehouseResource($warehouse->loadCount(['items', 'boxes'])))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/v1/storage/warehouses/{warehouse}',
        operationId: 'updateStorageWarehouse',
        summary: 'Update storage warehouse',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        parameters: [
            new OA\Parameter(name: 'warehouse', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Warehouse updated'),
            new OA\Response(response: 403, description: 'Missing storage.update scope'),
            new OA\Response(response: 404, description: 'Warehouse not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateWarehouse(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('storage_warehouses', 'code')->ignore($warehouse->id)],
            'address' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('code', $data) && $data['code']) {
            $data['code'] = Str::upper(Str::slug($data['code'], '-'));
        }

        $warehouse->update($data);

        return new StorageWarehouseResource($warehouse->loadCount(['items', 'boxes']));
    }

    #[OA\Get(
        path: '/api/v1/storage/boxes',
        operationId: 'getStorageBoxList',
        summary: 'Get list of storage boxes',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing storage.read scope'),
        ]
    )]
    public function boxes(Request $request)
    {
        $query = Box::query()
            ->with('warehouse')
            ->withCount('items')
            ->orderBy('code_human');

        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('code_human', 'like', '%'.$needle.'%')
                    ->orWhere('barcode_value', 'like', '%'.$needle.'%');
            });
        }

        foreach (['warehouse_id', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $field === 'warehouse_id' ? $request->integer($field) : $request->input($field));
            }
        }

        return StorageBoxResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Post(
        path: '/api/v1/storage/boxes',
        operationId: 'createStorageBox',
        summary: 'Create storage box',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        responses: [
            new OA\Response(response: 201, description: 'Box created'),
            new OA\Response(response: 403, description: 'Missing storage.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeBox(Request $request, StoreBox $storeBox)
    {
        $box = $storeBox->handle($this->validatedBox($request, null), $request->user());

        return (new StorageBoxResource($this->loadBox($box)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/v1/storage/boxes/{box}',
        operationId: 'updateStorageBox',
        summary: 'Update storage box',
        security: [['bearerAuth' => []]],
        tags: ['Storage'],
        parameters: [
            new OA\Parameter(name: 'box', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Box updated'),
            new OA\Response(response: 403, description: 'Missing storage.update scope'),
            new OA\Response(response: 404, description: 'Box not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateBox(Request $request, Box $box)
    {
        $data = $this->validatedBox($request, $box);

        if (array_key_exists('code_human', $data) && $data['code_human']) {
            $data['code_human'] = Str::upper(Str::slug($data['code_human'], '-'));
        }

        $data['updated_by'] = $request->user()?->id;
        $box->update($data);

        return new StorageBoxResource($this->loadBox($box));
    }

    private function validatedItem(Request $request, ?Item $item): array
    {
        $creating = $item === null;

        return $request->validate([
            'warehouse_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('storage_warehouses', 'id')],
            'room_id' => ['sometimes', 'nullable', 'integer', Rule::exists('storage_rooms', 'id')],
            'box_id' => ['sometimes', 'nullable', 'integer', Rule::exists('storage_boxes', 'id')],
            'manufacturer_vendor_id' => ['sometimes', 'nullable', 'integer', Rule::exists('vendors', 'id')],
            'primary_vendor_id' => ['sometimes', 'nullable', 'integer', Rule::exists('vendors', 'id')],
            'supplier_sku' => ['sometimes', 'nullable', 'string', 'max:255'],
            'supplier_purchase_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'supplier_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'supplier_lead_time_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'supplier_moq' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'supplier_pack_size' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sku' => [$creating ? 'required' : 'sometimes', 'string', 'max:100', Rule::unique('storage_items', 'sku')->ignore($item?->id)],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'short_description' => ['sometimes', 'nullable', 'string'],
            'long_description' => ['sometimes', 'nullable', 'string'],
            'manufacturer_part_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ean_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'purchase_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'markup_percent' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'vat_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'has_serials' => ['sometimes', 'boolean'],
            'track_batch' => ['sometimes', 'boolean'],
            'expiry_enabled' => ['sometimes', 'boolean'],
            'becomes_asset' => ['sometimes', 'boolean'],
            'default_warranty_months' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'reorder_point' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'target_level' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lead_time_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'moq' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'initial_quantity' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'integer', 'min:0'],
            'should_order' => ['sometimes', 'boolean'],
            'status' => [$creating ? 'sometimes' : 'sometimes', 'string', Rule::in(['active', 'inactive'])],
        ]);
    }

    private function validatedBox(Request $request, ?Box $box): array
    {
        $creating = $box === null;

        return $request->validate([
            'warehouse_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('storage_warehouses', 'id')],
            'room_id' => ['sometimes', 'nullable', 'integer', Rule::exists('storage_rooms', 'id')],
            'code_human' => ['sometimes', 'nullable', 'string', 'max:32', Rule::unique('storage_boxes', 'code_human')->ignore($box?->id)],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'barcode_value' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('storage_boxes', 'barcode_value')->ignore($box?->id)],
            'barcode_type' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(['QR', 'EAN13', 'CODE128'])],
            'status' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(['in_stock', 'in_transit', 'loaned', 'at_customer', 'lost', 'retired'])],
            'placement_note' => ['sometimes', 'nullable', 'string', 'max:512'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function prepareItemData(array $data): array
    {
        $manufacturer = $this->resolveVendor($data['manufacturer_vendor_id'] ?? null, true, false);
        $supplier = $this->resolveVendor($data['primary_vendor_id'] ?? null, false, true);

        $itemData = Arr::except($data, [
            'supplier_sku',
            'supplier_purchase_url',
            'supplier_currency',
            'supplier_lead_time_days',
            'supplier_moq',
            'supplier_pack_size',
        ]);

        if (array_key_exists('sku', $itemData)) {
            $itemData['sku'] = Str::upper($itemData['sku']);
        }

        if (array_key_exists('manufacturer_vendor_id', $data)) {
            $itemData['manufacturer_vendor_id'] = $manufacturer?->id;
            $itemData['manufacturer'] = $manufacturer?->name;
        }

        if (array_key_exists('primary_vendor_id', $data)) {
            $itemData['primary_vendor_id'] = $supplier?->id;
        }

        return $itemData;
    }

    private function supplierData(array $data): array
    {
        return [
            'vendor_id' => $this->resolveVendor($data['primary_vendor_id'] ?? null, false, true)?->id,
            'vendor_sku' => $data['supplier_sku'] ?? null,
            'purchase_url' => $data['supplier_purchase_url'] ?? null,
            'unit_cost' => $data['purchase_price'] ?? null,
            'currency' => Str::upper($data['supplier_currency'] ?? 'NOK'),
            'lead_time_days' => $data['supplier_lead_time_days'] ?? 0,
            'moq' => $data['supplier_moq'] ?? 1,
            'pack_size' => $data['supplier_pack_size'] ?? 1,
        ];
    }

    private function hasSupplierPayload(array $data): bool
    {
        return array_intersect(array_keys($data), [
            'primary_vendor_id',
            'supplier_sku',
            'supplier_purchase_url',
            'supplier_currency',
            'supplier_lead_time_days',
            'supplier_moq',
            'supplier_pack_size',
        ]) !== [];
    }

    private function syncPrimarySupplier(Item $item, array $supplierData): void
    {
        if (! $supplierData['vendor_id']) {
            if (array_key_exists('vendor_id', $supplierData)) {
                $item->itemVendors()->where('is_primary', true)->delete();
            }

            return;
        }

        $item->itemVendors()->where('is_primary', true)->where('vendor_id', '!=', $supplierData['vendor_id'])->delete();

        ItemVendor::updateOrCreate(
            [
                'item_id' => $item->id,
                'vendor_id' => $supplierData['vendor_id'],
                'vendor_sku' => $supplierData['vendor_sku'],
            ],
            [
                'purchase_url' => $supplierData['purchase_url'],
                'currency' => $supplierData['currency'],
                'unit_cost' => $supplierData['unit_cost'],
                'moq' => $supplierData['moq'],
                'pack_size' => $supplierData['pack_size'],
                'lead_time_days' => $supplierData['lead_time_days'],
                'is_primary' => true,
            ]
        );
    }

    private function resolveVendor(mixed $vendorId, bool $isManufacturer, bool $isSupplier): ?Vendor
    {
        if (! $vendorId) {
            return null;
        }

        $vendor = Vendor::find($vendorId);

        if ($vendor) {
            $vendor->forceFill([
                'is_manufacturer' => $vendor->is_manufacturer || $isManufacturer,
                'is_supplier' => $vendor->is_supplier || $isSupplier,
            ])->save();
        }

        return $vendor;
    }

    private function ensureBoxBelongsToWarehouse(mixed $boxId, int $warehouseId): void
    {
        if (! $boxId) {
            return;
        }

        $belongs = Box::whereKey($boxId)->where('warehouse_id', $warehouseId)->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'box_id' => 'The selected box does not belong to the selected warehouse.',
            ]);
        }
    }

    private function loadItem(Item $item): Item
    {
        return $item->load(['warehouse', 'box', 'primaryVendor', 'manufacturerVendor']);
    }

    private function loadBox(Box $box): Box
    {
        return $box->load('warehouse')->loadCount('items');
    }
}
