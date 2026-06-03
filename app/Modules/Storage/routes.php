<?php

use App\Modules\Storage\Controllers\Admin\InventoryController;
use App\Modules\Storage\Controllers\Tech\BoxController;
use App\Modules\Storage\Controllers\Tech\ItemController;
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
});
