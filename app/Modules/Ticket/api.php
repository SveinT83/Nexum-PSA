<?php

use App\Modules\Ticket\Controllers\Api\V1\TicketController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('tickets', [TicketController::class, 'index'])
    ->name('tickets.index')
    ->middleware(CheckAbilities::class.':tickets.read');

Route::post('tickets', [TicketController::class, 'store'])
    ->name('tickets.store')
    ->middleware(CheckAbilities::class.':tickets.create');

Route::get('tickets/{ticket}', [TicketController::class, 'show'])
    ->name('tickets.show')
    ->middleware(CheckAbilities::class.':tickets.read');

Route::match(['put', 'patch'], 'tickets/{ticket}', [TicketController::class, 'update'])
    ->name('tickets.update')
    ->middleware(CheckAbilities::class.':tickets.update');

Route::post('tickets/{ticket}/external-messages', [TicketController::class, 'storeExternalMessage'])
    ->name('tickets.external-messages.store')
    ->middleware(CheckAbilities::class.':tickets.update');
