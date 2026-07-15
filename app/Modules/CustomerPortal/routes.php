<?php

use App\Modules\CustomerPortal\Controllers\Admin\CustomerPortalAdminController;
use App\Modules\CustomerPortal\Controllers\Portal\CustomerPortalDashboardController;
use App\Modules\CustomerPortal\Controllers\Portal\CustomerPortalMembershipController;
use App\Modules\CustomerPortal\Controllers\Portal\CustomerPortalNotificationController;
use App\Modules\CustomerPortal\Controllers\Public\CustomerPortalInvitationController;
use App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess;
use Illuminate\Support\Facades\Route;

if (($customerPortalPublicRoutes ?? false) === true) {
    Route::get('/portal/invitations/{token}', [CustomerPortalInvitationController::class, 'show'])
        ->middleware('throttle:30,1')
        ->name('customer-portal.invitations.accept');

    Route::post('/portal/invitations/{token}', [CustomerPortalInvitationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('customer-portal.invitations.accept.store');

    Route::middleware(['auth', EnsureCustomerPortalAccess::class])
        ->prefix('portal')
        ->name('customer-portal.')
        ->group(function (): void {
            Route::get('/', CustomerPortalDashboardController::class)
                ->name('dashboard');
            Route::post('/memberships/{membership}/switch', [CustomerPortalMembershipController::class, 'switch'])
                ->name('memberships.switch');
            Route::get('/notifications', [CustomerPortalNotificationController::class, 'index'])
                ->name('notifications.index');
            Route::post('/notifications/{notification}/open', [CustomerPortalNotificationController::class, 'open'])
                ->name('notifications.open');
            Route::post('/notifications/{notification}/read', [CustomerPortalNotificationController::class, 'markRead'])
                ->name('notifications.read');
            Route::post('/notifications/read-all', [CustomerPortalNotificationController::class, 'markAllRead'])
                ->name('notifications.read-all');
            Route::post('/notifications/preferences', [CustomerPortalNotificationController::class, 'updatePreferences'])
                ->name('notifications.preferences.update');
        });

    return;
}

Route::middleware('admin')
    ->prefix('/admin/system/customer-portal')
    ->name('admin.system.customer-portal.')
    ->group(function (): void {
        Route::get('/', [CustomerPortalAdminController::class, 'index'])
            ->name('index');
        Route::post('/invitations', [CustomerPortalAdminController::class, 'storeInvitation'])
            ->name('invitations.store');
        Route::post('/invitations/{invitation}/revoke', [CustomerPortalAdminController::class, 'revokeInvitation'])
            ->name('invitations.revoke');
        Route::post('/memberships/{membership}/disable', [CustomerPortalAdminController::class, 'disableMembership'])
            ->name('memberships.disable');
    });
