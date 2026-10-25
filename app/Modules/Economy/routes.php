<?php

use App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess;
use App\Modules\Economy\Controllers\Admin\EconomySettingsController;
use App\Modules\Economy\Controllers\Portal\PortalEconomyOrderController;
use App\Modules\Economy\Controllers\Tech\EconomyController;
use Illuminate\Support\Facades\Route;

// API and existing portal sessions are separate from staff PAT authorization.
if (($economyPortalApiRoutes ?? false) === true) {
    Route::middleware(['web', 'auth:web', EnsureCustomerPortalAccess::class, \App\Modules\Economy\Http\Middleware\EconomyApiErrors::class, \App\Modules\Economy\Http\Middleware\EconomyApiIds::class])
        ->prefix('economy/portal/orders')->name('economy.portal.orders.')->group(function (): void {
            Route::get('/', [\App\Modules\Economy\Controllers\Api\V1\PortalEconomyController::class, 'index'])->name('index');
            Route::get('/context', [\App\Modules\Economy\Controllers\Api\V1\PortalEconomyController::class, 'context'])->name('context');
            Route::get('/{order}/lines', [\App\Modules\Economy\Controllers\Api\V1\PortalEconomyController::class, 'lines'])->name('lines');
            Route::get('/{order}', [\App\Modules\Economy\Controllers\Api\V1\PortalEconomyController::class, 'show'])->name('show');
        });

    return;
}

if (($economyApiRoutes ?? false) === true) {
    Route::middleware([\App\Modules\Economy\Http\Middleware\EconomyApiErrors::class, \App\Modules\Economy\Http\Middleware\EconomyApiIds::class, \App\Modules\Economy\Http\Middleware\EconomyApiGuard::class])->prefix('economy')->name('economy.')->group(function (): void {
        $controller = \App\Modules\Economy\Controllers\Api\V1\EconomyController::class;
        $routes = [
            ['get', 'orders', 'index', 'orders.index', 'economy.read', ['economy.order_manage']],
            ['get', 'orders/context', 'context', 'orders.context', 'economy.read', ['economy.order_manage']],
            ['get', 'orders/generate/preview', 'generationPreview', 'orders.generate.preview', 'economy.create', ['economy.generate_orders']],
            ['post', 'orders/generate', 'generate', 'orders.generate', 'economy.create', ['economy.generate_orders']],
            ['get', 'settings', 'settings', 'settings.show', 'economy.settings.read', ['economy.manage_settings']],
            ['patch', 'settings', 'updateSettings', 'settings.update', 'economy.settings.update', ['economy.manage_settings']],
            ['get', 'operations/{operation}', 'operation', 'operations.show', 'economy.operations.read', []],
            ['get', 'orders/{order}/lines', 'lines', 'orders.lines', 'economy.read', ['economy.order_manage']],
            ['get', 'orders/{order}', 'show', 'orders.show', 'economy.read', ['economy.order_manage']],
            ['post', 'orders/{order}/ready', 'markReady', 'orders.ready', 'economy.update', ['economy.order_manage']],
            ['post', 'orders/{order}/draft', 'markDraft', 'orders.draft', 'economy.update', ['economy.order_manage']],
            ['post', 'orders/{order}/invoiced', 'markInvoiced', 'orders.invoiced', 'economy.update', ['economy.order_manage']],
            ['post', 'orders/{order}/portal-visibility/preview', 'visibilityPreview', 'orders.portal-visibility.preview', 'economy.update', ['economy.order_manage']],
            ['post', 'orders/{order}/portal-visibility', 'visibility', 'orders.portal-visibility.update', 'economy.update', ['economy.order_manage']],
            ['delete', 'orders/{order}', 'destroyOrder', 'orders.destroy', 'economy.delete', ['economy.delete_orders']],
            ['delete', 'orders/{order}/lines/{line}', 'destroyLine', 'orders.lines.destroy', 'economy.delete', ['economy.delete_orders']],
        ];
        foreach ($routes as [$method, $uri, $action, $name, $ability, $permissions]) {
            Route::$method($uri, [$controller, $action])->name($name)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.$ability)->defaults('economy_permissions', $permissions);
        }
        foreach ([['get', 'exports/preview', 'preview', 'exports.preview'], ['post', 'exports', 'store', 'exports.store'], ['get', 'exports/{run}', 'show', 'exports.show']] as [$method, $uri, $action, $name]) {
            $read = $action === 'show';
            Route::$method($uri, [\App\Modules\Economy\Controllers\Api\V1\EconomyExportController::class, $action])->name($name)
                ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.($read ? 'economy.read,data_exchange.read' : 'economy.export,data_exchange.run'))
                ->defaults('economy_permissions', $read ? ['economy.view', 'data_exchange.view'] : ['economy.view', 'data_exchange.run']);
        }
    });

    return;
}

if (($economyPortalRoutes ?? false) === true) {
    Route::middleware(['auth', EnsureCustomerPortalAccess::class])
        ->prefix('portal/orders')
        ->name('customer-portal.orders.')
        ->group(function (): void {
            Route::get('/', [PortalEconomyOrderController::class, 'index'])->name('index');
            Route::get('/{order}', [PortalEconomyOrderController::class, 'show'])->name('show');
        });

    return;
}

Route::get('/economy', [EconomyController::class, 'index'])
    ->name('economy.orders.index');

Route::post('/economy/generate', [EconomyController::class, 'generate'])
    ->name('economy.orders.generate');

Route::post('/economy/export', [EconomyController::class, 'export'])
    ->name('economy.orders.export');

Route::get('/economy/orders/{order}', [EconomyController::class, 'show'])
    ->name('economy.orders.show');

Route::post('/economy/orders/{order}/ready', [EconomyController::class, 'markReady'])
    ->name('economy.orders.ready');

Route::post('/economy/orders/{order}/draft', [EconomyController::class, 'markDraft'])
    ->name('economy.orders.draft');

Route::post('/economy/orders/{order}/invoiced', [EconomyController::class, 'markInvoiced'])
    ->name('economy.orders.invoiced');

Route::post('/economy/orders/{order}/portal-visibility', [EconomyController::class, 'updatePortalVisibility'])
    ->name('economy.orders.portal-visibility.update');

Route::delete('/economy/orders/{order}', [EconomyController::class, 'destroyOrder'])
    ->name('economy.orders.destroy');

Route::delete('/economy/orders/{order}/lines/{line}', [EconomyController::class, 'destroyLine'])
    ->name('economy.orders.lines.destroy');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/economy', [EconomySettingsController::class, 'index'])
        ->name('admin.settings.economy');

    Route::patch('/admin/settings/economy', [EconomySettingsController::class, 'update'])
        ->name('admin.settings.economy.update');
});
