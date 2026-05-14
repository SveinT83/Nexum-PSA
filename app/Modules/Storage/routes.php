<?php

use App\Modules\Storage\Controllers\Tech\BoxController;
use App\Modules\Storage\Controllers\Tech\ItemController;
use App\Modules\Storage\Controllers\Tech\StorageController;
use Illuminate\Support\Facades\Route;

Route::get('/storage', [StorageController::class, 'index'])
    ->name('storage.index');

Route::post('/storage/warehouses', [StorageController::class, 'storeWarehouse'])
    ->name('storage.warehouses.store');

Route::get('/storage/items/create', [ItemController::class, 'create'])
    ->name('storage.items.create');

Route::post('/storage/items', [ItemController::class, 'store'])
    ->name('storage.items.store');

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
