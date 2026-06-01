<?php

use App\Modules\Contact\Controllers\Api\V1\ContactController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('contacts', [ContactController::class, 'index'])
    ->name('contacts.index')
    ->middleware(CheckAbilities::class.':contacts.read');

Route::get('contacts/{contact}', [ContactController::class, 'show'])
    ->name('contacts.show')
    ->middleware(CheckAbilities::class.':contacts.read');

Route::post('contacts', [ContactController::class, 'store'])
    ->name('contacts.store')
    ->middleware(CheckAbilities::class.':contacts.create,contacts.update');

Route::match(['put', 'patch'], 'contacts/{contact}', [ContactController::class, 'update'])
    ->name('contacts.update')
    ->middleware(CheckAbilities::class.':contacts.update');
