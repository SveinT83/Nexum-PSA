<?php

// Official calendar-only API is loaded explicitly by the legacy api.php entry.
if (($tdpsaLoadingNextcloudCalendarApiRoutes ?? false) === true) {
    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'connections'])->name('nextcloud.calendar.connections')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections/{connection}', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'show'])->name('nextcloud.calendar.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections/{connection}/mappings', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'mappings'])->name('nextcloud.calendar.mappings')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections/{connection}/attempts', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'attempts'])->name('nextcloud.calendar.attempts')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections/{connection}/attempts/{attempt}', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'attempt'])->name('nextcloud.calendar.attempt')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections/{connection}/conflicts', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'conflicts'])->name('nextcloud.calendar.conflicts')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/preview', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'preview'])->name('nextcloud.calendar.preview')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.preview');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/mappings', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'upsertMapping'])->name('nextcloud.calendar.upsertMapping')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.mapping');
    \Illuminate\Support\Facades\Route::delete('nextcloud/calendar/connections/{connection}/mappings/{mapping}', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'removeMapping'])->name('nextcloud.calendar.removeMapping')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.mapping');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/activation', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'activation'])->name('nextcloud.calendar.activation')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.activate');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/sync', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'sync'])->name('nextcloud.calendar.sync')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.sync');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/health', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'health'])->name('nextcloud.calendar.health')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.health');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/conflicts/{conflict}/preview', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarConflictController::class, 'preview'])->name('nextcloud.calendar.conflict.preview')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.reconcile');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/conflicts/{conflict}/apply', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarConflictController::class, 'apply'])->name('nextcloud.calendar.conflict.apply')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.reconcile');

    return;
}

use App\Modules\Nextcloud\Controllers\Admin\NextcloudConnectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Nextcloud Module Routes
|--------------------------------------------------------------------------
|
| These routes own Nextcloud connection setup and operational sync controls.
| They are loaded inside the authenticated tech route group.
|
*/

Route::middleware('admin')->group(function () {
    Route::get('/admin/system/nextcloud', [NextcloudConnectionController::class, 'index'])
        ->name('admin.nextcloud.connections.index');

    Route::get('/admin/system/nextcloud/connections/{connection}', [NextcloudConnectionController::class, 'show'])
        ->name('admin.nextcloud.connections.show');

    Route::post('/admin/system/nextcloud/connections', [NextcloudConnectionController::class, 'store'])
        ->name('admin.nextcloud.connections.store');

    Route::patch('/admin/system/nextcloud/connections/{connection}', [NextcloudConnectionController::class, 'update'])
        ->name('admin.nextcloud.connections.update');

    Route::delete('/admin/system/nextcloud/connections/{connection}', [NextcloudConnectionController::class, 'destroy'])
        ->name('admin.nextcloud.connections.destroy');

    Route::post('/admin/system/nextcloud/connections/{connection}/check', [NextcloudConnectionController::class, 'check'])
        ->name('admin.nextcloud.connections.check');

    Route::post('/admin/system/nextcloud/connections/{connection}/sync', [NextcloudConnectionController::class, 'sync'])
        ->name('admin.nextcloud.connections.sync');

    Route::post('/admin/system/nextcloud/connections/{connection}/test-talk-bot', [NextcloudConnectionController::class, 'testTalkBot'])
        ->name('admin.nextcloud.connections.test-talk-bot');

    Route::patch('/admin/system/nextcloud/connections/{connection}/talk-bot', [NextcloudConnectionController::class, 'updateTalkBot'])
        ->name('admin.nextcloud.connections.update-talk-bot');

    Route::post('/admin/system/nextcloud/connections/{connection}/users', [NextcloudConnectionController::class, 'storeUserMapping'])
        ->name('admin.nextcloud.connections.users.store');

    Route::post('/admin/system/nextcloud/connections/{connection}/groups', [NextcloudConnectionController::class, 'storeGroupMapping'])
        ->name('admin.nextcloud.connections.groups.store');

    Route::post('/admin/system/nextcloud/connections/{connection}/calendars', [NextcloudConnectionController::class, 'storeCalendarMapping'])
        ->name('admin.nextcloud.connections.calendars.store');

    Route::post('/admin/system/nextcloud/connections/{connection}/folders', [NextcloudConnectionController::class, 'storeFolderMapping'])
        ->name('admin.nextcloud.connections.folders.store');

    Route::post('/admin/system/nextcloud/connections/{connection}/folders/auto-match', [NextcloudConnectionController::class, 'autoMatchClientFolders'])
        ->name('admin.nextcloud.connections.folders.auto_match');

    Route::patch('/admin/system/nextcloud/connections/{connection}/folders', [NextcloudConnectionController::class, 'updateFolders'])
        ->name('admin.nextcloud.connections.folders.update');

    Route::delete('/admin/system/nextcloud/user-mappings/{mapping}', [NextcloudConnectionController::class, 'destroyUserMapping'])
        ->name('admin.nextcloud.user-mappings.destroy');

    Route::delete('/admin/system/nextcloud/group-mappings/{mapping}', [NextcloudConnectionController::class, 'destroyGroupMapping'])
        ->name('admin.nextcloud.group-mappings.destroy');

    Route::delete('/admin/system/nextcloud/calendar-mappings/{mapping}', [NextcloudConnectionController::class, 'destroyCalendarMapping'])
        ->name('admin.nextcloud.calendar-mappings.destroy');

    Route::delete('/admin/system/nextcloud/folder-mappings/{mapping}', [NextcloudConnectionController::class, 'destroyFolderMapping'])
        ->name('admin.nextcloud.folder-mappings.destroy');
});
