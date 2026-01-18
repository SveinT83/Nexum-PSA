<?php
use Illuminate\Support\Facades\Route;

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
use App\Http\Controllers\Tech\Clients\ClientSiteController;
use App\Http\Controllers\Tech\Clients\ClientUsersController;
use App\Http\Controllers\Tech\Clients\ClientController;

// ------------------------------------------------------------------------------------------
// Authenticated Tech/Superuser routes
// ------------------------------------------------------------------------------------------
Route::middleware(['auth','tech'])->group(function () {

    Route::get('/dashboard', function () {
        return view('tech.dashboard');
    })->name('dashboard');

    // -----------------------------------------
    // Clients
    // -----------------------------------------

    // -----------------------------------------
    // Clients (basic MVP: index, create, store, show)
    // -----------------------------------------
    Route::get('/clients', [ClientController::class, 'index'])
        ->name('clients.index');

    Route::get('/clients/create', [ClientController::class, 'create'])
        ->name('clients.create');

    Route::post('/clients/store', [ClientController::class, 'store'])
        ->name('clients.store');

    Route::get('/clients/show/{client}', [ClientController::class, 'show'])
        ->name('clients.show');


    // -----------------------------------------
    // Users
    // -----------------------------------------

    //Index
    Route::get('/clients/users/', [ClientUsersController::class, 'index'])
        ->name('clients.users.index');

    //Show
    Route::get('/clients/users/show/{userId}', [ClientUsersController::class, 'show'])
        ->name('clients.users.show');

    //Create
    Route::get('/clients/users.create/{Sites}', [ClientUsersController::class, 'create'])
        ->name('clients.users.create');

    //Store
    Route::post('/clients/users.store/{Sites}', [ClientUsersController::class, 'store'])
        ->name('clients.users.store');

    //Edit
    Route::get('/clients/users.edit/{ClientUsers}', [ClientUsersController::class, 'edit'])
        ->name('clients.users.edit');

    //Update
    Route::put('/clients/users.update/{ClientUsers}', [ClientUsersController::class, 'update'])
        ->name('clients.users.update');

    //Destroy
    Route::delete('/clients/users.delete/{ClientUsers}', [ClientUsersController::class, 'delete'])
        ->name('clients.users.delete');


    // -----------------------------------------
    // Sites
    // -----------------------------------------

    //Index
    Route::get('/clients/sites/{client?}', [ClientSiteController::class, 'index'])
        ->name('clients.sites.index');

    //Show
    Route::get('/clients/sites/show/{site}', [ClientSiteController::class, 'show'])
        ->name('clients.sites.show');

    //Create
    Route::get('/clients/sites/create/{client?}', [ClientSiteController::class, 'create'])
        ->name('clients.sites.create');

    //Store
    Route::post('/clients/sites/store/{client?}', [ClientSiteController::class, 'store'])
        ->name('clients.sites.store');

    //Edit
    Route::get('/clients/sites.edit/{site}', [ClientSiteController::class, 'edit'])
        ->name('clients.sites.edit');

    //Update
    Route::put('/clients/sites.update/{site}', [ClientSiteController::class, 'update'])
        ->name('clients.sites.update');

    //Destroy
    Route::delete('/clients/sites/destroy/{site}', [ClientSiteController::class, 'destroy'])
        ->name('clients.sites.destroy');

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

    //Delete a service
    Route::delete('/services/destroy/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

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
