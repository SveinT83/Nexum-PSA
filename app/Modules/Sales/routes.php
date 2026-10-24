<?php

use App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess;
use App\Modules\Sales\Controllers\Admin\SalesSettingsController;
use App\Modules\Sales\Controllers\Portal\PortalSalesQuoteController;
use App\Modules\Sales\Controllers\PublicQuoteController;
use App\Modules\Sales\Controllers\Tech\LeadsController;
use App\Modules\Sales\Controllers\Tech\SalesController;
use Illuminate\Support\Facades\Route;

// Typed Sales APIs. Browser/public/portal registrations below remain backwards compatible.
if (($salesPublicApiRoutes ?? false) === true) {
    Route::middleware([\App\Modules\Sales\Http\Middleware\SalesApiFields::class, 'throttle:60,1'])->group(function () {
        Route::get('sales/public/quotes/{token}', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'show'])->name('sales.public.quotes.show')
            ->defaults('sales_fields', []);
        Route::get('sales/public/quotes/{token}/pdf', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'pdf'])->name('sales.public.quotes.pdf')
            ->defaults('sales_fields', []);
        Route::post('sales/public/quotes/{token}/viewed', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'viewed'])->name('sales.public.quotes.viewed')
            ->defaults('sales_fields', []);
        Route::post('sales/public/quotes/{token}/accept', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'accept'])->name('sales.public.quotes.accept')
            ->defaults('sales_fields', ['name', 'confirm', 'selected_line_ids', 'quantities', 'acknowledgement_ids', 'email']);
        Route::post('sales/public/quotes/{token}/decline', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'decline'])->name('sales.public.quotes.decline')
            ->defaults('sales_fields', ['name', 'reason']);
        Route::post('sales/public/quotes/{token}/questions', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'question'])->name('sales.public.quotes.question')
            ->defaults('sales_fields', ['name', 'email', 'message']);
    });
    Route::middleware(['web', 'auth', \App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class, 'throttle:60,1'])->group(function () {
        Route::get('sales/portal/quotes', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'index'])->name('sales.portal.quotes.index')
            ->defaults('sales_fields', []);
        Route::get('sales/portal/quotes/{version}', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'show'])->name('sales.portal.quotes.show')
            ->defaults('sales_fields', []);
        Route::get('sales/portal/quotes/{version}/pdf', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'pdf'])->name('sales.portal.quotes.pdf')
            ->defaults('sales_fields', []);
        Route::post('sales/portal/quotes/{version}/viewed', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'viewed'])->name('sales.portal.quotes.viewed')
            ->defaults('sales_fields', []);
        Route::post('sales/portal/quotes/{version}/accept', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'accept'])->name('sales.portal.quotes.accept')
            ->defaults('sales_fields', ['name', 'confirm', 'selected_line_ids', 'quantities', 'acknowledgement_ids']);
        Route::post('sales/portal/quotes/{version}/decline', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'decline'])->name('sales.portal.quotes.decline')
            ->defaults('sales_fields', ['name', 'reason']);
        Route::post('sales/portal/quotes/{version}/questions', [\App\Modules\Sales\Controllers\Api\V1\SalesCustomerQuoteController::class, 'question'])->name('sales.portal.quotes.question')
            ->defaults('sales_fields', ['message']);
    });

    return;
}

if (($salesStaffApiRoutes ?? false) === true) {
    Route::get('sales/context', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'context'])->name('sales.context')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/dashboard', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'dashboard'])->name('sales.dashboard')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'index'])->name('sales.opportunities.index')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'store'])->name('sales.opportunities.store')
        ->defaults('sales_fields', ['client_id', 'primary_contact_id', 'owner_id', 'title', 'type', 'status', 'summary', 'needs', 'employee_count_estimate', 'user_count_estimate', 'workstation_count_estimate', 'server_count_estimate', 'site_count_estimate', 'estimated_value_ex_vat', 'probability_percent', 'expected_close_date', 'next_follow_up_at', 'next_follow_up_type', 'next_follow_up_note'])->defaults('sales_permission', 'sales.opportunity_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.create', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities/{opportunity}', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'show'])->name('sales.opportunities.show')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities/{opportunity}/context', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'opportunityContext'])->name('sales.opportunities.context')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::put('sales/opportunities/{opportunity}', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'update'])->name('sales.opportunities.replace')
        ->defaults('sales_fields', ['client_id', 'primary_contact_id', 'owner_id', 'title', 'type', 'status', 'summary', 'needs', 'employee_count_estimate', 'user_count_estimate', 'workstation_count_estimate', 'server_count_estimate', 'site_count_estimate', 'estimated_value_ex_vat', 'probability_percent', 'expected_close_date', 'next_follow_up_at', 'next_follow_up_type', 'next_follow_up_note'])->defaults('sales_permission', 'sales.opportunity_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.update', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::patch('sales/opportunities/{opportunity}', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'update'])->name('sales.opportunities.update')
        ->defaults('sales_fields', ['client_id', 'primary_contact_id', 'owner_id', 'title', 'type', 'status', 'summary', 'needs', 'employee_count_estimate', 'user_count_estimate', 'workstation_count_estimate', 'server_count_estimate', 'site_count_estimate', 'estimated_value_ex_vat', 'probability_percent', 'expected_close_date', 'next_follow_up_at', 'next_follow_up_type', 'next_follow_up_note'])->defaults('sales_permission', 'sales.opportunity_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.update', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/lost', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'markLost'])->name('sales.opportunities.lost')
        ->defaults('sales_fields', ['lost_reason', 'internal_note'])->defaults('sales_permission', 'sales.opportunity_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.update', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/reopen', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'reopen'])->name('sales.opportunities.reopen')
        ->defaults('sales_fields', ['status'])->defaults('sales_permission', 'sales.opportunity_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.update', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities/{opportunity}/activities', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'activities'])->name('sales.opportunities.activities.index')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/activities', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'storeActivity'])->name('sales.opportunities.activities.store')
        ->defaults('sales_fields', ['type', 'subject', 'body', 'recipient_contact_id', 'to_email', 'cc', 'notify_user_id'])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.update', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/read', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'markRead'])->name('sales.opportunities.read')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.update', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/activities/{activity}/read', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'markActivityRead'])->name('sales.opportunities.activities.read')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.update', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities/{opportunity}/stakeholders', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'stakeholders'])->name('sales.opportunities.stakeholders.index')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/stakeholders', [\App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController::class, 'storeStakeholder'])->name('sales.opportunities.stakeholders.store')
        ->defaults('sales_fields', ['client_user_id', 'role', 'is_primary', 'notes'])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.update', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/leads', [\App\Modules\Sales\Controllers\Api\V1\SalesLeadController::class, 'index'])->name('sales.leads.index')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.lead_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.leads.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/leads/context', [\App\Modules\Sales\Controllers\Api\V1\SalesLeadController::class, 'context'])->name('sales.leads.context')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.lead_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.leads.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/leads/{lead}', [\App\Modules\Sales\Controllers\Api\V1\SalesLeadController::class, 'show'])->name('sales.leads.show')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.lead_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.leads.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::patch('sales/leads/{lead}/classification', [\App\Modules\Sales\Controllers\Api\V1\SalesLeadController::class, 'classify'])->name('sales.leads.classification')
        ->defaults('sales_fields', ['sales_category_id', 'lead_temperature', 'website', 'tag_names'])->defaults('sales_permission', 'sales.lead_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.leads.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/leads/{lead}/start', [\App\Modules\Sales\Controllers\Api\V1\SalesLeadController::class, 'start'])->name('sales.leads.start')
        ->defaults('sales_fields', ['title', 'type', 'owner_id', 'status', 'summary', 'needs', 'employee_count_estimate', 'user_count_estimate', 'workstation_count_estimate', 'server_count_estimate', 'site_count_estimate', 'expected_close_date', 'next_follow_up_at', 'next_follow_up_type', 'next_follow_up_note'])->defaults('sales_permission', 'sales.lead_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.leads.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/clients/quick-create', [\App\Modules\Sales\Controllers\Api\V1\SalesQuickCreateController::class, 'client'])->name('sales.clients.quick-create')
        ->defaults('sales_fields', ['tripletex_number_mode', 'tripletex_request_key', 'tripletex_customer_id', 'name', 'client_number', 'org_no', 'client_format_id', 'website', 'billing_email', 'notes', 'active', 'site_name', 'user_name', 'user_email', 'user_phone', 'user_role'])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.clients.create', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/clients/{client}/contacts/quick-create', [\App\Modules\Sales\Controllers\Api\V1\SalesQuickCreateController::class, 'contact'])->name('sales.contacts.quick-create')
        ->defaults('sales_fields', ['client_site_id', 'name', 'email', 'phone', 'role'])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.contacts.create', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities/{opportunity}/quotes', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'quotes'])->name('sales.quotes.index')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities/{opportunity}/quote/versions', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'versions'])->name('sales.quote-versions.index')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities/{opportunity}/quote/versions/{version}', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'show'])->name('sales.quote-versions.show')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities/{opportunity}/quote/versions/{version}/customer-preview', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'customerPreview'])->name('sales.quote-versions.customerPreview')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities/{opportunity}/quote/versions/{version}/acceptance', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'acceptance'])->name('sales.quote-versions.acceptance')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities/{opportunity}/quote/versions/{version}/conversion-plans', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'plans'])->name('sales.quote-versions.plans')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/opportunities/{opportunity}/quote/versions/{version}/pdf', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'pdf'])->name('sales.quote-versions.pdf')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/quote/draft', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'draft'])->name('sales.quote.draft')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.quote_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::patch('sales/opportunities/{opportunity}/quote/versions/{version}/details', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'details'])->name('sales.quote-versions.details')
        ->defaults('sales_fields', ['title', 'expires_at', 'intro_text', 'scope_text', 'assumptions_text', 'exclusions_text', 'next_steps_text', 'acknowledgement_title', 'acknowledgement_body', 'acknowledgement_required'])->defaults('sales_permission', 'sales.quote_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/quote/versions/{version}/lines', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'addLine'])->name('sales.quote-versions.addLine')
        ->defaults('sales_fields', ['source_type', 'source_id', 'section', 'downstream_type', 'billing_cadence', 'name', 'description', 'quantity', 'unit_price_ex_vat', 'unit_cost_ex_vat', 'discount_value', 'discount_type', 'vat_rate', 'is_optional', 'is_required', 'is_recommended', 'customer_selected_by_default', 'customer_quantity_editable', 'min_customer_quantity', 'max_customer_quantity', 'customer_label', 'option_group_name', 'option_group_type', 'option_group_description', 'option_group_min_select', 'option_group_max_select', 'line_acknowledgement_title', 'line_acknowledgement_body', 'line_acknowledgement_required'])->defaults('sales_permission', 'sales.quote_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::patch('sales/opportunities/{opportunity}/quote/versions/{version}/lines/{line}', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'updateLine'])->name('sales.quote-versions.updateLine')
        ->defaults('sales_fields', ['section', 'downstream_type', 'billing_cadence', 'name', 'description', 'quantity', 'unit_price_ex_vat', 'unit_cost_ex_vat', 'discount_value', 'discount_type', 'vat_rate', 'is_optional', 'is_required', 'is_recommended', 'customer_selected_by_default', 'customer_quantity_editable', 'min_customer_quantity', 'max_customer_quantity', 'customer_label', 'option_group_name', 'option_group_type', 'option_group_description', 'option_group_min_select', 'option_group_max_select', 'line_acknowledgement_title', 'line_acknowledgement_body', 'line_acknowledgement_required'])->defaults('sales_permission', 'sales.quote_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::delete('sales/opportunities/{opportunity}/quote/versions/{version}/lines/{line}', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'deleteLine'])->name('sales.quote-versions.deleteLine')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.quote_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/quote/versions/{version}/revise', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'revise'])->name('sales.quote-versions.revise')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.quote_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/quote/versions/{version}/template', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'template'])->name('sales.quote-versions.template')
        ->defaults('sales_fields', ['template_id', 'replace_existing'])->defaults('sales_permission', 'sales.quote_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/quote/versions/{version}/approval/request', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'requestApproval'])->name('sales.quote-versions.approval.request')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.quote_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/quote/versions/{version}/approval/approve', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'approve'])->name('sales.quote-versions.approval.approve')
        ->defaults('sales_fields', ['note'])->defaults('sales_permission', 'sales.quote.approve')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.approve', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/quote/versions/{version}/approval/reject', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'reject'])->name('sales.quote-versions.approval.reject')
        ->defaults('sales_fields', ['note'])->defaults('sales_permission', 'sales.quote.approve')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.approve', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/quote/versions/{version}/approval/changes', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'changes'])->name('sales.quote-versions.approval.changes')
        ->defaults('sales_fields', ['note'])->defaults('sales_permission', 'sales.quote.approve')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.approve', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::patch('sales/opportunities/{opportunity}/quote/versions/{version}/conversion-plans/{plan}', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteController::class, 'updatePlan'])->name('sales.quote-versions.conversion-plans.update')
        ->defaults('sales_fields', ['status', 'target_reference', 'operator_note'])->defaults('sales_permission', 'sales.quote_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/quote/versions/{version}/send-preview', [\App\Modules\Sales\Controllers\Api\V1\SalesCommunicationController::class, 'quotePreview'])->name('sales.quote-versions.send-preview')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.quote_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.send', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/quote/versions/{version}/send', [\App\Modules\Sales\Controllers\Api\V1\SalesCommunicationController::class, 'quoteSend'])->name('sales.quote-versions.send')
        ->defaults('sales_fields', ['preview_id', 'preview_hash'])->defaults('sales_permission', 'sales.quote_manage')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quotes.send', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/emails/preview', [\App\Modules\Sales\Controllers\Api\V1\SalesCommunicationController::class, 'emailPreview'])->name('sales.opportunities.emails.emailPreview')
        ->defaults('sales_fields', ['subject', 'body', 'recipient_contact_id', 'to_email', 'cc', 'notify_user_id'])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.email.send', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/opportunities/{opportunity}/emails', [\App\Modules\Sales\Controllers\Api\V1\SalesCommunicationController::class, 'emailSend'])->name('sales.opportunities.emails.emailSend')
        ->defaults('sales_fields', ['preview_id', 'preview_hash'])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.email.send', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/operations/{operation}', [\App\Modules\Sales\Controllers\Api\V1\SalesCommunicationController::class, 'show'])->name('sales.operations.show')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.operations.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/operations/{operation}/dispatch', [\App\Modules\Sales\Controllers\Api\V1\SalesCommunicationController::class, 'dispatch'])->name('sales.operations.dispatch')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.view')->defaults('sales_admin', false)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.delivery.dispatch', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/settings/approval-policy', [\App\Modules\Sales\Controllers\Api\V1\SalesAdministrationController::class, 'policy'])->name('sales.settings.approval-policy')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.settings.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::patch('sales/settings/approval-policy', [\App\Modules\Sales\Controllers\Api\V1\SalesAdministrationController::class, 'updatePolicy'])->name('sales.settings.approval-policy.update')
        ->defaults('sales_fields', ['enabled', 'discount_percent_threshold', 'minimum_margin_percent', 'quote_total_ex_vat_threshold', 'manual_line_ex_vat_threshold'])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.settings.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/reports/quotes', [\App\Modules\Sales\Controllers\Api\V1\SalesAdministrationController::class, 'reporting'])->name('sales.reports.quotes')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.reports.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/initialize', [\App\Modules\Sales\Controllers\Api\V1\SalesAdministrationController::class, 'initialize'])->name('sales.initialize')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.settings.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/quote-template-catalog', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController::class, 'catalog'])->name('sales.quote-templates.catalog')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quote_templates.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/quote-templates', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController::class, 'index'])->name('sales.quote-templates.index')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quote_templates.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/quote-templates', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController::class, 'store'])->name('sales.quote-templates.store')
        ->defaults('sales_fields', ['name', 'description', 'is_active', 'target_type', 'customer_segment', 'intro_text', 'scope_text', 'assumptions_text', 'exclusions_text', 'next_steps_text', 'seller_checklist', 'seller_checklist_text', 'approval_policy_hints', 'approval_policy_hints_text'])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quote_templates.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::get('sales/quote-templates/{template}', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController::class, 'show'])->name('sales.quote-templates.show')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quote_templates.read', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::put('sales/quote-templates/{template}', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController::class, 'update'])->name('sales.quote-templates.update')
        ->defaults('sales_fields', ['name', 'description', 'is_active', 'target_type', 'customer_segment', 'intro_text', 'scope_text', 'assumptions_text', 'exclusions_text', 'next_steps_text', 'seller_checklist', 'seller_checklist_text', 'approval_policy_hints', 'approval_policy_hints_text'])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quote_templates.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::delete('sales/quote-templates/{template}', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController::class, 'destroy'])->name('sales.quote-templates.destroy')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quote_templates.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/quote-templates/{template}/lines', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController::class, 'storeLine'])->name('sales.quote-templates.lines.store')
        ->defaults('sales_fields', ['source_reference', 'option_group_id', 'option_group_name', 'option_group_type', 'option_group_description', 'option_group_min_select', 'option_group_max_select', 'section', 'sort_order', 'source_type', 'source_id', 'downstream_type', 'billing_cadence', 'is_required', 'is_recommended', 'customer_selected_by_default', 'customer_quantity_editable', 'min_customer_quantity', 'max_customer_quantity', 'customer_label', 'sku', 'name', 'description', 'quantity', 'unit', 'unit_cost_ex_vat', 'unit_price_ex_vat', 'discount_value', 'discount_type', 'vat_rate'])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quote_templates.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::delete('sales/quote-templates/{template}/lines/{line}', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController::class, 'destroyLine'])->name('sales.quote-templates.lines.destroy')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quote_templates.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::post('sales/quote-templates/{template}/acknowledgements', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController::class, 'storeAcknowledgement'])->name('sales.quote-templates.acknowledgements.store')
        ->defaults('sales_fields', ['template_line_id', 'title', 'body', 'is_required', 'sort_order'])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quote_templates.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);
    Route::delete('sales/quote-templates/{template}/acknowledgements/{acknowledgement}', [\App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController::class, 'destroyAcknowledgement'])->name('sales.quote-templates.acknowledgements.destroy')
        ->defaults('sales_fields', [])->defaults('sales_permission', 'sales.manage_settings')->defaults('sales_admin', true)
        ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':sales.quote_templates.manage', \App\Modules\Sales\Http\Middleware\SalesApiGuard::class, \App\Modules\Sales\Http\Middleware\SalesApiFields::class]);

    return;
}

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
