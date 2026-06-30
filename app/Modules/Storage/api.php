<?php

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
