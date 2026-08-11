<?php

use App\Modules\Notification\Controllers\Admin\NotificationChannelController;
use App\Modules\Notification\Controllers\Admin\WebPushDeviceController as AdminWebPushDeviceController;
use App\Modules\Notification\Controllers\NotificationOpenController;
use App\Modules\Notification\Controllers\NotificationSettingsController;
use App\Modules\Notification\Controllers\WebPushSelfTestController;
use App\Modules\Notification\Controllers\WebPushSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notification Module Routes
|--------------------------------------------------------------------------
|
| User-facing notification preferences and admin channel management.
|
*/

// User notification preferences (authenticated users)
Route::middleware(['auth'])->group(function () {
    Route::get('/profile/notifications', [NotificationSettingsController::class, 'show'])
        ->name('profile.notifications');
    Route::post('/profile/notifications', [NotificationSettingsController::class, 'update'])
        ->name('profile.notifications.update');
    Route::get('/profile/notifications/open/{notification}', NotificationOpenController::class)
        ->name('profile.notifications.open');

    Route::prefix('/profile/notifications/web-push')
        ->name('profile.notifications.web-push.')
        ->group(function (): void {
            Route::get('/devices', [WebPushSubscriptionController::class, 'index'])
                ->name('devices.index');
            Route::post('/devices', [WebPushSubscriptionController::class, 'store'])
                ->name('devices.store');
            Route::post('/devices/current', [WebPushSubscriptionController::class, 'current'])
                ->name('devices.current');
            Route::delete('/devices/{subscription}', [WebPushSubscriptionController::class, 'destroy'])
                ->name('devices.destroy');
            Route::post('/test', [WebPushSelfTestController::class, 'store'])
                ->middleware('throttle:3,10')
                ->name('test');
        });
});

// Admin notification channel management
Route::middleware(['admin'])->group(function () {
    Route::get('/admin/notification-channels/web-push/devices', [AdminWebPushDeviceController::class, 'index'])
        ->name('admin.notification-channels.web-push.devices.index');
    Route::delete('/admin/notification-channels/web-push/devices/{subscription}', [AdminWebPushDeviceController::class, 'destroy'])
        ->name('admin.notification-channels.web-push.devices.destroy');
    Route::get('/admin/notification-channels', [NotificationChannelController::class, 'index'])
        ->name('admin.notification-channels.index');
    Route::get('/admin/notification-channels/{channel}/edit', [NotificationChannelController::class, 'edit'])
        ->name('admin.notification-channels.edit');
    Route::put('/admin/notification-channels/{channel}', [NotificationChannelController::class, 'update'])
        ->name('admin.notification-channels.update');
    Route::post('/admin/notification-channels/{channel}/test', [NotificationChannelController::class, 'test'])
        ->name('admin.notification-channels.test');
});
