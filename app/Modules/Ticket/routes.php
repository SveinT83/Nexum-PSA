<?php

use App\Modules\Ticket\Controllers\Admin\TicketSettingsController;
use App\Modules\Ticket\Controllers\Tech\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/tickets', [TicketController::class, 'index'])
    ->name('tickets.index');

Route::get('/tickets/create', [TicketController::class, 'create'])
    ->name('tickets.create');

Route::post('/tickets', [TicketController::class, 'store'])
    ->name('tickets.store');

Route::get('/tickets/{ticket}/edit', [TicketController::class, 'edit'])
    ->name('tickets.edit');

Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
    ->name('tickets.show');

Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])
    ->name('tickets.update');

Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])
    ->name('tickets.close');

Route::post('/tickets/{ticket}/messages', [TicketController::class, 'addMessage'])
    ->name('tickets.messages.store');

Route::post('/tickets/{ticket}/read', [TicketController::class, 'markRead'])
    ->name('tickets.read');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/tickets', [TicketSettingsController::class, 'index'])
        ->name('admin.settings.tickets');
    Route::post('/admin/settings/tickets/default-email-account', [TicketSettingsController::class, 'updateDefaultEmailAccount'])
        ->name('admin.settings.tickets.default-email-account.update');

    Route::get('/admin/settings/tickets/rules', [TicketSettingsController::class, 'rules'])
        ->name('admin.settings.tickets.rules');

    Route::get('/admin/settings/tickets/workflows', [TicketSettingsController::class, 'workflows'])
        ->name('admin.settings.tickets.workflows');
});
