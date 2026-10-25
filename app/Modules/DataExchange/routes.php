<?php

use App\Modules\DataExchange\Controllers\Admin\DataExchangeController;
use App\Modules\DataExchange\Controllers\Admin\DataExchangeDeliveryTargetController;
use App\Modules\DataExchange\Controllers\Admin\DataExchangeImportController;
use App\Modules\DataExchange\Controllers\Admin\DataExchangeProfileController;
use App\Modules\DataExchange\Controllers\Admin\DataExchangeRunController;
use App\Modules\DataExchange\Controllers\Admin\DataExchangeScheduleController;
use Illuminate\Support\Facades\Route;

// The compatibility loader selects this API branch; all domain declarations remain here.
if (($dataExchangeApiRoutes ?? false) === true) {
    Route::middleware(\App\Modules\DataExchange\Http\Middleware\DataExchangeApiGuard::class)->prefix('data-exchange')->name('data-exchange.')->group(function (): void {
        $controller = \App\Modules\DataExchange\Controllers\Api\V1\DataExchangeController::class;
        $routes = [
            ['get', 'context', 'context', 'context', 'read', 'view'],
            ['get', 'sources', 'sources', 'sources.index', 'read', 'view'],
            ['get', 'sources/{sourceKey}', 'source', 'sources.show', 'read', 'view'],
            ['get', 'profiles', 'profiles', 'profiles.index', 'read', 'view'],
            ['post', 'profiles', 'storeProfile', 'profiles.store', 'manage', 'manage'],
            ['post', 'profiles/templates', 'templates', 'profiles.templates', 'manage', 'manage'],
            ['get', 'profiles/{profile}', 'profile', 'profiles.show', 'read', 'view'],
            ['patch', 'profiles/{profile}', 'updateProfile', 'profiles.update', 'manage', 'manage'],
            ['get', 'profiles/{profile}/runs/preview', 'exportPreview', 'profiles.runs.preview', 'run', 'run'],
            ['post', 'profiles/{profile}/runs', 'trigger', 'profiles.runs.store', 'run', 'run'],
            ['get', 'runs', 'runs', 'runs.index', 'read', 'view'],
            ['get', 'runs/{run}', 'run', 'runs.show', 'read', 'view'],
            ['get', 'files', 'files', 'files.index', 'read', 'view'],
            ['get', 'files/{file}', 'file', 'files.show', 'read', 'view'],
            ['get', 'files/{file}/download', 'download', 'files.download', 'download', 'download'],
            ['get', 'imports', 'imports', 'imports.index', 'read', 'view'],
            ['post', 'imports/dry-run', 'dryRun', 'imports.dry-run', 'import', 'import'],
            ['get', 'imports/{preview}', 'preview', 'imports.show', 'read', 'view'],
            ['post', 'imports/{preview}/commit', 'commit', 'imports.commit', 'approve_import', 'approve_import'],
            ['get', 'schedules', 'schedules', 'schedules.index', 'schedule', 'schedule'],
            ['post', 'schedules', 'storeSchedule', 'schedules.store', 'schedule', 'schedule'],
            ['get', 'schedules/{schedule}', 'schedule', 'schedules.show', 'schedule', 'schedule'],
            ['patch', 'schedules/{schedule}', 'updateSchedule', 'schedules.update', 'schedule', 'schedule'],
            ['get', 'delivery-targets', 'targets', 'delivery-targets.index', 'delivery', 'delivery'],
            ['post', 'delivery-targets', 'storeTarget', 'delivery-targets.store', 'delivery', 'delivery'],
            ['get', 'delivery-targets/{deliveryTarget}', 'target', 'delivery-targets.show', 'delivery', 'delivery'],
            ['patch', 'delivery-targets/{deliveryTarget}', 'updateTarget', 'delivery-targets.update', 'delivery', 'delivery'],
            ['post', 'files/{file}/deliveries/preview', 'deliveryPreview', 'files.deliveries.preview', 'delivery', 'delivery'],
            ['post', 'files/{file}/deliveries', 'deliver', 'files.deliveries.store', 'delivery', 'delivery'],
            ['get', 'delivery-attempts', 'attempts', 'delivery-attempts.index', 'delivery', 'delivery'],
            ['get', 'delivery-attempts/{attempt}', 'attempt', 'delivery-attempts.show', 'delivery', 'delivery'],
            ['post', 'delivery-attempts/{attempt}/retry', 'retryDelivery', 'delivery-attempts.retry', 'delivery', 'delivery'],
            ['post', 'delivery-attempts/{attempt}/reconcile', 'reconcileDelivery', 'delivery-attempts.reconcile', 'delivery', 'delivery'],
            ['get', 'audit-events', 'audit', 'audit-events.index', 'read', 'view'],
            ['get', 'operations/{operation}', 'operation', 'operations.show', 'read', 'view'],
        ];
        foreach ($routes as [$method, $uri, $action, $name, $ability, $permission]) {
            Route::$method($uri, [$controller, $action])->name($name)
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':data_exchange.'.$ability)
                ->defaults('dx_permission', $permission)->defaults('dx_ability', $ability);
        }
    });

    return;
}

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
