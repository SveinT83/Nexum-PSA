<?php

use App\Modules\Booking\Controllers\Admin\BookingController as AdminBookingController;
use App\Modules\Booking\Controllers\Admin\BookingRequestController as AdminBookingRequestController;
use App\Modules\Booking\Controllers\Admin\BookingServiceSettingController as AdminBookingServiceSettingController;
use App\Modules\Booking\Controllers\Public\BookingController as PublicBookingController;
use Illuminate\Support\Facades\Route;

if (($bookingPublicRoutes ?? false) === true) {
    Route::get('/booking', [PublicBookingController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('booking.index');

    Route::get('/booking/{setting:slug}', [PublicBookingController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('booking.services.show');

    Route::post('/booking/{setting:slug}', [PublicBookingController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('booking.services.store');

    Route::get('/booking/{setting:slug}/thanks', [PublicBookingController::class, 'thanks'])
        ->middleware('throttle:60,1')
        ->name('booking.services.thanks');

    return;
}

Route::middleware('admin')
    ->prefix('/admin/system/booking')
    ->name('admin.system.booking.')
    ->group(function (): void {
        Route::get('/', [AdminBookingController::class, 'index'])->name('index');
        Route::get('/settings/create', [AdminBookingServiceSettingController::class, 'create'])->name('settings.create');
        Route::post('/settings', [AdminBookingServiceSettingController::class, 'store'])->name('settings.store');
        Route::get('/settings/{setting:slug}/edit', [AdminBookingServiceSettingController::class, 'edit'])->name('settings.edit');
        Route::put('/settings/{setting:slug}', [AdminBookingServiceSettingController::class, 'update'])->name('settings.update');
        Route::post('/settings/{setting:slug}/toggle', [AdminBookingServiceSettingController::class, 'toggle'])->name('settings.toggle');
        Route::get('/requests/{bookingRequest}', [AdminBookingRequestController::class, 'show'])->name('requests.show');
        Route::post('/requests/{bookingRequest}/confirm', [AdminBookingRequestController::class, 'confirm'])->name('requests.confirm');
        Route::post('/requests/{bookingRequest}/decline', [AdminBookingRequestController::class, 'decline'])->name('requests.decline');
    });
