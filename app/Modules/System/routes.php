<?php

use App\Modules\System\Controllers\Admin\AdminDashboardController;
use App\Modules\System\Controllers\Admin\ApplicationVersionStatusController;
use App\Modules\System\Controllers\Admin\CompanyProfileController;
use App\Modules\System\Controllers\Admin\QueueWorkerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System Module Routes
|--------------------------------------------------------------------------
|
| Loaded inside the authenticated /tech route group. These routes own
| operational admin tools that are not part of a narrower business domain.
|
*/

Route::middleware('admin')->group(function () {
    // -------------------------------------------------
    // Admin hub and deferred application status
    // -------------------------------------------------
    Route::get('/admin', AdminDashboardController::class)
        ->name('admin.index');

    Route::get('/admin/system/version-status', ApplicationVersionStatusController::class)
        ->name('admin.system.version-status');

    // -------------------------------------------------
    // Company profile and app branding
    // -------------------------------------------------
    Route::get('/admin/system/company-profile', [CompanyProfileController::class, 'edit'])
        ->name('admin.system.company-profile.edit');

    Route::put('/admin/system/company-profile', [CompanyProfileController::class, 'update'])
        ->name('admin.system.company-profile.update');

    Route::get('/admin/system/branding', [CompanyProfileController::class, 'editBranding'])
        ->name('admin.system.branding.edit');

    Route::put('/admin/system/branding', [CompanyProfileController::class, 'updateBranding'])
        ->name('admin.system.branding.update');

    Route::put('/admin/system/branding/reset', [CompanyProfileController::class, 'resetBranding'])
        ->name('admin.system.branding.reset');

    Route::post('/admin/system/branding/preset', [CompanyProfileController::class, 'applyThemePreset'])
        ->name('admin.system.branding.preset');

    // -------------------------------------------------
    // Queue and worker operations
    // -------------------------------------------------
    Route::get('/admin/system/queues-workers', [QueueWorkerController::class, 'index'])
        ->name('admin.system.queues-workers.index');

    Route::post('/admin/system/queues-workers/restart', [QueueWorkerController::class, 'restartWorkers'])
        ->name('admin.system.queues-workers.restart');

    Route::post('/admin/system/queues-workers/clear', [QueueWorkerController::class, 'clearQueue'])
        ->name('admin.system.queues-workers.clear');

    Route::post('/admin/system/queues-workers/failed/retry', [QueueWorkerController::class, 'retryFailed'])
        ->name('admin.system.queues-workers.failed.retry');

    Route::post('/admin/system/queues-workers/failed/flush', [QueueWorkerController::class, 'flushFailed'])
        ->name('admin.system.queues-workers.failed.flush');
});
