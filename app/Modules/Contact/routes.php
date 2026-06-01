<?php

use App\Modules\Contact\Controllers\Admin\ContactSettingsController;
use App\Modules\Contact\Controllers\Tech\ContactController;
use Illuminate\Support\Facades\Route;

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/contacts', [ContactSettingsController::class, 'edit'])
        ->name('admin.settings.contacts');

    Route::put('/admin/settings/contacts', [ContactSettingsController::class, 'update'])
        ->name('admin.settings.contacts.update');
});

Route::get('/contacts', [ContactController::class, 'index'])
    ->name('contacts.index');

Route::post('/contacts/context/clear', [ContactController::class, 'clearContext'])
    ->name('contacts.context.clear');

Route::get('/contacts/create', [ContactController::class, 'create'])
    ->name('contacts.create');

Route::post('/contacts', [ContactController::class, 'store'])
    ->name('contacts.store');

Route::get('/contacts/{contact}/edit', [ContactController::class, 'edit'])
    ->name('contacts.edit');

Route::get('/contacts/{contact}', [ContactController::class, 'show'])
    ->name('contacts.show');
