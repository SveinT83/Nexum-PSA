<?php

use App\Modules\Contact\Controllers\Tech\ContactController;
use Illuminate\Support\Facades\Route;

Route::get('/contacts', [ContactController::class, 'index'])
    ->name('contacts.index');

Route::get('/contacts/create', [ContactController::class, 'create'])
    ->name('contacts.create');

Route::post('/contacts', [ContactController::class, 'store'])
    ->name('contacts.store');

Route::get('/contacts/{contact}', [ContactController::class, 'show'])
    ->name('contacts.show');
