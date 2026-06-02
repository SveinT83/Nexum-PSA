<?php

use App\Modules\Calendar\Controllers\Api\V1\CalendarController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('calendars', [CalendarController::class, 'calendars'])
    ->name('calendars.index')
    ->middleware(CheckAbilities::class.':calendar.read');

Route::get('calendar/events', [CalendarController::class, 'events'])
    ->name('calendar.events.index')
    ->middleware(CheckAbilities::class.':calendar.read');

Route::post('calendar/events', [CalendarController::class, 'storeEvent'])
    ->name('calendar.events.store')
    ->middleware(CheckAbilities::class.':calendar.create');

Route::get('calendar/events/{event}', [CalendarController::class, 'showEvent'])
    ->name('calendar.events.show')
    ->middleware(CheckAbilities::class.':calendar.read');

Route::match(['put', 'patch'], 'calendar/events/{event}', [CalendarController::class, 'updateEvent'])
    ->name('calendar.events.update')
    ->middleware(CheckAbilities::class.':calendar.update');

Route::delete('calendar/events/{event}', [CalendarController::class, 'destroyEvent'])
    ->name('calendar.events.destroy')
    ->middleware(CheckAbilities::class.':calendar.delete');
