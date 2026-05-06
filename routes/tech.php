<?php
// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Use Domain Architecture rout file in the module folder, Read module-architecture.md for more info.
// ---------------------------------------------------------------------------------------------------------------------------------------------------

use App\Http\Controllers\Tech\CS\Contracts\ContractController;
use App\Http\Controllers\Tech\CS\Costs\CostController;
use App\Http\Controllers\Tech\CS\Legal\LegalController;
use App\Http\Controllers\Tech\CS\Package\PackageController;
use App\Http\Controllers\Tech\CS\Services\ServiceController;
use App\Http\Controllers\Tech\CS\Sla\SlaController;
use App\Http\Controllers\Tech\Doc\DocController;
use App\Http\Controllers\Tech\Risk\RiskController;
use App\Http\Controllers\Tech\Work\Assets\AssetController;
use Illuminate\Support\Facades\Route;


// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Tech routes
//
// Routes fore tech url's - Middleware: auth, tech
// ---------------------------------------------------------------------------------------------------------------------------------------------------

// ------------------------------------------------------------------------------------------
// Authenticated Tech/Superuser routes
// ------------------------------------------------------------------------------------------
Route::middleware(['auth','tech'])->group(function () {

    foreach (glob(app_path('Modules/*/routes.php')) as $routeFile) {
        require $routeFile;
    }

    Route::get('/dashboard', function () {
        return view('tech.dashboard');
    })->name('dashboard');

    // -----------------------------------------
    // Contracts
    // -----------------------------------------

    //Index: A list over all Contracts
    Route::get('/contracts', [ContractController::class, 'index'])
        ->name('contracts.index');

    //Show: View details of a specific Contract
    Route::get('/contracts/show/{contract}', [ContractController::class, 'show'])
        ->name('contracts.show');

    //New Contract
    Route::get('/contracts/create', [ContractController::class, 'create'])
        ->name('contracts.create');

    //Store
    Route::post('/contracts/store', [ContractController::class, 'store'])
        ->name('contracts.store');

    //Edit: Edit an existing Contract
    Route::get('/contracts/edit/{contract}', [ContractController::class, 'edit'])
        ->name('contracts.edit');

    //Update: Save changes to an existing Contract
    Route::put('/contracts/update/{contract}', [ContractController::class, 'update'])
        ->name('contracts.update');

    Route::delete('/contracts/delete/{contract}', [ContractController::class, 'destroy'])
        ->name('contracts.destroy');

    //Actions: Send Quote, Send Contract, Manual Approval
    Route::post('/contracts/{contract}/send-quote', [ContractController::class, 'sendQuote'])
        ->name('contracts.send-quote');
    Route::post('/contracts/{contract}/send-contract', [ContractController::class, 'sendContract'])
        ->name('contracts.send-contract');
    Route::post('/contracts/{contract}/resend', [ContractController::class, 'resend'])
        ->name('contracts.resend');
    Route::post('/contracts/{contract}/approve-manual', [ContractController::class, 'approveManual'])
        ->name('contracts.approve-manual');



    // -----------------------------------------
    // Contracts
    // -----------------------------------------

    // -----------------------------------------
    // Contracts: Services
    // -----------------------------------------

    //Index: Add services to contract
    Route::get('/contracts/{contract}/services', [ContractController::class, 'servicesEdit'])
        ->name('contracts.services.edit');

    //Update: Save services to contract
    Route::post('/contracts/{contract}/services', [ContractController::class, 'servicesUpdate'])
        ->name('contracts.services.update');

    //Terms: Terms and Legal for contract
    Route::get('/contracts/{contract}/terms', [ContractController::class, 'terms'])
        ->name('contracts.terms');

    //Update: Save terms to contract
    Route::post('/contracts/{contract}/terms', [ContractController::class, 'termsUpdate'])
        ->name('contracts.terms.update');

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

    // Context setting
    Route::post('/context/set', [DocController::class, 'setContext'])
        ->name('context.set');

    //Index
    Route::get('/documentations', [DocController::class, 'index'])
        ->name('documentations.index');

    //Create
    Route::get('/documentations/create', [DocController::class, 'create'])
        ->name('documentations.create');

    //Store
    Route::post('/documentations/create', [DocController::class, 'store'])
        ->name('documentations.store');

    //Show
    Route::get('/documentations/show/{documentation}', [DocController::class, 'show'])
        ->name('documentations.show');

    //Edit
    Route::get('/documentations/edit/{documentation}', [DocController::class, 'edit'])
        ->name('documentations.edit');

    //Update
    Route::put('/documentations/update/{documentation}', [DocController::class, 'update'])
        ->name('documentations.update');

    //Destroy
    Route::delete('/documentations/destroy/{documentation}', [DocController::class, 'destroy'])
        ->name('documentations.destroy');

    // -----------------------------------------
    // Risk
    // -----------------------------------------

    //Index
    Route::get('/risk', [RiskController::class, 'index'])
        ->name('risk.index');

    //Create
    Route::get('/risk/create', [RiskController::class, 'create'])
        ->name('risk.create');

    //Store
    Route::post('/risk/store', [RiskController::class, 'store'])
        ->name('risk.store');

    //Show
    Route::get('/risk/show/{risk}', [RiskController::class, 'show'])
        ->name('risk.show');

    //Update
    Route::put('/risk/update/{risk}', [RiskController::class, 'update'])
        ->name('risk.update');

    //Destroy
    Route::delete('/risk/destroy/{risk}', [RiskController::class, 'destroy'])
        ->name('risk.destroy');

    //Risk Items
    Route::post('/risk/show/{risk}/items', [RiskController::class, 'storeItem'])
        ->name('risk.items.store');

    Route::post('/risk/show/{risk}/approve', [RiskController::class, 'approve'])
        ->name('risk.approve');

    Route::get('/risk/show/{risk}/pdf', [RiskController::class, 'exportPdf'])
        ->name('risk.pdf');

    Route::get('/risk/items/{item}', [RiskController::class, 'showItem'])
        ->name('risk.items.show');

    Route::post('/risk/items/{item}/updates', [RiskController::class, 'storeItemUpdate'])
        ->name('risk.items.updates.store');

    Route::delete('/risk/updates/{update}', [RiskController::class, 'destroyUpdate'])
        ->name('risk.updates.destroy');

    Route::delete('/risk/items/{item}', [RiskController::class, 'destroyItem'])
        ->name('risk.items.destroy');
    Route::put('/risk/items/{item}', [RiskController::class, 'updateItem'])
        ->name('risk.items.update');


    // -----------------------------------------
    // Inbox
    // -----------------------------------------
    Route::get('/inbox', [\App\Http\Controllers\Tech\Inbox\EmailController::class, 'index'])
        ->name('inbox.index');
    Route::post('/inbox/poll', [\App\Http\Controllers\Tech\Inbox\EmailController::class, 'poll'])
        ->name('inbox.poll');
    Route::get('/inbox/show/{message}', [\App\Http\Controllers\Tech\Inbox\EmailController::class, 'show'])
        ->name('inbox.show');
    Route::delete('/inbox/{message}', [\App\Http\Controllers\Tech\Inbox\EmailController::class, 'destroy'])
        ->name('inbox.delete');
    Route::get('/inbox/attachments/{attachment}/download', [\App\Http\Controllers\Tech\Inbox\EmailController::class, 'download'])
        ->name('inbox.download');

    // -----------------------------------------
    // Knowledge
    // -----------------------------------------
    Route::get('/knowledge', [\App\Http\Controllers\Tech\Work\Knowledge\KnowledgeController::class, 'index'])
        ->name('knowledge.index');
    Route::get('/knowledge/create', [\App\Http\Controllers\Tech\Work\Knowledge\KnowledgeController::class, 'create'])
        ->name('knowledge.create');
    Route::post('/knowledge/store', [\App\Http\Controllers\Tech\Work\Knowledge\KnowledgeController::class, 'store'])
        ->name('knowledge.store');
    Route::get('/knowledge/show/{article}', [\App\Http\Controllers\Tech\Work\Knowledge\KnowledgeController::class, 'show'])
        ->name('knowledge.show');
    Route::get('/knowledge/edit/{article}', [\App\Http\Controllers\Tech\Work\Knowledge\KnowledgeController::class, 'edit'])
        ->name('knowledge.edit');
    Route::put('/knowledge/update/{article}', [\App\Http\Controllers\Tech\Work\Knowledge\KnowledgeController::class, 'update'])
        ->name('knowledge.update');
    Route::delete('/knowledge/destroy/{article}', [\App\Http\Controllers\Tech\Work\Knowledge\KnowledgeController::class, 'destroy'])
        ->name('knowledge.destroy');

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
    // Assets
    // -----------------------------------------
    Route::get('/assets/docs', [AssetController::class, 'docs'])
        ->name('assets.docs');

    Route::get('/assets', [AssetController::class, 'index'])
        ->name('assets.index');

    Route::get('/assets/create', [AssetController::class, 'create'])
        ->name('assets.create');

    Route::post('/assets/store', [AssetController::class, 'store'])
        ->name('assets.store');

    Route::get('/assets/edit/{asset}', [AssetController::class, 'edit'])
        ->name('assets.edit');

    Route::get('/assets/{asset}/{tab?}', [AssetController::class, 'show'])
        ->name('assets.show');

    Route::put('/assets/update/{asset}', [AssetController::class, 'update'])
        ->name('assets.update');

    Route::get('/clients/{client}/assets', [AssetController::class, 'index'])
        ->name('clients.assets.index');

    // -----------------------------------------
    // Tickets
    // -----------------------------------------
    Route::get('/tickets', function () {
        return view('tech.tickets.index');
    })->name('tickets.index');

});
