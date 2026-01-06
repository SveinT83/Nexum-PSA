<?php

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Tech routes
//
// Routes fore tech url's - Middleware: auth, tech
// ---------------------------------------------------------------------------------------------------------------------------------------------------

use App\Http\Controllers\Tech\CS\Package\PackageController;
use App\Http\Controllers\Tech\CS\Services\ServiceController;
use App\Http\Controllers\Tech\CS\Contracts\ContractController;
use App\Http\Controllers\Tech\CS\Costs\CostController;
use App\Http\Controllers\Tech\CS\Sla\SlaController;
use App\Http\Controllers\Tech\CS\Legal\LegalController;

// ------------------------------------------------------------------------------------------
// Authenticated Tech/Superuser routes
// ------------------------------------------------------------------------------------------
Route::middleware(['auth','tech'])->group(function () {

    Route::get('/dashboard', function () {
        return view('tech.dashboard');
    })->name('dashboard');

    // ------------------------------------------------------------------------------------------
    // Admin Settings routes
    // ------------------------------------------------------------------------------------------

    // -----------------------------------------
    // Contracts & Services Settings
    // -----------------------------------------
    Route::get('/admin/settings/cs/contacts', function () {
        return view('tech.admin.settings.cs.contracts');
    })->name('admin.settings.cs.contracts');

    Route::get('/admin/settings/cs/services', function () {
        return view('tech.admin.settings.cs.services');
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
        return view('tech.admin.settings.sales.rules.index');
    })->name('admin.settings.sales.rules');

    Route::get('/admin/settings/sales/workflows', function () {
        return view('tech.admin.settings.sales.workflows.index');
    })->name('admin.settings.sales.workflows');

    // -----------------------------------------
    // Ticket Settings
    // -----------------------------------------
    Route::get('/admin/settings/tickets', function () {
        return view('tech.admin.settings.tickets.index');
    })->name('admin.settings.tickets');

    Route::get('/admin/settings/tickets/rules', function () {
        return view('tech.admin.settings.tickets.rules.index');
    })->name('admin.settings.tickets.rules');

    Route::get('/admin/settings/tickets/workflows', function () {
        return view('tech.admin.settings.tickets.workflows.index');
    })->name('admin.settings.tickets.workflows');

    // ------------------------------------------------------------------------------------------
    // Other Admin routes
    // ------------------------------------------------------------------------------------------

    // -----------------------------------------
    // Templates
    // -----------------------------------------
    Route::get('/admin/templates', function () {
        return view('tech.admin.templates.index');
    })->name('admin.templates.index');

    // -----------------------------------------
    // Users
    // -----------------------------------------
    Route::get('/admin/users', function () {
        return view('tech.admin.users.index');
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

    //Index: A list over all Contracts
    Route::get('/contracts', [ContractController::class, 'index'])
        ->name('contracts.index');

    //New Contract
    Route::get('/contracts/create', [ContractController::class, 'create'])
        ->name('contracts.create');

    // -----------------------------------------
    // PACKAGES
    // -----------------------------------------

    //Index: A list over all Packages
    Route::get('/packages', [PackageController::class, 'index'])
        ->name('packages.index');

    //Create a new Package
    Route::get('/package/create', [PackageController::class, 'create'])
        ->name('packages.create');

    //Store a new Package
    Route::post('/package/store', [PackageController::class, 'store'])
        ->name('packages.store');

    //Show a Package
    Route::get('/package/show/{package}', [PackageController::class, 'show'])
        ->name('packages.show');

    //Edit a Package
    Route::get('/package/edit/{package}', [PackageController::class, 'edit'])
        ->name('packages.edit');

    //Update a Package
    Route::put('/package/update/{package}', [PackageController::class, 'update'])
        ->name('packages.update');

    //Delete a Package
    Route::delete('/package/delete/{package}', [PackageController::class, 'destroy'])
        ->name('packages.delete');

    // -----------------------------------------
    // Services
    // -----------------------------------------

    //List all services
    Route::get('/services', [ServiceController::class, 'index'])->name('services.index');

    //Save a new service
    Route::post('/services', [ServiceController::class, 'store'])->name('services.store');

    //Update a service
    Route::post('/services/update/{service}', [ServiceController::class, 'update'])->name('services.update');

    //Create a new service form
    Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');

    //Show a service
    Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show');

    //Edit a service
    Route::get('/services/edit/{service}', [ServiceController::class, 'edit'])->name('services.edit');

    // -----------------------------------------
    // Costs
    // -----------------------------------------

    //List all costs
    Route::get('/costs', [CostController::class, 'index'])->name('costs.index');

    //Delete a cost
    Route::get('/costs/delete/{cost}', [CostController::class, 'delete'])->name('costs.delete');

    //Update a cost
    Route::post('/costs/update/{cost}', [CostController::class, 'update'])->name('costs.update');

    //New Cost Form
    Route::get('/costs/create', [CostController::class, 'create'])->name('costs.create');

    //Save a new cost
    Route::post('/costs/store', [CostController::class, 'store'])->name('costs.store');

    //Show a cost
    Route::get('/costs/{cost}', [CostController::class, 'show'])->name('costs.show');

    //Edit a cost
    Route::get('/costs/edit/{cost}', [CostController::class, 'edit'])->name('costs.edit');

    // -----------------------------------------
    // Legal - Legal & Terms
    // -----------------------------------------

    //List all costs
    Route::get('/legal', [LegalController::class, 'index'])
        ->name('legal.index');

    //Show a single legal or term
    Route::get('/legal/show/{term}', [LegalController::class, 'show'])
        ->name('legal.show');

    //Create a new legal or term
    Route::get('/legal/create', [LegalController::class, 'create'])
        ->name('legal.create');

    //Store the new legal or term
    Route::post('/legal/store', [LegalController::class, 'store'])
        ->name('legal.store');

    //Edit a legal or term
    Route::get('/legal/edit/{term}', [LegalController::class, 'edit'])
        ->name('legal.edit');

    //Update a legal or term
    Route::put('/legal/update/{term}', [LegalController::class, 'update'])
        ->name('legal.update');

    //Delete a legal or term
    Route::delete('/legal/delete/{term}', [LegalController::class, 'delete'])
        ->name('legal.delete');

    // -----------------------------------------
    // SLA
    // -----------------------------------------

    //Index: List of all SLA's
    Route::get('/sla', [SlaController::class, 'index'])
        ->name('sla.index');

    //Show: Show a single sla profile
    Route::get('/sla/show/{sla}', [SlaController::class, 'show'])
        ->name('sla.show');

    //EDIT: Edits a single sla profile
    Route::get('/sla/edit/{sla}', [SlaController::class, 'show'])
        ->name('sla.edit');

    //Create: List of all SLA's
    Route::get('/sla/create', [SlaController::class, 'create'])
        ->name('sla.create');

    //STORE: Save a new SLA Policy
    Route::post('/sla/store', [SlaController::class, 'store'])
        ->name('sla.store');

    //UPDATE: Updates an existing SLA Policy
    Route::put('/sla/update/{sla}', [SlaController::class, 'update'])
        ->name('sla.update');

    //DELETE: Deletes an existing SLA Policy
    Route::delete('/sla/delete/{sla}', [SlaController::class, 'destroy'])
        ->name('sla.delete');

    // -----------------------------------------
    // Documentations
    // -----------------------------------------
    Route::get('/documentations', function () {
        return view('tech.documentations.index');
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
        return view('tech.knowledge.index');
    })->name('knowledge.index');

    // -----------------------------------------
    // Reports
    // -----------------------------------------
    Route::get('/reports', function () {
        return view('tech.reports.index');
    })->name('reports.index');

    // -----------------------------------------
    // Sales
    // -----------------------------------------
    Route::get('/sales', function () {
        return view('tech.sales.index');
    })->name('sales.index');

    // -----------------------------------------
    // Storage
    // -----------------------------------------
    Route::get('/storage', function () {
        return view('tech.storage.index');
    })->name('storage.index');

    // -----------------------------------------
    // Tasks
    // -----------------------------------------
    Route::get('/tasks', function () {
        return view('tech.tasks.index');
    })->name('tasks.index');

    // -----------------------------------------
    // Tickets
    // -----------------------------------------
    Route::get('/tickets', function () {
        return view('tech.tickets.index');
    })->name('tickets.index');

});
