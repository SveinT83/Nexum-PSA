<?php

use App\Modules\Taxonomy\Controllers\Admin\CategoryController;
use App\Modules\Taxonomy\Controllers\Admin\TagController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Taxonomy Module Routes
|--------------------------------------------------------------------------
|
| Loaded inside the authenticated /tech route group. Existing route names and
| URLs remain stable while Taxonomy owns categories and tags.
|
*/

Route::middleware('admin')->group(function () {
    Route::get('/admin/system/category', [CategoryController::class, 'index'])
        ->name('admin.system.category.index');
    Route::post('/admin/system/category/store', [CategoryController::class, 'store'])
        ->name('admin.system.category.store');
    Route::put('/admin/system/category/update/{category}', [CategoryController::class, 'update'])
        ->name('admin.system.category.update');
    Route::delete('/admin/system/category/destroy/{category}', [CategoryController::class, 'destroy'])
        ->name('admin.system.category.destroy');

    Route::get('/admin/system/tag', [TagController::class, 'index'])
        ->name('admin.system.tag.index');
    Route::post('/admin/system/tag/store', [TagController::class, 'store'])
        ->name('admin.system.tag.store');
    Route::put('/admin/system/tag/update/{tag}', [TagController::class, 'update'])
        ->name('admin.system.tag.update');
    Route::delete('/admin/system/tag/destroy/{tag}', [TagController::class, 'destroy'])
        ->name('admin.system.tag.destroy');
});
