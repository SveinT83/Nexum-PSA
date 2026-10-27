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

// The API entry loader and the browser entry loader evaluate this file independently.
if ($systemApiRoutes ?? false) {
    $controller = \App\Modules\System\Controllers\Api\V1\SystemController::class;
    $companyFields = array_keys(\App\Modules\System\Support\CompanyProfileRules::company());
    $brandingFields = array_keys(\App\Modules\System\Support\CompanyProfileRules::branding());
    Route::prefix('system')->name('system.')->middleware(\App\Modules\System\Support\SystemApiAccess::class)
        ->group(function () use ($controller, $companyFields, $brandingFields) {
            $register = function ($method, $path, $handler, $name, $scope, $permission, $fields = [], $action = null) use ($controller) {
                $route = Route::$method($path, [$controller, $handler])->name($name)
                    ->defaults('system_scope', $scope)->defaults('system_permission', $permission)
                    ->defaults('system_fields', $fields);
                if ($action) {
                    $route->defaults('system_action', $action);
                }
            };
            $register('get', '/context', 'context', 'context', 'system.read', 'system.view');
            $register('get', '/version-status', 'version', 'version-status', 'system.read', 'system.view');
            $register('get', '/company-profile', 'company', 'company-profile.show', 'system.settings.read', 'system.settings_manage');
            $register('put', '/company-profile', 'updateCompany', 'company-profile.update', 'system.settings.write', 'system.settings_manage', $companyFields);
            $register('get', '/branding', 'branding', 'branding.show', 'system.settings.read', 'system.settings_manage');
            $register('put', '/branding', 'updateBranding', 'branding.update', 'system.settings.write', 'system.settings_manage', array_values(array_diff($brandingFields, ['logo', 'logo_light', 'logo_dark'])));
            $register('post', '/branding', 'updateBranding', 'branding.upload', 'system.settings.write', 'system.settings_manage', $brandingFields);
            $register('get', '/branding/presets', 'presets', 'branding.presets', 'system.settings.read', 'system.settings_manage');
            $register('post', '/branding/preview', 'previewBranding', 'branding.preview', 'system.settings.read', 'system.settings_manage',
                array_values(array_diff($brandingFields, ['logo', 'logo_light', 'logo_dark'])));
            $register('post', '/branding/preset', 'applyPreset', 'branding.preset', 'system.settings.write', 'system.settings_manage', ['preset']);
            $register('put', '/branding/reset', 'reset', 'branding.reset', 'system.settings.write', 'system.settings_manage');
            $register('get', '/queues-workers', 'queues', 'queues-workers.show', 'system.queue.read', 'system.queue_manage');
            $register('post', '/queues-workers/preview', 'previewMaintenance', 'queues-workers.preview', 'system.queue.read', 'system.queue_manage', ['action', 'queue', 'job_id', 'all']);
            foreach (['restart' => 'restart', 'clear' => 'clear', 'failed/retry' => 'retry', 'failed/flush' => 'flush'] as $path => $action) {
                $fields = match ($action) {
                    'clear' => ['confirmation', 'queue'], 'retry' => ['confirmation', 'job_id', 'all'], default => ['confirmation'],
                };
                $register('post', '/queues-workers/'.$path, 'maintenance', 'queues-workers.'.$action,
                    'system.queue.'.$action, 'system.queue_manage', $fields, $action);
            }
            $register('get', '/operations', 'operations', 'operations.index', 'system.operations.read', null, ['page', 'per_page']);
            $register('get', '/operations/{operation}', 'operation', 'operations.show', 'system.operations.read', null);
        });

    return;
}

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
