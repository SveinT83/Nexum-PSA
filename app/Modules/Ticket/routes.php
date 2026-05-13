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
    Route::post('/admin/settings/tickets/queues', [TicketSettingsController::class, 'storeQueue'])
        ->name('admin.settings.tickets.queues.store');
    Route::put('/admin/settings/tickets/queues/{queue}', [TicketSettingsController::class, 'updateQueue'])
        ->name('admin.settings.tickets.queues.update');
    Route::delete('/admin/settings/tickets/queues/{queue}', [TicketSettingsController::class, 'destroyQueue'])
        ->name('admin.settings.tickets.queues.destroy');
    Route::post('/admin/settings/tickets/types', [TicketSettingsController::class, 'storeType'])
        ->name('admin.settings.tickets.types.store');
    Route::put('/admin/settings/tickets/types/{type}', [TicketSettingsController::class, 'updateType'])
        ->name('admin.settings.tickets.types.update');
    Route::delete('/admin/settings/tickets/types/{type}', [TicketSettingsController::class, 'destroyType'])
        ->name('admin.settings.tickets.types.destroy');

    // Ticket lifecycle and urgency records are managed inside the Ticket module settings surface.
    Route::post('/admin/settings/tickets/statuses', [TicketSettingsController::class, 'storeStatus'])
        ->name('admin.settings.tickets.statuses.store');
    Route::put('/admin/settings/tickets/statuses/{status}', [TicketSettingsController::class, 'updateStatus'])
        ->name('admin.settings.tickets.statuses.update');
    Route::delete('/admin/settings/tickets/statuses/{status}', [TicketSettingsController::class, 'destroyStatus'])
        ->name('admin.settings.tickets.statuses.destroy');
    Route::post('/admin/settings/tickets/priorities', [TicketSettingsController::class, 'storePriority'])
        ->name('admin.settings.tickets.priorities.store');
    Route::put('/admin/settings/tickets/priorities/{priority}', [TicketSettingsController::class, 'updatePriority'])
        ->name('admin.settings.tickets.priorities.update');
    Route::delete('/admin/settings/tickets/priorities/{priority}', [TicketSettingsController::class, 'destroyPriority'])
        ->name('admin.settings.tickets.priorities.destroy');

    Route::get('/admin/settings/tickets/rules', [TicketSettingsController::class, 'rules'])
        ->name('admin.settings.tickets.rules');
    Route::get('/admin/settings/tickets/rules/create', [TicketSettingsController::class, 'createRule'])
        ->name('admin.settings.tickets.rules.create');
    Route::post('/admin/settings/tickets/rules', [TicketSettingsController::class, 'storeRule'])
        ->name('admin.settings.tickets.rules.store');
    Route::get('/admin/settings/tickets/rules/{rule}/edit', [TicketSettingsController::class, 'editRule'])
        ->name('admin.settings.tickets.rules.edit');
    Route::put('/admin/settings/tickets/rules/{rule}', [TicketSettingsController::class, 'updateRule'])
        ->name('admin.settings.tickets.rules.update');
    Route::post('/admin/settings/tickets/rules/{rule}/toggle', [TicketSettingsController::class, 'toggleRule'])
        ->name('admin.settings.tickets.rules.toggle');
    Route::delete('/admin/settings/tickets/rules/{rule}', [TicketSettingsController::class, 'destroyRule'])
        ->name('admin.settings.tickets.rules.destroy');

    Route::get('/admin/settings/tickets/workflows', [TicketSettingsController::class, 'workflows'])
        ->name('admin.settings.tickets.workflows');
});
