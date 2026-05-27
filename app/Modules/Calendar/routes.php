<?php

use App\Modules\Calendar\Controllers\Admin\CalendarSettingsController;
use App\Modules\Calendar\Controllers\Tech\CalendarController;
use Illuminate\Support\Facades\Route;

Route::get('/calendar', [CalendarController::class, 'index'])
    ->name('calendar.index');

Route::post('/calendar/events', [CalendarController::class, 'store'])
    ->name('calendar.events.store');

Route::patch('/calendar/events/{event}', [CalendarController::class, 'update'])
    ->name('calendar.events.update');

Route::delete('/calendar/events/{event}', [CalendarController::class, 'destroy'])
    ->name('calendar.events.destroy');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/calendar', [CalendarSettingsController::class, 'index'])
        ->name('admin.settings.calendar');

    Route::patch('/admin/settings/calendar', [CalendarSettingsController::class, 'update'])
        ->name('admin.settings.calendar.update');

    Route::post('/admin/settings/calendar/calendars', [CalendarSettingsController::class, 'storeCalendar'])
        ->name('admin.settings.calendar.calendars.store');

    Route::delete('/admin/settings/calendar/calendars/{calendar}', [CalendarSettingsController::class, 'destroyCalendar'])
        ->name('admin.settings.calendar.calendars.destroy');

    Route::post('/admin/settings/calendar/calendars/{calendar}/access', [CalendarSettingsController::class, 'storeAccess'])
        ->name('admin.settings.calendar.access.store');

    Route::delete('/admin/settings/calendar/access/{access}', [CalendarSettingsController::class, 'destroyAccess'])
        ->name('admin.settings.calendar.access.destroy');
});
