<?php

use App\Modules\Sales\Controllers\Admin\SalesSettingsController;
use App\Modules\Sales\Controllers\Tech\LeadsController;
use App\Modules\Sales\Controllers\Tech\SalesController;
use Illuminate\Support\Facades\Route;

Route::get('/sales', [SalesController::class, 'index'])
    ->name('sales.index');

Route::get('/sales/create', [SalesController::class, 'create'])
    ->name('sales.create');

Route::get('/sales/leads', [LeadsController::class, 'index'])
    ->name('sales.leads.index');

Route::get('/sales/leads/{lead}', [LeadsController::class, 'show'])
    ->name('sales.leads.show');

Route::get('/sales/{sale}', [SalesController::class, 'show'])
    ->name('sales.show');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/sales/rules', [SalesSettingsController::class, 'rules'])
        ->name('admin.settings.sales.rules');

    Route::get('/admin/settings/sales/workflows', [SalesSettingsController::class, 'workflows'])
        ->name('admin.settings.sales.workflows');
});
