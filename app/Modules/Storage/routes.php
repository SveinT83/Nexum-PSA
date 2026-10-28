<?php

use App\Modules\Storage\Controllers\Admin\InventoryController;
use App\Modules\Storage\Controllers\Admin\PurchaseOrderAutomationController;
use App\Modules\Storage\Controllers\Admin\PurchaseOrderImportProfileController;
use App\Modules\Storage\Controllers\Tech\BoxController;
use App\Modules\Storage\Controllers\Tech\ItemController;
use App\Modules\Storage\Controllers\Tech\PurchaseOrderController;
use App\Modules\Storage\Controllers\Tech\PurchaseOrderImportController;
use App\Modules\Storage\Controllers\Tech\PurchaseReceiptController;
use App\Modules\Storage\Controllers\Tech\PurchaseShipmentController;
use App\Modules\Storage\Controllers\Tech\StorageController;
use Illuminate\Support\Facades\Route;

// Versioned Storage endpoints are declared here; api.php is only a legacy loader.
if (isset($storageApiRoutes) && $storageApiRoutes === true) {

    Route::get('storage/items', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'items'])
        ->name('storage.items.index')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.read');

    Route::post('storage/items', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'storeItem'])
        ->name('storage.items.store')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.create');

    Route::get('storage/items/{item}', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'showItem'])
        ->name('storage.items.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.read');

    Route::match(['put', 'patch'], 'storage/items/{item}', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'updateItem'])
        ->name('storage.items.update')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.update');

    Route::post('storage/items/{item}/adjust', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'adjustItem'])
        ->name('storage.items.adjust')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.update');

    Route::delete('storage/items/{item}', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'destroyItem'])
        ->name('storage.items.destroy')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.update');

    Route::get('storage/warehouses', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'warehouses'])
        ->name('storage.warehouses.index')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.read');

    Route::post('storage/warehouses', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'storeWarehouse'])
        ->name('storage.warehouses.store')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.create');

    Route::match(['put', 'patch'], 'storage/warehouses/{warehouse}', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'updateWarehouse'])
        ->name('storage.warehouses.update')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.update');

    Route::get('storage/boxes', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'boxes'])
        ->name('storage.boxes.index')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.read');

    Route::post('storage/boxes', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'storeBox'])
        ->name('storage.boxes.store')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.create');

    Route::match(['put', 'patch'], 'storage/boxes/{box}', [\App\Modules\Storage\Controllers\Api\V1\StorageController::class, 'updateBox'])
        ->name('storage.boxes.update')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.update');

    Route::prefix('storage/purchase-orders')
        ->name('storage.purchase-orders.')
        ->group(function (): void {
            Route::get('/', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'index'])
                ->name('index')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.read');
            Route::post('/', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'store'])
                ->name('store')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.manage');
            Route::get('{purchaseOrder}', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'show'])
                ->name('show')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.read');
            Route::put('{purchaseOrder}', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'update'])
                ->name('update')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.manage');

            Route::post('{purchaseOrder}/lines/{purchaseOrderLine}/cancel', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'cancelLine'])
                ->name('lines.cancel')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.manage');
            Route::post('{purchaseOrder}/close', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'close'])
                ->name('close')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.manage');
            Route::post('{purchaseOrder}/cancel', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'cancel'])
                ->name('cancel')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.manage');

            Route::post('{purchaseOrder}/shipments', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'storeShipment'])
                ->name('shipments.store')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.manage');
            Route::patch('{purchaseOrder}/shipments/{purchaseShipment}/status', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'updateShipmentStatus'])
                ->name('shipments.status.update')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.manage');
            Route::post('{purchaseOrder}/shipments/{purchaseShipment}/trackings', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'appendTracking'])
                ->name('shipments.trackings.store')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.manage');

            Route::post('{purchaseOrder}/receipts', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'postReceipt'])
                ->name('receipts.store')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.receive');
            Route::post('{purchaseOrder}/receipts/{purchaseReceipt}/reverse', [\App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController::class, 'reverseReceipt'])
                ->name('receipts.reverse')
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.reverse');
        });

    // Apply fresh browser permissions and durable command handling to legacy URLs.
    $legacyPermissions = ['items.index' => ['storage.view', 'item'], 'items.show' => ['storage.view', 'item'], 'items.store' => ['storage.item_manage', null], 'items.update' => ['storage.item_manage', 'item'], 'items.adjust' => ['storage.stock_adjust', 'item'], 'items.destroy' => ['storage.item_manage', 'item'], 'warehouses.index' => ['storage.view', null], 'warehouses.store' => ['storage.manage_settings', null], 'warehouses.update' => ['storage.manage_settings', null], 'boxes.index' => ['storage.view', null], 'boxes.store' => ['storage.item_manage', null], 'boxes.update' => ['storage.item_manage', 'box'], 'purchase-orders.index' => ['storage.purchase_view', null], 'purchase-orders.show' => ['storage.purchase_view', 'purchaseOrder'], 'purchase-orders.store' => ['storage.purchase_manage', null], 'purchase-orders.update' => ['storage.purchase_manage', 'purchaseOrder'], 'purchase-orders.lines.cancel' => ['storage.purchase_manage', 'purchaseOrder'], 'purchase-orders.close' => ['storage.purchase_manage', 'purchaseOrder'], 'purchase-orders.cancel' => ['storage.purchase_manage', 'purchaseOrder'], 'purchase-orders.shipments.store' => ['storage.purchase_manage', 'purchaseOrder'], 'purchase-orders.shipments.status.update' => ['storage.purchase_manage', 'purchaseOrder'], 'purchase-orders.shipments.trackings.store' => ['storage.purchase_manage', 'purchaseOrder'], 'purchase-orders.receipts.store' => ['storage.purchase_receive', 'purchaseOrder'], 'purchase-orders.receipts.reverse' => ['storage.purchase_reverse', 'purchaseOrder']];
    $legacyFields = ['items.store' => ['warehouse_id', 'room_id', 'box_id', 'manufacturer_vendor_id', 'primary_vendor_id', 'supplier_sku', 'supplier_purchase_url', 'supplier_currency', 'supplier_lead_time_days', 'supplier_moq', 'supplier_pack_size', 'sku', 'name', 'short_description', 'long_description', 'manufacturer_part_number', 'ean_number', 'purchase_price', 'markup_percent', 'sale_price', 'vat_rate', 'has_serials', 'track_batch', 'expiry_enabled', 'becomes_asset', 'default_warranty_months', 'reorder_point', 'target_level', 'lead_time_days', 'moq', 'initial_quantity', 'should_order', 'can_be_ordered', 'requires_customer_quote', 'status'], 'items.adjust' => ['delta', 'adjustment_mode', 'quantity', 'reason', 'note'], 'warehouses.store' => ['name', 'code', 'address', 'notes', 'is_active'], 'boxes.store' => ['warehouse_id', 'room_id', 'code_human', 'name', 'barcode_value', 'barcode_type', 'status', 'placement_note', 'is_active'], 'purchase-orders.store' => ['vendor_id', 'deliver_to_warehouse_id', 'status', 'vendor_ref', 'ordered_at', 'expected_at', 'currency', 'notes', 'lines'], 'purchase-orders.shipments.store' => ['shipping_carrier_id', 'reference', 'status', 'shipped_at', 'expected_at', 'delivered_at', 'notes', 'allocations', 'trackings'], 'purchase-orders.shipments.status.update' => ['status', 'occurred_at', 'reason'], 'purchase-orders.shipments.trackings.store' => ['shipping_carrier_id', 'tracking_number', 'tracking_type', 'label', 'direct_url', 'sort_order', 'metadata'], 'purchase-orders.lines.cancel' => ['quantity', 'reason'], 'purchase-orders.close' => ['reason'], 'purchase-orders.cancel' => ['reason'], 'purchase-orders.receipts.store' => ['idempotency_token', 'purchase_shipment_id', 'delivery_note_ref', 'received_at', 'warehouse_id', 'room_id', 'box_id', 'notes', 'lines'], 'purchase-orders.receipts.reverse' => ['idempotency_token', 'reason', 'reversed_at'], 'items.update' => ['warehouse_id', 'room_id', 'box_id', 'manufacturer_vendor_id', 'primary_vendor_id', 'supplier_sku', 'supplier_purchase_url', 'supplier_currency', 'supplier_lead_time_days', 'supplier_moq', 'supplier_pack_size', 'sku', 'name', 'short_description', 'long_description', 'manufacturer_part_number', 'ean_number', 'purchase_price', 'markup_percent', 'sale_price', 'vat_rate', 'has_serials', 'track_batch', 'expiry_enabled', 'becomes_asset', 'default_warranty_months', 'reorder_point', 'target_level', 'lead_time_days', 'moq', 'should_order', 'can_be_ordered', 'requires_customer_quote', 'status'], 'warehouses.update' => ['name', 'code', 'address', 'notes', 'is_active'], 'boxes.update' => ['warehouse_id', 'room_id', 'code_human', 'name', 'barcode_value', 'barcode_type', 'status', 'placement_note', 'is_active'], 'purchase-orders.update' => ['po_number', 'vendor_id', 'deliver_to_warehouse_id', 'status', 'vendor_ref', 'ordered_at', 'expected_at', 'currency', 'notes', 'lines'], 'items.index' => ['availability', 'q', 'warehouse_id', 'supplier_id', 'sort', 'direction', 'sku', 'ean_number', 'status', 'box_id', 'page', 'per_page'], 'items.show' => [], 'items.destroy' => [], 'warehouses.index' => ['q', 'is_active', 'page', 'per_page'], 'boxes.index' => ['q', 'warehouse_id', 'status', 'page', 'per_page'], 'purchase-orders.index' => ['q', 'status', 'vendor_id', 'warehouse_id', 'expected_after', 'expected_before', 'tracking_number', 'method', 'sort', 'direction', 'page', 'per_page'], 'purchase-orders.show' => ['order_line_sort', 'order_line_direction', 'shipment_line_sort', 'shipment_line_direction', 'receipt_sort', 'receipt_direction']];
    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        if (! str_starts_with($name ?? '', 'api.v1.storage.')) {
            continue;
        }
        $suffix = substr($name, strlen('api.v1.storage.'));
        if (! isset($legacyPermissions[$suffix])) {
            continue;
        }
        [$permission, $revision] = $legacyPermissions[$suffix];
        $route->defaults('storage_permissions', [$permission]);
        $route->defaults('storage_fields', $legacyFields[$suffix] ?? []);
        if ($revision && ($revision !== 'item' || str_contains($route->uri(), '{item}'))) {
            $route->defaults('storage_revision', $revision);
        }
        $abilities = [];
        foreach ($route->middleware() as $middleware) {
            if (str_contains($middleware, 'CheckAbilities:')) {
                $abilities = explode(',', substr($middleware, strpos($middleware, ':') + 1));
            }
        }
        $route->defaults('storage_abilities', $abilities);
        $route->middleware([\App\Modules\Storage\Support\StorageApiErrors::class,
            \App\Modules\Storage\Support\StorageApiAccess::class,
            \App\Modules\Storage\Actions\ExecuteStorageApiCommand::class]);
    }

    // Each new operation carries explicit scopes, current user rights and payload keys.
    $operations = [
        [
            'method' => 'GET',
            'path' => 'storage/context',
            'name' => 'storage.context',
            'operation' => 'context',
            'ability' => 'storage.read',
            'permissions' => [],
            'fields' => [],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => ['storage.view', 'storage.item_manage', 'storage.purchase_manage', 'storage.purchase_receive', 'storage.pick', 'storage.purchase_import_resolve', 'storage.purchase_import_profile_manage', 'storage.purchase_import_policy_manage'],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/summary',
            'name' => 'storage.summary',
            'operation' => 'summary',
            'ability' => 'storage.read',
            'permissions' => ['storage.view'],
            'fields' => [],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/docs',
            'name' => 'storage.docs',
            'operation' => 'docs',
            'ability' => 'storage.read',
            'permissions' => ['storage.view'],
            'fields' => ['topic'],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/suppliers',
            'name' => 'storage.suppliers.index',
            'operation' => 'suppliers',
            'ability' => 'storage.suppliers.read',
            'permissions' => [],
            'fields' => ['q', 'is_active', 'is_supplier', 'is_manufacturer', 'page', 'per_page'],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => ['storage.view', 'storage.purchase_view', 'storage.item_manage', 'storage.purchase_manage', 'storage.purchase_import_profile_manage'],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/suppliers/{supplier}',
            'name' => 'storage.suppliers.show',
            'operation' => 'supplier',
            'ability' => 'storage.suppliers.read',
            'permissions' => [],
            'fields' => [],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => ['storage.view', 'storage.purchase_view', 'storage.item_manage', 'storage.purchase_manage', 'storage.purchase_import_profile_manage'],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/items/{item}/movements',
            'name' => 'storage.items.movements',
            'operation' => 'movements',
            'ability' => 'storage.read',
            'permissions' => ['storage.view'],
            'fields' => ['sort', 'direction', 'page', 'per_page'],
            'revision' => 'item',
            'parameter' => 'item',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/boxes/{box}',
            'name' => 'storage.boxes.show',
            'operation' => 'box',
            'ability' => 'storage.read',
            'permissions' => ['storage.item_manage'],
            'fields' => ['box_item_sort', 'box_item_direction', 'box_event_sort', 'box_event_direction'],
            'revision' => 'box',
            'parameter' => 'box',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/picking',
            'name' => 'storage.picking.index',
            'operation' => 'picking',
            'ability' => 'storage.picking.read',
            'permissions' => ['storage.pick'],
            'fields' => ['q', 'status', 'sort', 'direction', 'page', 'per_page'],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/picking/{costEntry}/pick',
            'name' => 'storage.picking.pick',
            'operation' => 'pick',
            'ability' => 'storage.pick',
            'permissions' => ['storage.pick'],
            'fields' => [],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/supplier-orders',
            'name' => 'storage.supplier-orders.index',
            'operation' => 'workspace',
            'ability' => 'storage.purchase.read',
            'permissions' => ['storage.purchase_view'],
            'fields' => ['q', 'scope', 'status', 'stage', 'vendor_id', 'warehouse_id', 'expected_after', 'expected_before', 'tracking_number', 'method', 'sort', 'direction', 'page', 'per_page'],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/receiving',
            'name' => 'storage.receiving.index',
            'operation' => 'receiving',
            'ability' => 'storage.purchase.receive',
            'permissions' => ['storage.purchase_receive'],
            'fields' => ['q', 'scope', 'status', 'stage', 'vendor_id', 'warehouse_id', 'expected_after', 'expected_before', 'tracking_number', 'method', 'sort', 'direction', 'page', 'per_page'],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/purchase-orders/{purchaseOrder}/control-slip',
            'name' => 'storage.purchase-orders.control-slip',
            'operation' => 'control-slip',
            'ability' => 'storage.purchase.read',
            'permissions' => ['storage.purchase_view'],
            'fields' => ['shipment_id'],
            'revision' => 'purchaseOrder',
            'parameter' => 'purchaseOrder',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/supplier-order-imports',
            'name' => 'storage.imports.index',
            'operation' => 'imports',
            'ability' => 'storage.imports.read',
            'permissions' => ['storage.purchase_import_view'],
            'fields' => ['q', 'scope', 'status', 'stage', 'vendor_id', 'warehouse_id', 'expected_after', 'expected_before', 'tracking_number', 'method', 'sort', 'direction', 'page', 'per_page'],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/supplier-order-imports/{import}',
            'name' => 'storage.imports.show',
            'operation' => 'import',
            'ability' => 'storage.imports.read',
            'permissions' => ['storage.purchase_import_view'],
            'fields' => [],
            'revision' => 'import',
            'parameter' => 'import',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-imports/{import}/lines/{line}/map',
            'name' => 'storage.imports.lines.map',
            'operation' => 'import-map',
            'ability' => 'storage.imports.resolve',
            'permissions' => ['storage.purchase_import_resolve'],
            'fields' => ['item_id'],
            'revision' => 'import',
            'parameter' => 'import',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-imports/{import}/lines/{line}/create-item',
            'name' => 'storage.imports.lines.create-item',
            'operation' => 'import-create-item',
            'ability' => 'storage.imports.execute',
            'permissions' => ['storage.purchase_import_execute'],
            'fields' => ['mode'],
            'revision' => 'import',
            'parameter' => 'import',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-imports/{import}/manual-correction',
            'name' => 'storage.imports.correct',
            'operation' => 'import-correct',
            'ability' => 'storage.imports.resolve',
            'permissions' => ['storage.purchase_import_resolve'],
            'fields' => ['correction'],
            'revision' => 'import',
            'parameter' => 'import',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-imports/{import}/retry',
            'name' => 'storage.imports.retry',
            'operation' => 'import-retry',
            'ability' => 'storage.imports.execute',
            'permissions' => ['storage.purchase_import_execute'],
            'fields' => ['confirm'],
            'revision' => 'import',
            'parameter' => 'import',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-imports/{import}/finalize',
            'name' => 'storage.imports.finalize',
            'operation' => 'import-finalize',
            'ability' => 'storage.imports.execute',
            'permissions' => ['storage.purchase_import_execute'],
            'fields' => [],
            'revision' => 'import',
            'parameter' => 'import',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-imports/{import}/reject',
            'name' => 'storage.imports.reject',
            'operation' => 'import-reject',
            'ability' => 'storage.imports.execute',
            'permissions' => ['storage.purchase_import_execute'],
            'fields' => ['reason'],
            'revision' => 'import',
            'parameter' => 'import',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-imports/{import}/repair',
            'name' => 'storage.imports.repair',
            'operation' => 'import-repair',
            'ability' => 'storage.imports.execute,storage.profiles.manage',
            'permissions' => ['storage.purchase_import_execute', 'storage.purchase_import_profile_manage'],
            'fields' => ['confirm'],
            'revision' => 'import',
            'parameter' => 'import',
            'any_permissions' => [],
            'external' => true,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-imports/{import}/retry/preview',
            'name' => 'storage.imports.retry.preview',
            'operation' => 'preview',
            'ability' => 'storage.imports.execute',
            'permissions' => ['storage.purchase_import_execute'],
            'fields' => [],
            'revision' => 'import',
            'parameter' => 'import',
            'any_permissions' => [],
            'external' => false,
            'preview' => 'retry',
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-imports/{import}/repair/preview',
            'name' => 'storage.imports.repair.preview',
            'operation' => 'preview',
            'ability' => 'storage.imports.execute,storage.profiles.manage',
            'permissions' => ['storage.purchase_import_execute', 'storage.purchase_import_profile_manage'],
            'fields' => [],
            'revision' => 'import',
            'parameter' => 'import',
            'any_permissions' => [],
            'external' => false,
            'preview' => 'repair',
        ],
        [
            'method' => 'GET',
            'path' => 'storage/settings',
            'name' => 'storage.settings.show',
            'operation' => 'settings',
            'ability' => 'storage.settings.read',
            'permissions' => ['storage.manage_settings'],
            'fields' => ['warehouse_sort', 'warehouse_direction'],
            'revision' => 'settings',
            'parameter' => 'settings',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'PUT',
            'path' => 'storage/settings',
            'name' => 'storage.settings.update',
            'operation' => 'settings-update',
            'ability' => 'storage.settings.update',
            'permissions' => ['storage.manage_settings'],
            'fields' => ['default_warehouse_id'],
            'revision' => 'settings',
            'parameter' => 'settings',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/automation-policy',
            'name' => 'storage.policy.show',
            'operation' => 'policy',
            'ability' => 'storage.policy.read',
            'permissions' => ['storage.purchase_import_policy_manage'],
            'fields' => [],
            'revision' => 'policy',
            'parameter' => 'policy',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'PUT',
            'path' => 'storage/automation-policy',
            'name' => 'storage.policy.update',
            'operation' => 'policy-update',
            'ability' => 'storage.policy.update',
            'permissions' => ['storage.purchase_import_policy_manage'],
            'fields' => ['runtime_mode', 'default_warehouse_id', 'ai_agent_id', 'ai_mode', 'amount_tolerance', 'max_lines', 'max_quantity_per_line', 'max_order_total', 'max_new_items', 'supplier_bootstrap_mode', 'new_item_mode', 'silent_success', 'daily_digest_enabled', 'confirm'],
            'revision' => 'policy',
            'parameter' => 'policy',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/automation-policy/preview',
            'name' => 'storage.policy.preview',
            'operation' => 'preview',
            'ability' => 'storage.policy.update',
            'permissions' => ['storage.purchase_import_policy_manage'],
            'fields' => ['runtime_mode', 'default_warehouse_id', 'ai_agent_id', 'ai_mode', 'amount_tolerance', 'max_lines', 'max_quantity_per_line', 'max_order_total', 'max_new_items', 'supplier_bootstrap_mode', 'new_item_mode', 'silent_success', 'daily_digest_enabled', 'confirm'],
            'revision' => 'policy',
            'parameter' => 'policy',
            'any_permissions' => [],
            'external' => false,
            'preview' => 'policy',
        ],
        [
            'method' => 'GET',
            'path' => 'storage/supplier-order-profiles',
            'name' => 'storage.profiles.index',
            'operation' => 'profiles',
            'ability' => 'storage.profiles.read',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => ['q', 'vendor_id', 'lifecycle_state', 'page', 'per_page', 'state', 'sort', 'direction'],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/supplier-order-profiles/context',
            'name' => 'storage.profiles.context',
            'operation' => 'profile-context',
            'ability' => 'storage.profiles.read',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => [],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-profiles',
            'name' => 'storage.profiles.store',
            'operation' => 'profile-create',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => ['vendor_id', 'name', 'slug', 'description', 'priority', 'matching_scope', 'policy_overrides', 'definition'],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-profiles/import',
            'name' => 'storage.profiles.import',
            'operation' => 'profile-import',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => ['export', 'slug'],
            'revision' => null,
            'parameter' => null,
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/supplier-order-profiles/{profile}',
            'name' => 'storage.profiles.show',
            'operation' => 'profile',
            'ability' => 'storage.profiles.read',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => [],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'PUT',
            'path' => 'storage/supplier-order-profiles/{profile}',
            'name' => 'storage.profiles.update',
            'operation' => 'profile-update',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => ['name', 'slug', 'description', 'matching_scope', 'reason'],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'GET',
            'path' => 'storage/supplier-order-profiles/{profile}/export',
            'name' => 'storage.profiles.export',
            'operation' => 'profile-export',
            'ability' => 'storage.profiles.read',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => [],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-profiles/{profile}/fixtures',
            'name' => 'storage.profiles.fixtures.store',
            'operation' => 'profile-fixture',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => ['fixture_name', 'profile_version_id', 'purchase_order_import_id'],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-profiles/{profile}/versions',
            'name' => 'storage.profiles.versions.store',
            'operation' => 'profile-version',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => ['definition', 'parent_version_id'],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-profiles/{profile}/versions/{version}/test',
            'name' => 'storage.profiles.versions.test',
            'operation' => 'profile-test',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => [],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-profiles/{profile}/versions/{version}/activate',
            'name' => 'storage.profiles.versions.activate',
            'operation' => 'profile-activate',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => ['reason', 'confirm'],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-profiles/{profile}/versions/{version}/rollback',
            'name' => 'storage.profiles.versions.rollback',
            'operation' => 'profile-rollback',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => ['reason', 'confirm'],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-profiles/{profile}/pause',
            'name' => 'storage.profiles.pause',
            'operation' => 'profile-pause',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => ['reason'],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-profiles/{profile}/retire',
            'name' => 'storage.profiles.retire',
            'operation' => 'profile-retire',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => ['reason'],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => null,
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-profiles/{profile}/versions/{version}/activate/preview',
            'name' => 'storage.profiles.activate.preview',
            'operation' => 'preview',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => [],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => 'activate',
        ],
        [
            'method' => 'POST',
            'path' => 'storage/supplier-order-profiles/{profile}/versions/{version}/rollback/preview',
            'name' => 'storage.profiles.rollback.preview',
            'operation' => 'preview',
            'ability' => 'storage.profiles.manage',
            'permissions' => ['storage.purchase_import_profile_manage'],
            'fields' => [],
            'revision' => 'profile',
            'parameter' => 'profile',
            'any_permissions' => [],
            'external' => false,
            'preview' => 'rollback',
        ],
    ];
    foreach ($operations as $spec) {
        $read = $spec['method'] === 'GET' || $spec['preview'];
        $route = Route::match([$spec['method']], $spec['path'],
            [\App\Modules\Storage\Controllers\Api\V1\StorageWorkflowController::class, $read ? 'read' : 'write'])
            ->name($spec['name'])
            ->defaults('storage_operation', $spec['operation'])
            ->defaults('storage_permissions', $spec['permissions'])
            ->defaults('storage_any_permissions', $spec['any_permissions'])
            ->defaults('storage_abilities', explode(',', $spec['ability']))
            ->defaults('storage_fields', $spec['fields'])
            ->defaults('storage_key_required', ! $read)
            ->defaults('storage_external', $spec['external'])
            ->defaults('storage_preview', (bool) $spec['preview'])
            ->defaults('storage_preview_target', $spec['preview'])
            ->middleware([\App\Modules\Storage\Support\StorageApiErrors::class,
                \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.$spec['ability'],
                \App\Modules\Storage\Support\StorageApiAccess::class,
                \App\Modules\Storage\Actions\ExecuteStorageApiCommand::class]);
        if ($spec['revision']) {
            $route->defaults('storage_revision', $spec['revision'])
                ->defaults('storage_revision_parameter', $spec['parameter']);
        }
        foreach (['supplier', 'item', 'box', 'costEntry', 'purchaseOrder', 'import', 'line', 'profile', 'version'] as $parameter) {
            if (str_contains($spec['path'], '{'.$parameter.'}')) {
                $route->whereNumber($parameter);
            }
        }
    }

    foreach ([
        ['rooms', 'storage.read', 'storage.view', ['warehouse_id', 'page', 'per_page']],
        ['carriers', 'storage.purchase.read', 'storage.purchase_view', ['page', 'per_page']],
    ] as [$kind, $ability, $permission, $fields]) {
        Route::get('storage/'.$kind, [\App\Modules\Storage\Controllers\Api\V1\StorageWorkflowController::class, 'read'])
            ->name('storage.'.$kind.'.index')->defaults('storage_operation', $kind)->defaults('storage_fields', $fields)
            ->defaults('storage_permissions', [$permission])->defaults('storage_abilities', [$ability])
            ->middleware([\App\Modules\Storage\Support\StorageApiErrors::class,
                \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.$ability,
                \App\Modules\Storage\Support\StorageApiAccess::class,
                \App\Modules\Storage\Actions\ExecuteStorageApiCommand::class]);
    }

    Route::get('storage/purchase-orders/{purchaseOrder}/receiving-context', [\App\Modules\Storage\Controllers\Api\V1\StorageWorkflowController::class, 'read'])
        ->name('storage.purchase-orders.receiving-context')->defaults('storage_operation', 'receiving-context')
        ->defaults('storage_fields', ['shipment_id'])->defaults('storage_permissions', ['storage.purchase_receive'])
        ->defaults('storage_abilities', ['storage.purchase.receive'])->defaults('storage_revision', 'purchaseOrder')
        ->middleware([\App\Modules\Storage\Support\StorageApiErrors::class,
            \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.purchase.receive',
            \App\Modules\Storage\Support\StorageApiAccess::class,
            \App\Modules\Storage\Actions\ExecuteStorageApiCommand::class]);

    foreach ([
        ['storage/items/{item}/edit-context', 'storage.items.edit-context', 'item-edit-context', 'storage.update', 'storage.item_manage', 'item'],
        ['storage/purchase-orders/{purchaseOrder}/edit-context', 'storage.purchase-orders.edit-context', 'order-edit-context', 'storage.purchase.manage', 'storage.purchase_manage', 'purchaseOrder'],
    ] as [$path, $name, $operation, $ability, $permission, $revision]) {
        Route::get($path, [\App\Modules\Storage\Controllers\Api\V1\StorageWorkflowController::class, 'read'])
            ->name($name)->defaults('storage_operation', $operation)->defaults('storage_fields', [])
            ->defaults('storage_permissions', [$permission])->defaults('storage_abilities', [$ability])
            ->defaults('storage_revision', $revision)
            ->middleware([\App\Modules\Storage\Support\StorageApiErrors::class,
                \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.$ability,
                \App\Modules\Storage\Support\StorageApiAccess::class,
                \App\Modules\Storage\Actions\ExecuteStorageApiCommand::class]);
    }

    Route::get('storage/lookups/items', [\App\Modules\Storage\Controllers\Api\V1\StorageWorkflowController::class, 'read'])
        ->name('storage.lookups.items')->defaults('storage_operation', 'item-lookup')
        ->defaults('storage_fields', ['q', 'warehouse_id', 'page', 'per_page'])->defaults('storage_permissions', [])
        ->defaults('storage_any_permissions', ['storage.view', 'storage.item_manage', 'storage.purchase_manage', 'storage.purchase_import_resolve'])
        ->middleware([\App\Modules\Storage\Support\StorageApiErrors::class,
            \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.read',
            \App\Modules\Storage\Support\StorageApiAccess::class,
            \App\Modules\Storage\Actions\ExecuteStorageApiCommand::class]);

    Route::get('storage/operations/{operation}', [\App\Modules\Storage\Controllers\Api\V1\StorageWorkflowController::class, 'read'])
        ->name('storage.operations.show')->defaults('storage_operation', 'operation')->defaults('storage_fields', [])
        ->defaults('storage_permissions', [])
        ->middleware([\App\Modules\Storage\Support\StorageApiErrors::class,
            \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':storage.operations.read',
            \App\Modules\Storage\Support\StorageApiAccess::class,
            \App\Modules\Storage\Actions\ExecuteStorageApiCommand::class]);

    return;
}

Route::get('/storage', [StorageController::class, 'index'])
    ->name('storage.index');

Route::get('/storage/docs', [StorageController::class, 'docs'])
    ->name('storage.docs');

Route::get('/storage/picking', [StorageController::class, 'picking'])
    ->name('storage.picking');

Route::get('/storage/picking/docs', [StorageController::class, 'pickingDocs'])
    ->name('storage.picking.docs');

Route::get('/storage/supplier-order-imports', [PurchaseOrderImportController::class, 'index'])
    ->name('storage.purchase-order-imports.index');

Route::get('/storage/supplier-order-imports/{purchaseOrderImport}', [PurchaseOrderImportController::class, 'show'])
    ->name('storage.purchase-order-imports.show');

Route::post('/storage/supplier-order-imports/{purchaseOrderImport}/lines/{importLine}/map', [PurchaseOrderImportController::class, 'mapLine'])
    ->name('storage.purchase-order-imports.lines.map');

Route::post('/storage/supplier-order-imports/{purchaseOrderImport}/lines/{importLine}/create-item', [PurchaseOrderImportController::class, 'createItem'])
    ->name('storage.purchase-order-imports.lines.create-item');

Route::post('/storage/supplier-order-imports/{purchaseOrderImport}/retry', [PurchaseOrderImportController::class, 'retry'])
    ->name('storage.purchase-order-imports.retry');

Route::post('/storage/supplier-order-imports/{purchaseOrderImport}/finalize', [PurchaseOrderImportController::class, 'finalize'])
    ->name('storage.purchase-order-imports.finalize');

Route::post('/storage/supplier-order-imports/{purchaseOrderImport}/reject', [PurchaseOrderImportController::class, 'reject'])
    ->name('storage.purchase-order-imports.reject');
Route::post('/storage/supplier-order-imports/{purchaseOrderImport}/manual-correction', [PurchaseOrderImportController::class, 'correctManually'])
    ->name('storage.purchase-order-imports.correct-manually');

Route::post('/storage/supplier-order-imports/{purchaseOrderImport}/repair', [PurchaseOrderImportController::class, 'repair'])
    ->name('storage.purchase-order-imports.repair');

Route::get('/storage/purchase-orders', [PurchaseOrderController::class, 'index'])
    ->name('storage.purchase-orders.index');

Route::get('/storage/purchase-orders/create', [PurchaseOrderController::class, 'create'])
    ->name('storage.purchase-orders.create');

Route::post('/storage/purchase-orders', [PurchaseOrderController::class, 'store'])
    ->name('storage.purchase-orders.store');

Route::get('/storage/purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])
    ->name('storage.purchase-orders.edit');

Route::patch('/storage/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])
    ->name('storage.purchase-orders.update');

Route::get('/storage/purchase-orders/{purchaseOrder}/shipments/create', [PurchaseShipmentController::class, 'create'])
    ->name('storage.purchase-orders.shipments.create');

Route::post('/storage/purchase-orders/{purchaseOrder}/shipments', [PurchaseShipmentController::class, 'store'])
    ->name('storage.purchase-orders.shipments.store');

Route::patch('/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/status', [PurchaseShipmentController::class, 'updateStatus'])
    ->name('storage.purchase-orders.shipments.status.update');

Route::post('/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/trackings', [PurchaseShipmentController::class, 'storeTracking'])
    ->name('storage.purchase-orders.shipments.trackings.store');

Route::post('/storage/purchase-orders/{purchaseOrder}/lines/{purchaseOrderLine}/cancel', [PurchaseOrderController::class, 'cancelLine'])
    ->name('storage.purchase-orders.lines.cancel');

Route::post('/storage/purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])
    ->name('storage.purchase-orders.close');

Route::post('/storage/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
    ->name('storage.purchase-orders.cancel');

Route::get('/storage/purchase-orders/{purchaseOrder}/control-slip', [PurchaseReceiptController::class, 'controlSlip'])
    ->name('storage.purchase-orders.control-slip');

Route::get('/storage/purchase-orders/{purchaseOrder}/receive', [PurchaseReceiptController::class, 'create'])
    ->name('storage.purchase-orders.receive');

Route::post('/storage/purchase-orders/{purchaseOrder}/receipts', [PurchaseReceiptController::class, 'store'])
    ->name('storage.purchase-orders.receipts.store');

Route::get('/storage/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
    ->name('storage.purchase-orders.show');

Route::get('/storage/receiving', [PurchaseReceiptController::class, 'index'])
    ->name('storage.receiving.index');

Route::post('/storage/receipts/{receipt}/reverse', [PurchaseReceiptController::class, 'reverse'])
    ->name('storage.receipts.reverse');

Route::post('/storage/picking/{costEntry}/pick', [StorageController::class, 'pick'])
    ->name('storage.picking.pick');

Route::get('/storage/items/create', [ItemController::class, 'create'])
    ->name('storage.items.create');

Route::post('/storage/items', [ItemController::class, 'store'])
    ->name('storage.items.store');

Route::get('/storage/items/{item}/edit', [ItemController::class, 'edit'])
    ->name('storage.items.edit');

Route::patch('/storage/items/{item}', [ItemController::class, 'update'])
    ->name('storage.items.update');

Route::delete('/storage/items/{item}', [ItemController::class, 'destroy'])
    ->name('storage.items.destroy');

Route::get('/storage/items/{item}', [ItemController::class, 'show'])
    ->name('storage.items.show');

Route::post('/storage/items/{item}/adjust', [ItemController::class, 'adjust'])
    ->name('storage.items.adjust');

Route::get('/storage/boxes/create', [BoxController::class, 'create'])
    ->name('storage.boxes.create');

Route::post('/storage/boxes', [BoxController::class, 'store'])
    ->name('storage.boxes.store');

Route::get('/storage/boxes/{box}', [BoxController::class, 'show'])
    ->name('storage.boxes.show');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/storage/inventory', [InventoryController::class, 'index'])
        ->name('admin.settings.storage.inventory');

    Route::post('/admin/settings/storage/inventory/default-warehouse', [InventoryController::class, 'updateDefaultWarehouse'])
        ->name('admin.settings.storage.inventory.default-warehouse.update');

    Route::post('/admin/settings/storage/inventory/warehouses', [InventoryController::class, 'storeWarehouse'])
        ->name('admin.settings.storage.inventory.warehouses.store');

    Route::get('/admin/settings/storage/purchase-order-automation', [PurchaseOrderAutomationController::class, 'edit'])
        ->name('admin.settings.storage.purchase-order-automation.edit');

    Route::put('/admin/settings/storage/purchase-order-automation', [PurchaseOrderAutomationController::class, 'update'])
        ->name('admin.settings.storage.purchase-order-automation.update');

    Route::get('/admin/settings/storage/supplier-order-profiles', [PurchaseOrderImportProfileController::class, 'index'])
        ->name('admin.settings.storage.supplier-order-profiles.index');

    Route::get('/admin/settings/storage/supplier-order-profiles/create', [PurchaseOrderImportProfileController::class, 'create'])
        ->name('admin.settings.storage.supplier-order-profiles.create');

    Route::get('/admin/settings/storage/supplier-order-profiles/import', [PurchaseOrderImportProfileController::class, 'importForm'])
        ->name('admin.settings.storage.supplier-order-profiles.import');

    Route::post('/admin/settings/storage/supplier-order-profiles/import', [PurchaseOrderImportProfileController::class, 'import'])
        ->name('admin.settings.storage.supplier-order-profiles.import.store');

    Route::post('/admin/settings/storage/supplier-order-profiles', [PurchaseOrderImportProfileController::class, 'store'])
        ->name('admin.settings.storage.supplier-order-profiles.store');

    Route::get('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/edit', [PurchaseOrderImportProfileController::class, 'edit'])
        ->name('admin.settings.storage.supplier-order-profiles.edit');

    Route::get('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}', [PurchaseOrderImportProfileController::class, 'show'])
        ->name('admin.settings.storage.supplier-order-profiles.show');

    Route::put('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}', [PurchaseOrderImportProfileController::class, 'update'])
        ->name('admin.settings.storage.supplier-order-profiles.update');

    Route::post('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/fixtures', [PurchaseOrderImportProfileController::class, 'storeFixture'])
        ->name('admin.settings.storage.supplier-order-profiles.fixtures.store');

    Route::get('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/versions/create', [PurchaseOrderImportProfileController::class, 'createVersion'])
        ->name('admin.settings.storage.supplier-order-profiles.versions.create');

    Route::post('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/versions', [PurchaseOrderImportProfileController::class, 'storeVersion'])
        ->name('admin.settings.storage.supplier-order-profiles.versions.store');

    Route::post('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/versions/{profileVersion}/test', [PurchaseOrderImportProfileController::class, 'testVersion'])
        ->name('admin.settings.storage.supplier-order-profiles.versions.test');

    Route::post('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/versions/{profileVersion}/activate', [PurchaseOrderImportProfileController::class, 'activateVersion'])
        ->name('admin.settings.storage.supplier-order-profiles.versions.activate');

    Route::post('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/versions/{profileVersion}/rollback', [PurchaseOrderImportProfileController::class, 'rollbackVersion'])
        ->name('admin.settings.storage.supplier-order-profiles.versions.rollback');

    Route::post('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/pause', [PurchaseOrderImportProfileController::class, 'pause'])
        ->name('admin.settings.storage.supplier-order-profiles.pause');

    Route::post('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/retire', [PurchaseOrderImportProfileController::class, 'retire'])
        ->name('admin.settings.storage.supplier-order-profiles.retire');

    Route::get('/admin/settings/storage/supplier-order-profiles/{purchaseOrderImportProfile}/export', [PurchaseOrderImportProfileController::class, 'export'])
        ->name('admin.settings.storage.supplier-order-profiles.export');
});
