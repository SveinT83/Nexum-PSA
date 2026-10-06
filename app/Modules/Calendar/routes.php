<?php

use App\Modules\Calendar\Controllers\Admin\CalendarSettingsController;
use App\Modules\Calendar\Controllers\Tech\CalendarController;
use Illuminate\Support\Facades\Route;

// Work-plan endpoints share the Calendar actions across browser and API.
if (($tdpsaLoadingApiRoutes ?? false) === true) {
    Route::middleware(\App\Modules\UserManagement\Http\Middleware\EnsureEmployeeWorkPlan::class)->group(function () {
        Route::get('calendar/work-plan/blocks', [\App\Modules\Calendar\Controllers\WorkPlanBlockController::class, 'index'])
            ->name('calendar.work-plan.blocks.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.work-plan.read');
        Route::post('calendar/work-plan/blocks', [\App\Modules\Calendar\Controllers\WorkPlanBlockController::class, 'store'])
            ->name('calendar.work-plan.blocks.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.work-plan.write');
        Route::patch('calendar/work-plan/blocks/{event}', [\App\Modules\Calendar\Controllers\WorkPlanBlockController::class, 'update'])
            ->name('calendar.work-plan.blocks.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.work-plan.write');
        Route::delete('calendar/work-plan/blocks/{event}', [\App\Modules\Calendar\Controllers\WorkPlanBlockController::class, 'destroy'])
            ->name('calendar.work-plan.blocks.destroy')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.work-plan.write');
    });

    return;
}
Route::middleware(\App\Modules\UserManagement\Http\Middleware\EnsureEmployeeWorkPlan::class)->group(function () {
    Route::post('/profile/work-plan/blocks', [\App\Modules\Calendar\Controllers\WorkPlanBlockController::class, 'store'])->name('profile.work-plan.blocks.store');
    Route::patch('/profile/work-plan/blocks/{event}', [\App\Modules\Calendar\Controllers\WorkPlanBlockController::class, 'update'])->name('profile.work-plan.blocks.update');
    Route::delete('/profile/work-plan/blocks/{event}', [\App\Modules\Calendar\Controllers\WorkPlanBlockController::class, 'destroy'])->name('profile.work-plan.blocks.destroy');
});

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
