<?php

use App\Modules\Storage\Controllers\Api\V1\PurchaseOrderController;
use App\Modules\Storage\Controllers\Api\V1\StorageController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('storage/items', [StorageController::class, 'items'])
    ->name('storage.items.index')
    ->middleware(CheckAbilities::class.':storage.read');

Route::post('storage/items', [StorageController::class, 'storeItem'])
    ->name('storage.items.store')
    ->middleware(CheckAbilities::class.':storage.create');

Route::get('storage/items/{item}', [StorageController::class, 'showItem'])
    ->name('storage.items.show')
    ->middleware(CheckAbilities::class.':storage.read');

Route::match(['put', 'patch'], 'storage/items/{item}', [StorageController::class, 'updateItem'])
    ->name('storage.items.update')
    ->middleware(CheckAbilities::class.':storage.update');

Route::post('storage/items/{item}/adjust', [StorageController::class, 'adjustItem'])
    ->name('storage.items.adjust')
    ->middleware(CheckAbilities::class.':storage.update');

Route::delete('storage/items/{item}', [StorageController::class, 'destroyItem'])
    ->name('storage.items.destroy')
    ->middleware(CheckAbilities::class.':storage.update');

Route::get('storage/warehouses', [StorageController::class, 'warehouses'])
    ->name('storage.warehouses.index')
    ->middleware(CheckAbilities::class.':storage.read');

Route::post('storage/warehouses', [StorageController::class, 'storeWarehouse'])
    ->name('storage.warehouses.store')
    ->middleware(CheckAbilities::class.':storage.create');

Route::match(['put', 'patch'], 'storage/warehouses/{warehouse}', [StorageController::class, 'updateWarehouse'])
    ->name('storage.warehouses.update')
    ->middleware(CheckAbilities::class.':storage.update');

Route::get('storage/boxes', [StorageController::class, 'boxes'])
    ->name('storage.boxes.index')
    ->middleware(CheckAbilities::class.':storage.read');

Route::post('storage/boxes', [StorageController::class, 'storeBox'])
    ->name('storage.boxes.store')
    ->middleware(CheckAbilities::class.':storage.create');

Route::match(['put', 'patch'], 'storage/boxes/{box}', [StorageController::class, 'updateBox'])
    ->name('storage.boxes.update')
    ->middleware(CheckAbilities::class.':storage.update');

Route::prefix('storage/purchase-orders')
    ->name('storage.purchase-orders.')
    ->group(function (): void {
        Route::get('/', [PurchaseOrderController::class, 'index'])
            ->name('index')
            ->middleware(CheckAbilities::class.':storage.purchase.read');
        Route::post('/', [PurchaseOrderController::class, 'store'])
            ->name('store')
            ->middleware(CheckAbilities::class.':storage.purchase.manage');
        Route::get('{purchaseOrder}', [PurchaseOrderController::class, 'show'])
            ->name('show')
            ->middleware(CheckAbilities::class.':storage.purchase.read');
        Route::put('{purchaseOrder}', [PurchaseOrderController::class, 'update'])
            ->name('update')
            ->middleware(CheckAbilities::class.':storage.purchase.manage');

        Route::post('{purchaseOrder}/lines/{purchaseOrderLine}/cancel', [PurchaseOrderController::class, 'cancelLine'])
            ->name('lines.cancel')
            ->middleware(CheckAbilities::class.':storage.purchase.manage');
        Route::post('{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])
            ->name('close')
            ->middleware(CheckAbilities::class.':storage.purchase.manage');
        Route::post('{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
            ->name('cancel')
            ->middleware(CheckAbilities::class.':storage.purchase.manage');

        Route::post('{purchaseOrder}/shipments', [PurchaseOrderController::class, 'storeShipment'])
            ->name('shipments.store')
            ->middleware(CheckAbilities::class.':storage.purchase.manage');
        Route::patch('{purchaseOrder}/shipments/{purchaseShipment}/status', [PurchaseOrderController::class, 'updateShipmentStatus'])
            ->name('shipments.status.update')
            ->middleware(CheckAbilities::class.':storage.purchase.manage');
        Route::post('{purchaseOrder}/shipments/{purchaseShipment}/trackings', [PurchaseOrderController::class, 'appendTracking'])
            ->name('shipments.trackings.store')
            ->middleware(CheckAbilities::class.':storage.purchase.manage');

        Route::post('{purchaseOrder}/receipts', [PurchaseOrderController::class, 'postReceipt'])
            ->name('receipts.store')
            ->middleware(CheckAbilities::class.':storage.purchase.receive');
        Route::post('{purchaseOrder}/receipts/{purchaseReceipt}/reverse', [PurchaseOrderController::class, 'reverseReceipt'])
            ->name('receipts.reverse')
            ->middleware(CheckAbilities::class.':storage.purchase.reverse');
    });
