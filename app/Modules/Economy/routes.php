<?php

use App\Modules\Economy\Controllers\Admin\EconomySettingsController;
use App\Modules\Economy\Controllers\Portal\PortalEconomyOrderController;
use App\Modules\Economy\Controllers\Tech\EconomyController;
use App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess;
use Illuminate\Support\Facades\Route;

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
