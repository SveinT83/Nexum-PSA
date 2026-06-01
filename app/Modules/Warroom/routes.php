<?php

use App\Modules\Warroom\Controllers\Admin\WarroomSettingsController;
use App\Modules\Warroom\Controllers\Tech\WarroomController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/settings/warroom', [WarroomSettingsController::class, 'edit'])
    ->name('admin.settings.warroom');
Route::put('/admin/settings/warroom', [WarroomSettingsController::class, 'update'])
    ->name('admin.settings.warroom.update');

Route::get('/dashboard', WarroomController::class)
    ->name('dashboard');
