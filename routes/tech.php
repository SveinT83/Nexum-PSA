<?php

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Tech routes
//
// Routes fore tech url's - Middleware: auth, tech
// ---------------------------------------------------------------------------------------------------------------------------------------------------

use Illuminate\Support\Facades\Route;

// ------------------------------------------------------------------------------------------
// Authenticated Tech/Superuser routes
// ------------------------------------------------------------------------------------------
Route::middleware(['auth','tech'])->group(function () {

    Route::get('/dashboard', function () {
        return view('Tech.dashboard');
    })->name('dashboard');

    // ------------------------------------------------------------------------------------------
    // Admin Settings routes
    // ------------------------------------------------------------------------------------------

    // -----------------------------------------
    // Contracts & Services Settings
    // -----------------------------------------
    Route::get('/admin/settings/cs/contacts', function () {
        return view('Tech.admin.settings.cs.contracts');
    })->name('admin.settings.cs.contracts');

    Route::get('/admin/settings/cs/services', function () {
        return view('Tech.admin.settings.cs.services');
    })->name('admin.settings.cs.services');

    // -----------------------------------------
    // Email Settings
    // -----------------------------------------
    // Email Settings (controllers)
    Route::get('/admin/settings/email/accounts', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'index'])
        ->name('admin.settings.email.accounts');
    Route::get('/admin/settings/email/accounts/create', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'create'])
        ->name('admin.settings.email.accounts.create');
    Route::post('/admin/settings/email/accounts', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'store'])
        ->name('admin.settings.email.accounts.store');
    Route::get('/admin/settings/email/accounts/{account}/edit', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'edit'])
        ->name('admin.settings.email.accounts.edit');
    Route::put('/admin/settings/email/accounts/{account}', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'update'])
        ->name('admin.settings.email.accounts.update');
    Route::post('/admin/settings/email/accounts/{account}/toggle', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'toggleActive'])
        ->name('admin.settings.email.accounts.toggle');

    // Live connection test (IMAP/SMTP)
    Route::post('/admin/settings/email/accounts/{account}/test', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'test'])
        ->name('admin.settings.email.accounts.test');

    Route::get('/admin/settings/email/config', [\App\Http\Controllers\Tech\Admin\Settings\Email\ConfigController::class, 'index'])
        ->name('admin.settings.email.config');
    Route::post('/admin/settings/email/config', [\App\Http\Controllers\Tech\Admin\Settings\Email\ConfigController::class, 'update'])
        ->name('admin.settings.email.config.update');

    Route::get('/admin/settings/email/rules', [\App\Http\Controllers\Tech\Admin\Settings\Email\RulesController::class, 'index'])
        ->name('admin.settings.email.rules');

    // -----------------------------------------
    // Sales Settings
    // -----------------------------------------
    Route::get('/admin/settings/sales/rules', function () {
        return view('Tech.admin.settings.sales.rules.index');
    })->name('admin.settings.sales.rules');

    Route::get('/admin/settings/sales/workflows', function () {
        return view('Tech.admin.settings.sales.workflows.index');
    })->name('admin.settings.sales.workflows');

    // -----------------------------------------
    // Ticket Settings
    // -----------------------------------------
    Route::get('/admin/settings/tickets', function () {
        return view('Tech.admin.settings.tickets.index');
    })->name('admin.settings.tickets');

    Route::get('/admin/settings/tickets/rules', function () {
        return view('Tech.admin.settings.tickets.rules.index');
    })->name('admin.settings.tickets.rules');

    Route::get('/admin/settings/tickets/workflows', function () {
        return view('Tech.admin.settings.tickets.workflows.index');
    })->name('admin.settings.tickets.workflows');

    // ------------------------------------------------------------------------------------------
    // Other Admin routes
    // ------------------------------------------------------------------------------------------

    // -----------------------------------------
    // Templates
    // -----------------------------------------
    Route::get('/admin/templates', function () {
        return view('Tech.admin.templates.index');
    })->name('admin.templates.index');

    // -----------------------------------------
    // Users
    // -----------------------------------------
    Route::get('/admin/users', function () {
        return view('Tech.admin.users.index');
    })->name('admin.users.index');

    // -----------------------------------------
    // Clients
    // -----------------------------------------

    // -----------------------------------------
    // Clients (basic MVP: index, create, store, show)
    // -----------------------------------------
    Route::get('/clients', [\App\Http\Controllers\Tech\Clients\IndexController::class, 'index'])
        ->name('clients.index');

    Route::get('/clients/create', [\App\Http\Controllers\Tech\Clients\CreateController::class, 'create'])
        ->name('clients.create');

    Route::post('/clients', [\App\Http\Controllers\Tech\Clients\CreateController::class, 'store'])
        ->name('clients.store');
        
    Route::get('/clients/{client}', [\App\Http\Controllers\Tech\Clients\ShowController::class, 'show'])
        ->name('clients.show');

    // -----------------------------------------
    // Contracts
    // -----------------------------------------
    Route::get('/contracts', function () {
        return view('Tech.cs.contracts.index');
    })->name('contracts.index');

    // -----------------------------------------
    // Services
    // -----------------------------------------
    Route::get('/services', function () {
        return view('Tech.cs.services.index');
    })->name('services.index');

    // -----------------------------------------
    // Documentations
    // -----------------------------------------
    Route::get('/documentations', function () {
        return view('Tech.Documentations.index');
    })->name('documentations.index');

    // -----------------------------------------
    // Inbox
    // -----------------------------------------
    Route::get('/inbox', [\App\Http\Controllers\Tech\Inbox\IndexController::class, 'index'])
        ->name('inbox.index');
    Route::post('/inbox/poll', [\App\Http\Controllers\Tech\Inbox\IndexController::class, 'poll'])
        ->name('inbox.poll');
    Route::get('/inbox/show/{message}', [\App\Http\Controllers\Tech\Inbox\ShowController::class, 'show'])
        ->name('inbox.show');
    Route::delete('/inbox/{message}', [\App\Http\Controllers\Tech\Inbox\ShowController::class, 'destroy'])
        ->name('inbox.delete');
    Route::get('/inbox/attachments/{attachment}/download', [\App\Http\Controllers\Tech\Inbox\ShowController::class, 'download'])
        ->name('inbox.download');

    // -----------------------------------------
    // Knowledge
    // -----------------------------------------
    Route::get('/knowledge', function () {
        return view('Tech.Knowledge.index');
    })->name('knowledge.index');

    // -----------------------------------------
    // Reports
    // -----------------------------------------
    Route::get('/reports', function () {
        return view('Tech.Reports.index');
    })->name('reports.index');

    // -----------------------------------------
    // Sales
    // -----------------------------------------
    Route::get('/sales', function () {
        return view('Tech.Sales.index');
    })->name('sales.index');

    // -----------------------------------------
    // Storage
    // -----------------------------------------
    Route::get('/storage', function () {
        return view('Tech.Storage.index');
    })->name('storage.index');

    // -----------------------------------------
    // Tasks
    // -----------------------------------------
    Route::get('/tasks', function () {
        return view('Tech.Tasks.index');
    })->name('tasks.index');

    // -----------------------------------------
    // Tickets
    // -----------------------------------------
    Route::get('/tickets', function () {
        return view('Tech.Tickets.index');
    })->name('tickets.index');

});