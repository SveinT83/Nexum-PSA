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
