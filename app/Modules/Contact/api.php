<?php

use App\Modules\Contact\Controllers\Api\V1\ContactController;
use App\Modules\Contact\Controllers\Api\V1\ContactOwnershipController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('clients/{client}/contacts', [ContactOwnershipController::class, 'index'])
    ->name('clients.contacts.index')
    ->middleware(CheckAbilities::class.':contacts.read');

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

Route::post('contacts/{contact}/move', [ContactOwnershipController::class, 'move'])
    ->name('contacts.move')
    ->middleware(CheckAbilities::class.':contacts.ownership_manage');

Route::post('clients/{client}/contacts/bulk-fix', [ContactOwnershipController::class, 'bulkFix'])
    ->name('clients.contacts.bulk-fix')
    ->middleware(CheckAbilities::class.':contacts.ownership_manage');

Route::delete('clients/{client}/contacts/{contact}', [ContactOwnershipController::class, 'detach'])
    ->name('clients.contacts.detach')
    ->middleware(CheckAbilities::class.':contacts.ownership_manage');
