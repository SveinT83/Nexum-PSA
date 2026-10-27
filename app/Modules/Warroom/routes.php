<?php

use App\Modules\Warroom\Controllers\Api\V1\WarroomController as WarroomApiController;
use App\Modules\Warroom\Support\WarroomAccess;

if (($warroomApiRoutes ?? false) === true) {
    // Explicit abilities always intersect native actor permissions in WarroomAccess.
    \Illuminate\Support\Facades\Route::prefix('warroom')->name('warroom.')->middleware(WarroomAccess::class)->group(function (): void {
        $read = ['warroom_ability' => 'warroom.read', 'warroom_permission' => 'warroom.view'];
        foreach ([
            ['context', 'context', 'context', []],
            ['dashboard', 'dashboard', 'dashboard', []],
            ['my-day', 'myDay', 'my-day', ['focus', 'page', 'per_page']],
            ['my-day/overdue', 'overdue', 'my-day.overdue', ['page', 'per_page']],
        ] as [$path, $method, $name, $fields]) {
            \Illuminate\Support\Facades\Route::get($path, [WarroomApiController::class, $method])->name($name)
                ->defaults('warroom_ability', $read['warroom_ability'])->defaults('warroom_permission', $read['warroom_permission'])
                ->defaults('warroom_fields', $fields);
        }
        \Illuminate\Support\Facades\Route::get('settings', [WarroomApiController::class, 'settings'])->name('settings.show')
            ->defaults('warroom_ability', 'warroom.settings.read')->defaults('warroom_permission', 'warroom.manage_settings');
        \Illuminate\Support\Facades\Route::put('settings', [WarroomApiController::class, 'updateSettings'])->name('settings.update')
            ->defaults('warroom_ability', 'warroom.settings.manage')->defaults('warroom_permission', 'warroom.manage_settings')
            ->defaults('warroom_fields', ['due_soon_hours', 'inbox_recent_hours', 'latest_tickets_limit',
                'latest_alerts_limit', 'calendar_today_limit', 'recent_integrations_limit', 'enabled_sections']);
        \Illuminate\Support\Facades\Route::get('operations/{command}', [WarroomApiController::class, 'operation'])->name('operations.show')
            ->whereUuid('command')->defaults('warroom_ability', 'warroom.settings.manage')->defaults('warroom_permission', 'warroom.manage_settings');
    });

    return;
}

use App\Modules\Warroom\Controllers\Admin\WarroomSettingsController;
use App\Modules\Warroom\Controllers\Tech\MyDayController;
use App\Modules\Warroom\Controllers\Tech\WarroomController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/settings/warroom', [WarroomSettingsController::class, 'edit'])
    ->name('admin.settings.warroom');
Route::put('/admin/settings/warroom', [WarroomSettingsController::class, 'update'])
    ->name('admin.settings.warroom.update');

Route::get('/dashboard', WarroomController::class)
    ->name('dashboard');

Route::get('/my-day', MyDayController::class)
    ->name('my-day.index');
