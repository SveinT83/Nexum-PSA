<?php

use App\Modules\Clients\Controllers\Admin\ClientFormatSettingsController;
use App\Modules\Clients\Controllers\Tech\ClientController;
use Illuminate\Support\Facades\Route;

Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
Route::get('/clients/index', [ClientController::class, 'index'])->name('client.index');
Route::get('/clients/create', [ClientController::class, 'create'])->name('clients.create');
Route::post('/clients/store', [ClientController::class, 'store'])->name('clients.store');
Route::get('/clients/show/{client}', [ClientController::class, 'show'])->name('clients.show');

// Sites
Route::get('/clients/sites/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'index'])->name('clients.sites.index');
Route::get('/clients/sites/show/{site}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'show'])->name('clients.sites.show');
Route::get('/clients/sites/edit/{site}/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'edit'])->name('clients.sites.edit');
Route::put('/clients/sites/update/{site}/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'update'])->name('clients.sites.update');
Route::delete('/clients/sites/destroy/{site}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'destroy'])->name('clients.sites.destroy');
Route::get('/clients/sites/create/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'create'])->name('clients.sites.create');
Route::post('/clients/sites/store/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'store'])->name('clients.sites.store');

// Users
Route::get('/clients/users/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'index'])->name('clients.users.index');
Route::get('/clients/user_management/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'index'])->name('clients.user_management.index');
Route::get('/clients/user/show/{ClientUser}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'show'])->name('clients.user.show');
Route::get('/clients/user/create/{client}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'create'])->name('clients.user.create');
Route::post('/clients/user/store/{client}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'store'])->name('clients.user.store');
Route::get('/clients/user/edit/{ClientUser}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'edit'])->name('clients.user.edit');
Route::put('/clients/user/update/{ClientUser}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'update'])->name('clients.user.update');
Route::delete('/clients/user/delete/{ClientUser}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'delete'])->name('clients.user.delete');

// Settings
Route::get('/clients/{client}/settings', [\App\Modules\Clients\Controllers\Tech\ClientSettingsController::class, 'edit'])->name('clients.settings.edit');
Route::put('/clients/{client}/settings', [\App\Modules\Clients\Controllers\Tech\ClientSettingsController::class, 'update'])->name('clients.settings.update');

Route::middleware('admin')->group(function (): void {
    Route::get('/admin/settings/clients/client-formats', [ClientFormatSettingsController::class, 'index'])
        ->name('admin.settings.clients.client-formats');

    Route::post('/admin/settings/clients/client-formats', [ClientFormatSettingsController::class, 'store'])
        ->name('admin.settings.clients.client-formats.store');

    Route::patch('/admin/settings/clients/client-formats/{clientFormat}', [ClientFormatSettingsController::class, 'update'])
        ->name('admin.settings.clients.client-formats.update');
});
