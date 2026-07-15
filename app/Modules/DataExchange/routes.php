<?php

use App\Modules\DataExchange\Controllers\Admin\DataExchangeController;
use App\Modules\DataExchange\Controllers\Admin\DataExchangeDeliveryTargetController;
use App\Modules\DataExchange\Controllers\Admin\DataExchangeImportController;
use App\Modules\DataExchange\Controllers\Admin\DataExchangeProfileController;
use App\Modules\DataExchange\Controllers\Admin\DataExchangeRunController;
use App\Modules\DataExchange\Controllers\Admin\DataExchangeScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware('admin')->group(function (): void {
    Route::get('/admin/system/data-exchange', [DataExchangeController::class, 'index'])
        ->name('admin.system.data-exchange.index');

    Route::get('/admin/system/data-exchange/profiles/create', [DataExchangeProfileController::class, 'create'])
        ->name('admin.system.data-exchange.profiles.create');
    Route::get('/admin/system/data-exchange/profiles/{profile}/edit', [DataExchangeProfileController::class, 'edit'])
        ->name('admin.system.data-exchange.profiles.edit');

    Route::post('/admin/system/data-exchange/profiles/{profile}/runs', [DataExchangeRunController::class, 'store'])
        ->name('admin.system.data-exchange.runs.store');
    Route::get('/admin/system/data-exchange/runs/{run}', [DataExchangeRunController::class, 'show'])
        ->name('admin.system.data-exchange.runs.show');
    Route::get('/admin/system/data-exchange/files/{file}/download', [DataExchangeRunController::class, 'download'])
        ->name('admin.system.data-exchange.files.download');

    Route::post('/admin/system/data-exchange/imports/dry-run', [DataExchangeImportController::class, 'dryRun'])
        ->name('admin.system.data-exchange.imports.dry-run');
    Route::get('/admin/system/data-exchange/imports/{preview}', [DataExchangeImportController::class, 'show'])
        ->name('admin.system.data-exchange.imports.show');
    Route::post('/admin/system/data-exchange/imports/{preview}/commit', [DataExchangeImportController::class, 'commit'])
        ->name('admin.system.data-exchange.imports.commit');

    Route::post('/admin/system/data-exchange/schedules', [DataExchangeScheduleController::class, 'store'])
        ->name('admin.system.data-exchange.schedules.store');
    Route::patch('/admin/system/data-exchange/schedules/{schedule}', [DataExchangeScheduleController::class, 'update'])
        ->name('admin.system.data-exchange.schedules.update');

    Route::post('/admin/system/data-exchange/delivery-targets', [DataExchangeDeliveryTargetController::class, 'store'])
        ->name('admin.system.data-exchange.delivery-targets.store');
    Route::patch('/admin/system/data-exchange/delivery-targets/{deliveryTarget}', [DataExchangeDeliveryTargetController::class, 'update'])
        ->name('admin.system.data-exchange.delivery-targets.update');
});
