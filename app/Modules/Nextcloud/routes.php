<?php

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
