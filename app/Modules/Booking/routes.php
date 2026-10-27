<?php

use App\Modules\Booking\Controllers\Admin\BookingController as AdminBookingController;
use App\Modules\Booking\Controllers\Admin\BookingRequestController as AdminBookingRequestController;
use App\Modules\Booking\Controllers\Admin\BookingServiceSettingController as AdminBookingServiceSettingController;
use App\Modules\Booking\Controllers\Public\BookingController as PublicBookingController;
use Illuminate\Support\Facades\Route;

\Illuminate\Support\Facades\RateLimiter::for('booking-public-read', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('booking-read:'.$request->ip()));
\Illuminate\Support\Facades\RateLimiter::for('booking-public-submit', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by('booking-submit:'.$request->ip()));

// Official anonymous JSON surface keeps the same deliberately public intake authority.
if (($bookingPublicApiRoutes ?? false) === true) {
    Route::prefix('booking/public/services')->name('booking.public.')->middleware([\App\Modules\Booking\Support\BookingApiErrors::class])->group(function () {
        $controller = \App\Modules\Booking\Controllers\Api\V1\BookingController::class;
        Route::get('/', [$controller, 'publicServices'])->name('services')->middleware('throttle:booking-public-read');
        Route::get('/{setting:slug}', [$controller, 'publicContext'])->name('context')->middleware('throttle:booking-public-read');
        Route::get('/{setting:slug}/slots', [$controller, 'publicSlots'])->name('slots')->middleware('throttle:booking-public-read');
        Route::post('/{setting:slug}/requests', [$controller, 'submit'])->name('submit')->middleware('throttle:booking-public-submit');
    });

    return;
}

if (($tdpsaLoadingApiRoutes ?? false) === true) {
    $controller = \App\Modules\Booking\Controllers\Api\V1\BookingController::class;
    $endpoints = [
        ['get', 'context', 'context', 'booking.read', null],
        ['get', 'summary', 'summary', 'booking.read', 'booking.view'],
        ['get', 'settings', 'settings', 'booking.read', 'booking.view'],
        ['get', 'settings/{setting:slug}', 'setting', 'booking.read', 'booking.manage'],
        ['post', 'settings', 'saveSetting', 'booking.settings.write', 'booking.manage'],
        ['put', 'settings/{setting:slug}', 'updateSetting', 'booking.settings.write', 'booking.manage'],
        ['post', 'settings/{setting:slug}/toggle', 'toggle', 'booking.settings.write', 'booking.manage'],
        ['get', 'requests', 'requests', 'booking.read', 'booking.view'],
        ['get', 'requests/{bookingRequest}', 'bookingRequest', 'booking.read', 'booking.view'],
        ['get', 'requests/{bookingRequest}/events', 'events', 'booking.read', 'booking.view'],
        ['post', 'requests/{bookingRequest}/confirm', 'confirm', 'booking.requests.review', 'booking.request_review'],
        ['post', 'requests/{bookingRequest}/decline', 'decline', 'booking.requests.review', 'booking.request_review'],
        ['get', 'commands/{command}', 'command', 'booking.commands.read', null],
    ];
    foreach ($endpoints as [$method, $path, $action, $scope, $permission]) {
        Route::$method('booking/'.$path, [$controller, $action])->name('booking.'.$action)
            ->defaults('booking_permission', $permission)
            ->middleware([\App\Modules\Booking\Support\BookingApiErrors::class,
                \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.$scope,
                \App\Modules\Booking\Support\BookingApiAccess::class]);
    }

    return;
}

if (($bookingPublicRoutes ?? false) === true) {
    Route::get('/booking', [PublicBookingController::class, 'index'])
        ->middleware('throttle:booking-public-read')
        ->name('booking.index');

    Route::get('/booking/{setting:slug}', [PublicBookingController::class, 'show'])
        ->middleware('throttle:booking-public-read')
        ->name('booking.services.show');

    Route::post('/booking/{setting:slug}', [PublicBookingController::class, 'store'])
        ->middleware('throttle:booking-public-submit')
        ->name('booking.services.store');

    Route::get('/booking/{setting:slug}/thanks', [PublicBookingController::class, 'thanks'])
        ->middleware('throttle:booking-public-read')
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
