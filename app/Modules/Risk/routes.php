<?php

use App\Modules\Risk\Controllers\Admin\RiskSettingsController;
use App\Modules\Risk\Controllers\Tech\RiskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Risk Module Routes
|--------------------------------------------------------------------------
|
| These routes are loaded from the authenticated /tech route group. Keep every
| Risk domain route in this file to satisfy tdPSA module architecture rules.
| The surrounding group adds the "tech." route name prefix, web middleware,
| auth middleware, and tech access middleware.
|
*/

Route::get('/admin/settings/risk', [RiskSettingsController::class, 'edit'])->name('admin.settings.risk');
Route::put('/admin/settings/risk', [RiskSettingsController::class, 'update'])->name('admin.settings.risk.update');

// Assessment lifecycle: list, create, read, update, and soft-delete.
Route::get('/risk', [RiskController::class, 'index'])->name('risk.index');
Route::get('/risk/create', [RiskController::class, 'create'])->name('risk.create');
Route::post('/risk/store', [RiskController::class, 'store'])->name('risk.store');
Route::get('/risk/show/{risk}', [RiskController::class, 'show'])->name('risk.show');
Route::get('/risk/edit/{risk}', [RiskController::class, 'edit'])->name('risk.edit');
Route::put('/risk/update/{risk}', [RiskController::class, 'update'])->name('risk.update');
Route::delete('/risk/destroy/{risk}', [RiskController::class, 'destroy'])->name('risk.destroy');

// Assessment-level operations that depend on the current item state.
Route::post('/risk/show/{risk}/items', [RiskController::class, 'storeItem'])->name('risk.items.store');
Route::post('/risk/show/{risk}/approve', [RiskController::class, 'approve'])->name('risk.approve');
Route::get('/risk/show/{risk}/pdf', [RiskController::class, 'exportPdf'])->name('risk.pdf');

// Risk item current-state screens and descriptive edits.
Route::get('/risk/items/{item}', [RiskController::class, 'showItem'])->name('risk.items.show');
Route::put('/risk/items/{item}', [RiskController::class, 'updateItem'])->name('risk.items.update');
Route::delete('/risk/items/{item}', [RiskController::class, 'destroyItem'])->name('risk.items.destroy');

// Risk item history. Updating likelihood, impact, or status should go here.
Route::post('/risk/items/{item}/updates', [RiskController::class, 'storeItemUpdate'])->name('risk.items.updates.store');
Route::delete('/risk/updates/{update}', [RiskController::class, 'destroyUpdate'])->name('risk.updates.destroy');
