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

    Route::get('calendars', [\App\Modules\Calendar\Controllers\Api\V1\CalendarController::class, 'calendars'])
        ->name('calendars.index')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.read');

    Route::get('calendar/events', [\App\Modules\Calendar\Controllers\Api\V1\CalendarController::class, 'events'])
        ->name('calendar.events.index')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.read');

    Route::post('calendar/events', [\App\Modules\Calendar\Controllers\Api\V1\CalendarController::class, 'storeEvent'])
        ->name('calendar.events.store')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.create');

    Route::get('calendar/events/{event}', [\App\Modules\Calendar\Controllers\Api\V1\CalendarController::class, 'showEvent'])
        ->name('calendar.events.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.read');

    Route::match(['put', 'patch'], 'calendar/events/{event}', [\App\Modules\Calendar\Controllers\Api\V1\CalendarController::class, 'updateEvent'])
        ->name('calendar.events.update')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.update');

    Route::delete('calendar/events/{event}', [\App\Modules\Calendar\Controllers\Api\V1\CalendarController::class, 'destroyEvent'])
        ->name('calendar.events.destroy')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.delete');

    Route::post('calendar/events/{event}/delete-preview', [\App\Modules\Calendar\Controllers\Api\V1\CalendarController::class, 'deletePreview'])->name('calendar.events.delete-preview')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.delete');
    Route::get('calendar/context', [\App\Modules\Calendar\Controllers\Api\V1\CalendarController::class, 'context'])->name('calendar.context')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.read');
    Route::post('calendar/initialize', [\App\Modules\Calendar\Controllers\Api\V1\CalendarController::class, 'initialize'])->name('calendar.initialize')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.manage');
    Route::get('calendar/admin/settings', [\App\Modules\Calendar\Controllers\Api\V1\CalendarAdministrationController::class, 'index'])->name('calendar.admin.settings')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.manage');
    Route::patch('calendar/admin/settings', [\App\Modules\Calendar\Controllers\Api\V1\CalendarAdministrationController::class, 'updateSettings'])->name('calendar.admin.settings.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.manage');
    Route::post('calendar/admin/calendars', [\App\Modules\Calendar\Controllers\Api\V1\CalendarAdministrationController::class, 'createCalendar'])->name('calendar.admin.calendars.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.manage');
    Route::get('calendar/admin/calendars/{calendar}/access', [\App\Modules\Calendar\Controllers\Api\V1\CalendarAdministrationController::class, 'access'])->name('calendar.admin.access.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.manage');
    Route::post('calendar/admin/calendars/{calendar}/preview', [\App\Modules\Calendar\Controllers\Api\V1\CalendarAdministrationController::class, 'preview'])->name('calendar.admin.preview')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.manage');
    Route::delete('calendar/admin/calendars/{calendar}', [\App\Modules\Calendar\Controllers\Api\V1\CalendarAdministrationController::class, 'archive'])->name('calendar.admin.calendars.archive')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.manage');
    Route::post('calendar/admin/calendars/{calendar}/access', [\App\Modules\Calendar\Controllers\Api\V1\CalendarAdministrationController::class, 'upsertAccess'])->name('calendar.admin.access.upsert')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.manage');
    Route::delete('calendar/admin/calendars/{calendar}/access/{access}', [\App\Modules\Calendar\Controllers\Api\V1\CalendarAdministrationController::class, 'revokeAccess'])->name('calendar.admin.access.revoke')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.manage');
    Route::get('calendar/availability', [\App\Modules\Calendar\Controllers\Api\V1\CalendarAvailabilityController::class, 'index'])->name('calendar.availability')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.free-busy.read');
    Route::get('calendar/availability/users', [\App\Modules\Calendar\Controllers\Api\V1\CalendarAvailabilityController::class, 'users'])->name('calendar.availability.users')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.free-busy.read');
    Route::get('calendar/admin/references', [\App\Modules\Calendar\Controllers\Api\V1\CalendarAdministrationController::class, 'references'])->name('calendar.admin.references')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':calendar.manage');

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
