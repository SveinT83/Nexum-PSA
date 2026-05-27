<?php

use App\Modules\Sales\Controllers\Admin\SalesSettingsController;
use App\Modules\Sales\Controllers\PublicQuoteController;
use App\Modules\Sales\Controllers\Tech\LeadsController;
use App\Modules\Sales\Controllers\Tech\SalesController;
use Illuminate\Support\Facades\Route;

if (($salesPublicRoutes ?? false) === true) {
    Route::get('/quote/view/{token}', [PublicQuoteController::class, 'view'])
        ->name('sales.quotes.public.view');
    Route::get('/quote/pdf/{token}', [PublicQuoteController::class, 'pdf'])
        ->name('sales.quotes.public.pdf');
    Route::post('/quote/accept/{token}', [PublicQuoteController::class, 'accept'])
        ->name('sales.quotes.public.accept');
    Route::post('/quote/question/{token}', [PublicQuoteController::class, 'question'])
        ->name('sales.quotes.public.question');

    return;
}

Route::get('/sales', [SalesController::class, 'index'])
    ->name('sales.index');

Route::get('/sales/create', [SalesController::class, 'create'])
    ->name('sales.create');

Route::post('/sales', [SalesController::class, 'store'])
    ->name('sales.store');

Route::post('/sales/clients/quick-store', [SalesController::class, 'quickStoreClient'])
    ->name('sales.clients.quick-store');

Route::post('/sales/clients/{client}/contacts/quick-store', [SalesController::class, 'quickStoreContact'])
    ->name('sales.clients.contacts.quick-store');

Route::get('/sales/leads', [LeadsController::class, 'index'])
    ->name('sales.leads.index');

Route::post('/sales/leads/{lead}/start', [LeadsController::class, 'start'])
    ->name('sales.leads.start');

Route::patch('/sales/leads/{lead}/classification', [LeadsController::class, 'updateClassification'])
    ->name('sales.leads.classification.update');

Route::get('/sales/leads/{lead}', [LeadsController::class, 'show'])
    ->name('sales.leads.show');

Route::get('/sales/{sale}', [SalesController::class, 'show'])
    ->name('sales.show');

Route::patch('/sales/{sale}', [SalesController::class, 'update'])
    ->name('sales.update');

Route::post('/sales/{sale}/activities', [SalesController::class, 'storeActivity'])
    ->name('sales.activities.store');

Route::post('/sales/{sale}/read', [SalesController::class, 'markRead'])
    ->name('sales.read');

Route::post('/sales/{sale}/activities/{activity}/read', [SalesController::class, 'markActivityRead'])
    ->name('sales.activities.read');

Route::post('/sales/{sale}/stakeholders', [SalesController::class, 'storeStakeholder'])
    ->name('sales.stakeholders.store');

Route::post('/sales/{sale}/quote', [SalesController::class, 'ensureQuote'])
    ->name('sales.quote.ensure');

Route::post('/sales/{sale}/quote/lines', [SalesController::class, 'addQuoteLine'])
    ->name('sales.quote.lines.store');

Route::patch('/sales/{sale}/quote/lines/{line}', [SalesController::class, 'updateQuoteLine'])
    ->name('sales.quote.lines.update');

Route::delete('/sales/{sale}/quote/lines/{line}', [SalesController::class, 'deleteQuoteLine'])
    ->name('sales.quote.lines.destroy');

Route::post('/sales/{sale}/quote/send', [SalesController::class, 'sendQuote'])
    ->name('sales.quote.send');

Route::post('/sales/{sale}/quote/revise', [SalesController::class, 'reviseQuote'])
    ->name('sales.quote.revise');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/sales/rules', [SalesSettingsController::class, 'rules'])
        ->name('admin.settings.sales.rules');

    Route::get('/admin/settings/sales/workflows', [SalesSettingsController::class, 'workflows'])
        ->name('admin.settings.sales.workflows');

});
