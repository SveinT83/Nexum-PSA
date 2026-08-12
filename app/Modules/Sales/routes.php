<?php

use App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess;
use App\Modules\Sales\Controllers\Admin\SalesSettingsController;
use App\Modules\Sales\Controllers\Portal\PortalSalesQuoteController;
use App\Modules\Sales\Controllers\PublicQuoteController;
use App\Modules\Sales\Controllers\Tech\LeadsController;
use App\Modules\Sales\Controllers\Tech\SalesController;
use Illuminate\Support\Facades\Route;

if (($salesPortalRoutes ?? false) === true) {
    Route::middleware(['auth', EnsureCustomerPortalAccess::class])
        ->prefix('portal/quotes')
        ->name('customer-portal.quotes.')
        ->group(function (): void {
            Route::get('/', [PortalSalesQuoteController::class, 'index'])->name('index');
            Route::get('/{quote}', [PortalSalesQuoteController::class, 'show'])->name('show');
            Route::post('/{quote}/accept', [PortalSalesQuoteController::class, 'accept'])->name('accept');
            Route::post('/{quote}/decline', [PortalSalesQuoteController::class, 'decline'])->name('decline');
            Route::post('/{quote}/question', [PortalSalesQuoteController::class, 'question'])->name('question');
        });

    return;
}

if (($salesPublicRoutes ?? false) === true) {
    Route::get('/quote/view/{token}', [PublicQuoteController::class, 'view'])
        ->name('sales.quotes.public.view');
    Route::get('/quote/pdf/{token}', [PublicQuoteController::class, 'pdf'])
        ->name('sales.quotes.public.pdf');
    Route::post('/quote/accept/{token}', [PublicQuoteController::class, 'accept'])
        ->name('sales.quotes.public.accept');
    Route::post('/quote/decline/{token}', [PublicQuoteController::class, 'decline'])
        ->name('sales.quotes.public.decline');
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

Route::post('/sales/{sale}/lost', [SalesController::class, 'markLost'])
    ->name('sales.lost');

Route::post('/sales/{sale}/reopen', [SalesController::class, 'reopen'])
    ->name('sales.reopen');

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

Route::patch('/sales/{sale}/quote/details', [SalesController::class, 'updateQuoteDetails'])
    ->name('sales.quote.details.update');

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

Route::post('/sales/{sale}/quote/approval/request', [SalesController::class, 'requestQuoteApproval'])
    ->name('sales.quote.approval.request');

Route::post('/sales/{sale}/quote/approval/approve', [SalesController::class, 'approveQuote'])
    ->name('sales.quote.approval.approve');

Route::post('/sales/{sale}/quote/approval/reject', [SalesController::class, 'rejectQuote'])
    ->name('sales.quote.approval.reject');

Route::post('/sales/{sale}/quote/approval/changes', [SalesController::class, 'requestQuoteChanges'])
    ->name('sales.quote.approval.changes');

Route::post('/sales/{sale}/quote/template', [SalesController::class, 'applyQuoteTemplate'])
    ->name('sales.quote.templates.apply');

Route::post('/sales/{sale}/quote/conversion-plans/{plan}', [SalesController::class, 'updateConversionPlan'])
    ->name('sales.quote.conversion-plans.update');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/sales/rules', [SalesSettingsController::class, 'rules'])
        ->name('admin.settings.sales.rules');

    Route::post('/admin/settings/sales/rules', [SalesSettingsController::class, 'updateRules'])
        ->name('admin.settings.sales.rules.update');

    Route::get('/admin/settings/sales/quote-templates', [SalesSettingsController::class, 'workflows'])
        ->name('admin.settings.sales.quote-templates.index');

    Route::get('/admin/settings/sales/workflows', fn () => redirect()->route('tech.admin.settings.sales.quote-templates.index'))
        ->name('admin.settings.sales.workflows');

    Route::get('/admin/settings/sales/quote-templates/create', [SalesSettingsController::class, 'createTemplate'])
        ->name('admin.settings.sales.quote-templates.create');

    Route::post('/admin/settings/sales/quote-templates', [SalesSettingsController::class, 'storeTemplate'])
        ->name('admin.settings.sales.quote-templates.store');

    Route::get('/admin/settings/sales/quote-templates/{template}/edit', [SalesSettingsController::class, 'editTemplate'])
        ->name('admin.settings.sales.quote-templates.edit');

    Route::put('/admin/settings/sales/quote-templates/{template}', [SalesSettingsController::class, 'updateTemplate'])
        ->name('admin.settings.sales.quote-templates.update');

    Route::delete('/admin/settings/sales/quote-templates/{template}', [SalesSettingsController::class, 'destroyTemplate'])
        ->name('admin.settings.sales.quote-templates.destroy');

    Route::post('/admin/settings/sales/quote-templates/{template}/lines', [SalesSettingsController::class, 'storeTemplateLine'])
        ->name('admin.settings.sales.quote-templates.lines.store');

    Route::delete('/admin/settings/sales/quote-templates/{template}/lines/{line}', [SalesSettingsController::class, 'destroyTemplateLine'])
        ->name('admin.settings.sales.quote-templates.lines.destroy');

    Route::post('/admin/settings/sales/quote-templates/{template}/acknowledgements', [SalesSettingsController::class, 'storeTemplateAcknowledgement'])
        ->name('admin.settings.sales.quote-templates.acknowledgements.store');

    Route::delete('/admin/settings/sales/quote-templates/{template}/acknowledgements/{acknowledgement}', [SalesSettingsController::class, 'destroyTemplateAcknowledgement'])
        ->name('admin.settings.sales.quote-templates.acknowledgements.destroy');

});
