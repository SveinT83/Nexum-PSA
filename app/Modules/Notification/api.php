<?php

use App\Modules\Notification\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('notifications', [NotificationController::class, 'index'])
    ->name('notifications.index')
    ->middleware(CheckAbilities::class.':notifications.read');

Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])
    ->name('notifications.read')
    ->middleware(CheckAbilities::class.':notifications.update');

Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])
    ->name('notifications.read-all')
    ->middleware(CheckAbilities::class.':notifications.update');
