<?php

use App\Modules\Integration\Controllers\Admin\ApiController;
use App\Modules\Integration\Controllers\Admin\IntegrationsController;
use Illuminate\Support\Facades\Route;

Route::middleware('admin')->group(function () {
    Route::get('/admin/system/integrations', [IntegrationsController::class, 'index'])
        ->name('admin.system.integrations.index');

    Route::post('/admin/system/integrations/toggle', [IntegrationsController::class, 'toggle'])
        ->name('admin.system.integrations.toggle');

    Route::get('/admin/system/integrations/nable-rmm', [IntegrationsController::class, 'nableRmmSettings'])
        ->name('admin.system.integrations.nable_rmm.settings');

    Route::post('/admin/system/integrations/nable-rmm', [IntegrationsController::class, 'nableRmmUpdate'])
        ->name('admin.system.integrations.nable_rmm.update');

    Route::post('/admin/system/integrations/nable-rmm/settings', [IntegrationsController::class, 'nableRmmUpdateSettings'])
        ->name('admin.system.integrations.nable_rmm.update_settings');

    Route::post('/admin/system/integrations/nable-rmm/sync-from', [IntegrationsController::class, 'nableRmmSyncFrom'])
        ->name('admin.system.integrations.nable_rmm.sync_from');

    Route::post('/admin/system/integrations/nable-rmm/sync-to', [IntegrationsController::class, 'nableRmmSyncTo'])
        ->name('admin.system.integrations.nable_rmm.sync_to');

    Route::post('/admin/system/integrations/nable-rmm/sync-sites-from', [IntegrationsController::class, 'nableRmmSyncSitesFrom'])
        ->name('admin.system.integrations.nable_rmm.sync_sites_from');

    Route::post('/admin/system/integrations/nable-rmm/sync-sites-to', [IntegrationsController::class, 'nableRmmSyncSitesTo'])
        ->name('admin.system.integrations.nable_rmm.sync_sites_to');

    Route::get('/admin/system/integrations/tactical-rmm', [IntegrationsController::class, 'tacticalRmmSettings'])
        ->name('admin.system.integrations.tactical_rmm.settings');

    Route::post('/admin/system/integrations/tactical-rmm', [IntegrationsController::class, 'tacticalRmmUpdate'])
        ->name('admin.system.integrations.tactical_rmm.update');

    Route::post('/admin/system/integrations/tactical-rmm/settings', [IntegrationsController::class, 'tacticalRmmUpdateSettings'])
        ->name('admin.system.integrations.tactical_rmm.update_settings');

    Route::get('/admin/system/integrations/book-stack', [IntegrationsController::class, 'bookStackSettings'])
        ->name('admin.system.integrations.book_stack.settings');

    Route::post('/admin/system/integrations/book-stack', [IntegrationsController::class, 'bookStackUpdate'])
        ->name('admin.system.integrations.book_stack.update');

    Route::post('/admin/system/integrations/book-stack/test', [IntegrationsController::class, 'bookStackTestConnection'])
        ->name('admin.system.integrations.book_stack.test');

    Route::get('/admin/system/integrations/api', [ApiController::class, 'index'])
        ->name('admin.system.integrations.api.index');

    Route::post('/admin/system/integrations/api/store', [ApiController::class, 'store'])
        ->name('admin.system.integrations.api.store');

    Route::delete('/admin/system/integrations/api/{apiKey}', [ApiController::class, 'destroy'])
        ->name('admin.system.integrations.api.destroy');

    Route::get('/admin/system/integrations/api/docs', [ApiController::class, 'documentation'])
        ->name('admin.system.integrations.api.docs');
});
