<?php

use App\Modules\Notification\Controllers\NotificationSettingsController;
use App\Modules\Notification\Controllers\Admin\NotificationChannelController;
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
});

// Admin notification channel management
Route::middleware(['admin'])->group(function () {
    Route::get('/admin/notification-channels', [NotificationChannelController::class, 'index'])
        ->name('admin.notification-channels.index');
    Route::get('/admin/notification-channels/{channel}/edit', [NotificationChannelController::class, 'edit'])
        ->name('admin.notification-channels.edit');
    Route::put('/admin/notification-channels/{channel}', [NotificationChannelController::class, 'update'])
        ->name('admin.notification-channels.update');
    Route::post('/admin/notification-channels/{channel}/test', [NotificationChannelController::class, 'test'])
        ->name('admin.notification-channels.test');
});