<?php

use App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess;
use App\Modules\Documentation\Controllers\Admin\TemplateManagementController;
use App\Modules\Documentation\Controllers\Portal\PortalDocumentationController;
use App\Modules\Documentation\Controllers\Tech\DocumentationController;
use App\Modules\Documentation\Controllers\Tech\ShippingCarrierController;
use App\Modules\Documentation\Controllers\Tech\VendorController;
use Illuminate\Support\Facades\Route;

// Portal API uses the same current account/contact/membership as portal HTML.
if (($documentationPortalApiRoutes ?? false) === true) {
    Route::middleware(['web', 'auth:sanctum', \App\Modules\Documentation\Support\PortalDocumentationApiAccess::class, 'throttle:60,1'])
        ->group(function (): void {
            Route::get('portal/documents', [\App\Modules\Documentation\Controllers\Api\V1\PortalDocumentationController::class, 'index'])
                ->name('documentation.portal.index');
            Route::get('portal/documents/{documentation}', [\App\Modules\Documentation\Controllers\Api\V1\PortalDocumentationController::class, 'show'])
                ->name('documentation.portal.show');
        });

    return;
}

// Official domain API routes. The legacy URL namespace remains compatible.
if (($documentationApiRoutes ?? false) === true) {
    Route::middleware([
        \App\Modules\Documentation\Support\DocumentationApiAccess::class,
        \App\Modules\Documentation\Actions\ExecuteDocumentationApiCommand::class,
    ])->group(function (): void {
        Route::get('knowledge/documentations', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationController::class, 'index'])
            ->name('knowledge.documentations.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
            ->defaults('documentation_permissions', ['documentation.view'])->defaults('documentation_ability', 'knowledge.read')
            ->defaults('documentation_fields', ['q', 'page', 'per_page', 'category_id', 'category_slug', 'template_id', 'client_id', 'site_id', 'scope_type', 'work_context_id', 'context_type', 'exclude_internal']);
        Route::post('knowledge/documentations', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationController::class, 'store'])
            ->name('knowledge.documentations.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.create')
            ->defaults('documentation_permissions', ['documentation.create'])->defaults('documentation_ability', 'knowledge.create')
            ->defaults('documentation_fields', ['title', 'category_id', 'category_slug', 'template_id', 'client_id', 'site_id', 'scope_type', 'data', 'content', 'body']);
        Route::get('knowledge/documentations/{documentation}', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationController::class, 'show'])
            ->name('knowledge.documentations.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
            ->defaults('documentation_permissions', [])->defaults('documentation_ability', 'knowledge.read')
            ->defaults('documentation_fields', [])->defaults('documentation_target', 'documentation')->defaults('documentation_any_permissions', ['documentation.view', 'documentation.update']);
        Route::match(['put', 'patch'], 'knowledge/documentations/{documentation}', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationController::class, 'update'])
            ->name('knowledge.documentations.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
            ->defaults('documentation_permissions', ['documentation.update'])->defaults('documentation_ability', 'knowledge.update')
            ->defaults('documentation_fields', ['title', 'category_id', 'category_slug', 'template_id', 'client_id', 'site_id', 'scope_type', 'data', 'content', 'body'])->defaults('documentation_target', 'documentation');
        Route::delete('knowledge/documentations/{documentation}', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationController::class, 'destroy'])
            ->name('knowledge.documentations.destroy')->withTrashed()->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
            ->defaults('documentation_permissions', ['documentation.delete'])->defaults('documentation_ability', 'knowledge.update')
            ->defaults('documentation_fields', [])->defaults('documentation_target', 'documentation');
        Route::get('knowledge/documentation-categories', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationCategoryController::class, 'index'])
            ->name('knowledge.documentation-categories.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
            ->defaults('documentation_permissions', [])->defaults('documentation_ability', 'knowledge.read')
            ->defaults('documentation_fields', ['q', 'page', 'per_page', 'category_id', 'category_slug', 'include_inactive'])->defaults('documentation_any_permissions', ['documentation.view', 'taxonomy.manage_categories']);
        Route::post('knowledge/documentation-categories', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationCategoryController::class, 'store'])
            ->name('knowledge.documentation-categories.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.create')
            ->defaults('documentation_permissions', ['taxonomy.manage_categories'])->defaults('documentation_ability', 'knowledge.create')
            ->defaults('documentation_fields', ['parent_id', 'name', 'slug', 'description', 'is_active']);
        Route::get('knowledge/documentation-categories/{category}', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationCategoryController::class, 'show'])
            ->name('knowledge.documentation-categories.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
            ->defaults('documentation_permissions', [])->defaults('documentation_ability', 'knowledge.read')
            ->defaults('documentation_fields', [])->defaults('documentation_target', 'category')->defaults('documentation_any_permissions', ['documentation.view', 'taxonomy.manage_categories']);
        Route::match(['put', 'patch'], 'knowledge/documentation-categories/{category}', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationCategoryController::class, 'update'])
            ->name('knowledge.documentation-categories.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
            ->defaults('documentation_permissions', ['taxonomy.manage_categories'])->defaults('documentation_ability', 'knowledge.update')
            ->defaults('documentation_fields', ['parent_id', 'name', 'slug', 'description', 'is_active'])->defaults('documentation_target', 'category');
        Route::get('knowledge/documentation-templates', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationTemplateController::class, 'index'])
            ->name('knowledge.documentation-templates.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
            ->defaults('documentation_permissions', [])->defaults('documentation_ability', 'knowledge.read')
            ->defaults('documentation_fields', ['q', 'page', 'per_page', 'category_id', 'category_slug', 'include_inactive'])->defaults('documentation_any_permissions', ['documentation.view', 'documentation.manage_templates']);
        Route::post('knowledge/documentation-templates', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationTemplateController::class, 'store'])
            ->name('knowledge.documentation-templates.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.create')
            ->defaults('documentation_permissions', ['documentation.manage_templates'])->defaults('documentation_ability', 'knowledge.create')
            ->defaults('documentation_fields', ['category_id', 'category_slug', 'name', 'fields', 'is_active']);
        Route::get('knowledge/documentation-templates/{documentationTemplate}', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationTemplateController::class, 'show'])
            ->name('knowledge.documentation-templates.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
            ->defaults('documentation_permissions', [])->defaults('documentation_ability', 'knowledge.read')
            ->defaults('documentation_fields', [])->defaults('documentation_target', 'documentationTemplate')->defaults('documentation_any_permissions', ['documentation.view', 'documentation.manage_templates']);
        Route::match(['put', 'patch'], 'knowledge/documentation-templates/{documentationTemplate}', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationTemplateController::class, 'update'])
            ->name('knowledge.documentation-templates.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
            ->defaults('documentation_permissions', ['documentation.manage_templates'])->defaults('documentation_ability', 'knowledge.update')
            ->defaults('documentation_fields', ['category_id', 'category_slug', 'name', 'fields', 'is_active'])->defaults('documentation_target', 'documentationTemplate');
        Route::get('documentation/context', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationWorkspaceController::class, 'context'])
            ->name('documentation.context')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
            ->defaults('documentation_permissions', [])->defaults('documentation_ability', 'knowledge.read')
            ->defaults('documentation_fields', [])->defaults('documentation_any_permissions', ['documentation.view', 'documentation.create', 'documentation.update', 'documentation.carrier_manage', 'documentation.manage_templates', 'system.settings_manage', 'taxonomy.manage_categories']);
        Route::get('documentation/lookups/{kind}', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationWorkspaceController::class, 'lookups'])
            ->name('documentation.lookups')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
            ->defaults('documentation_permissions', [])->defaults('documentation_ability', 'knowledge.read')
            ->defaults('documentation_fields', ['q', 'client_id', 'page', 'per_page']);
        Route::get('documentation/operations/{operation}', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationWorkspaceController::class, 'operation'])
            ->name('documentation.operations.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.operations.read')
            ->defaults('documentation_permissions', [])->defaults('documentation_ability', 'documentation.operations.read')
            ->defaults('documentation_fields', []);
        Route::post('knowledge/documentations/{documentation}/portal-visibility', [\App\Modules\Documentation\Controllers\Api\V1\DocumentationController::class, 'visibility'])
            ->name('documentation.portal-visibility.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.portal.manage')
            ->defaults('documentation_permissions', ['documentation.update'])->defaults('documentation_ability', 'documentation.portal.manage')
            ->defaults('documentation_fields', ['portal_visible'])->defaults('documentation_target', 'documentation')->defaults('documentation_key_required', true);
        Route::get('documentation/vendors', [\App\Modules\Documentation\Controllers\Api\V1\VendorController::class, 'index'])
            ->name('documentation.vendors.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.vendors.read')
            ->defaults('documentation_permissions', ['documentation.view'])->defaults('documentation_ability', 'documentation.vendors.read')
            ->defaults('documentation_fields', ['q', 'role', 'is_active', 'page', 'per_page']);
        Route::post('documentation/vendors', [\App\Modules\Documentation\Controllers\Api\V1\VendorController::class, 'store'])
            ->name('documentation.vendors.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.vendors.create')
            ->defaults('documentation_permissions', ['documentation.create'])->defaults('documentation_ability', 'documentation.vendors.create')
            ->defaults('documentation_fields', ['name', 'vendor_code', 'org_no', 'url', 'phone', 'email', 'default_lead_time_days', 'note', 'terms', 'is_vendor', 'is_supplier', 'is_manufacturer', 'is_active'])->defaults('documentation_key_required', true)->defaults('vendor_role', 'vendors');
        Route::get('documentation/suppliers', [\App\Modules\Documentation\Controllers\Api\V1\VendorController::class, 'index'])
            ->name('documentation.suppliers.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.vendors.read')
            ->defaults('documentation_permissions', ['documentation.view'])->defaults('documentation_ability', 'documentation.vendors.read')
            ->defaults('documentation_fields', ['q', 'role', 'is_active', 'page', 'per_page'])->defaults('vendor_role', 'suppliers');
        Route::post('documentation/suppliers', [\App\Modules\Documentation\Controllers\Api\V1\VendorController::class, 'store'])
            ->name('documentation.suppliers.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.vendors.create')
            ->defaults('documentation_permissions', ['documentation.create'])->defaults('documentation_ability', 'documentation.vendors.create')
            ->defaults('documentation_fields', ['name', 'vendor_code', 'org_no', 'url', 'phone', 'email', 'default_lead_time_days', 'note', 'terms', 'is_vendor', 'is_supplier', 'is_manufacturer', 'is_active'])->defaults('documentation_key_required', true)->defaults('vendor_role', 'suppliers');
        Route::get('documentation/vendors/{vendor}', [\App\Modules\Documentation\Controllers\Api\V1\VendorController::class, 'show'])
            ->name('documentation.vendors.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.vendors.read')
            ->defaults('documentation_permissions', [])->defaults('documentation_ability', 'documentation.vendors.read')
            ->defaults('documentation_fields', [])->defaults('documentation_target', 'vendor')->defaults('documentation_any_permissions', ['documentation.view', 'documentation.update']);
        Route::patch('documentation/vendors/{vendor}', [\App\Modules\Documentation\Controllers\Api\V1\VendorController::class, 'update'])
            ->name('documentation.vendors.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.vendors.update')
            ->defaults('documentation_permissions', ['documentation.update'])->defaults('documentation_ability', 'documentation.vendors.update')
            ->defaults('documentation_fields', ['name', 'vendor_code', 'org_no', 'url', 'phone', 'email', 'default_lead_time_days', 'note', 'terms', 'is_vendor', 'is_supplier', 'is_manufacturer', 'is_active'])->defaults('documentation_target', 'vendor')->defaults('documentation_key_required', true);
        Route::get('documentation/shipping-carriers', [\App\Modules\Documentation\Controllers\Api\V1\ShippingCarrierController::class, 'index'])
            ->name('documentation.shipping-carriers.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.carriers.read')
            ->defaults('documentation_permissions', ['documentation.view'])->defaults('documentation_ability', 'documentation.carriers.read')
            ->defaults('documentation_fields', ['q', 'lifecycle_state', 'page', 'per_page']);
        Route::post('documentation/shipping-carriers', [\App\Modules\Documentation\Controllers\Api\V1\ShippingCarrierController::class, 'store'])
            ->name('documentation.shipping-carriers.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.carriers.manage')
            ->defaults('documentation_permissions', ['documentation.carrier_manage'])->defaults('documentation_ability', 'documentation.carriers.manage')
            ->defaults('documentation_fields', ['code', 'name', 'vendor_id', 'legal_name', 'lifecycle_state', 'sort_order', 'service_tags', 'website_url', 'support_url', 'tracking_page_url', 'tracking_method', 'tracking_url_template', 'allowed_tracking_hosts', 'link_visibility', 'connector_type', 'source_url', 'verification_state', 'verified_at', 'notes'])->defaults('documentation_key_required', true);
        Route::get('documentation/shipping-carriers/{shippingCarrier}', [\App\Modules\Documentation\Controllers\Api\V1\ShippingCarrierController::class, 'show'])
            ->name('documentation.shipping-carriers.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.carriers.read')
            ->defaults('documentation_permissions', [])->defaults('documentation_ability', 'documentation.carriers.read')
            ->defaults('documentation_fields', [])->defaults('documentation_target', 'shippingCarrier')->defaults('documentation_any_permissions', ['documentation.view', 'documentation.carrier_manage']);
        Route::patch('documentation/shipping-carriers/{shippingCarrier}', [\App\Modules\Documentation\Controllers\Api\V1\ShippingCarrierController::class, 'update'])
            ->name('documentation.shipping-carriers.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.carriers.manage')
            ->defaults('documentation_permissions', ['documentation.carrier_manage'])->defaults('documentation_ability', 'documentation.carriers.manage')
            ->defaults('documentation_fields', ['code', 'name', 'vendor_id', 'legal_name', 'lifecycle_state', 'sort_order', 'service_tags', 'website_url', 'support_url', 'tracking_page_url', 'tracking_method', 'tracking_url_template', 'allowed_tracking_hosts', 'link_visibility', 'connector_type', 'source_url', 'verification_state', 'verified_at', 'notes'])->defaults('documentation_target', 'shippingCarrier')->defaults('documentation_key_required', true);
        Route::post('documentation/shipping-carriers/{shippingCarrier}/tracking-preview', [\App\Modules\Documentation\Controllers\Api\V1\ShippingCarrierController::class, 'preview'])
            ->name('documentation.shipping-carriers.tracking-preview')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':documentation.carriers.read')
            ->defaults('documentation_permissions', ['documentation.view'])->defaults('documentation_ability', 'documentation.carriers.read')
            ->defaults('documentation_fields', ['tracking_number', 'direct_url'])->defaults('documentation_target', 'shippingCarrier')->defaults('documentation_preview', true);
    });

    return;
}

/*
|--------------------------------------------------------------------------
| Documentation Module Routes
|--------------------------------------------------------------------------
|
| These routes are loaded inside the authenticated /tech route group. Public
| route names intentionally remain `tech.documentations.*` and
| `tech.context.set` so existing menus and Blade links keep working while the
| implementation lives in the singular Documentation module.
|
*/

if (($documentationPortalRoutes ?? false) === true) {
    Route::middleware(['auth', EnsureCustomerPortalAccess::class])
        ->prefix('portal/documents')
        ->name('customer-portal.documents.')
        ->group(function (): void {
            Route::get('/', [PortalDocumentationController::class, 'index'])->name('index');
            Route::get('/{documentation}', [PortalDocumentationController::class, 'show'])->name('show');
        });

    return;
}

Route::post('/context/set', [DocumentationController::class, 'setContext'])
    ->name('context.set');

Route::get('/documentations', [DocumentationController::class, 'index'])
    ->name('documentations.index');

Route::get('/documentations/create', [DocumentationController::class, 'create'])
    ->name('documentations.create');

Route::get('/documentations/shipping-carriers', [ShippingCarrierController::class, 'index'])
    ->name('documentations.shipping-carriers.index');

Route::get('/documentations/shipping-carriers/create', [ShippingCarrierController::class, 'create'])
    ->name('documentations.shipping-carriers.create');

Route::post('/documentations/shipping-carriers', [ShippingCarrierController::class, 'store'])
    ->name('documentations.shipping-carriers.store');

Route::get('/documentations/shipping-carriers/{shippingCarrier}', [ShippingCarrierController::class, 'show'])
    ->name('documentations.shipping-carriers.show');

Route::get('/documentations/shipping-carriers/{shippingCarrier}/edit', [ShippingCarrierController::class, 'edit'])
    ->name('documentations.shipping-carriers.edit');

Route::patch('/documentations/shipping-carriers/{shippingCarrier}', [ShippingCarrierController::class, 'update'])
    ->name('documentations.shipping-carriers.update');

Route::get('/documentations/vendors', [VendorController::class, 'index'])
    ->defaults('role', 'vendors')
    ->name('documentations.vendors.index');

Route::get('/documentations/vendors/create', [VendorController::class, 'create'])
    ->defaults('role', 'vendors')
    ->name('documentations.vendors.create');

Route::post('/documentations/vendors', [VendorController::class, 'store'])
    ->defaults('role', 'vendors')
    ->name('documentations.vendors.store');

Route::get('/documentations/vendors/{vendor}', [VendorController::class, 'show'])
    ->name('documentations.vendors.show');

Route::get('/documentations/vendors/{vendor}/edit', [VendorController::class, 'edit'])
    ->name('documentations.vendors.edit');

Route::patch('/documentations/vendors/{vendor}', [VendorController::class, 'update'])
    ->name('documentations.vendors.update');

Route::get('/documentations/suppliers/create', [VendorController::class, 'create'])
    ->defaults('role', 'suppliers')
    ->name('documentations.suppliers.create');

Route::post('/documentations/suppliers', [VendorController::class, 'store'])
    ->defaults('role', 'suppliers')
    ->name('documentations.suppliers.store');

Route::get('/documentations/suppliers', [VendorController::class, 'index'])
    ->defaults('role', 'suppliers')
    ->name('documentations.suppliers.index');

Route::post('/documentations/create', [DocumentationController::class, 'store'])
    ->name('documentations.store');

Route::get('/documentations/show/{documentation}', [DocumentationController::class, 'show'])
    ->name('documentations.show');

Route::post('/documentations/{documentation}/portal-visibility', [DocumentationController::class, 'updatePortalVisibility'])
    ->name('documentations.portal-visibility.update');

Route::get('/documentations/edit/{documentation}', [DocumentationController::class, 'edit'])
    ->name('documentations.edit');

Route::put('/documentations/update/{documentation}', [DocumentationController::class, 'update'])
    ->name('documentations.update');

Route::delete('/documentations/destroy/{documentation}', [DocumentationController::class, 'destroy'])
    ->name('documentations.destroy');

Route::middleware('admin')->group(function () {
    Route::get('/admin/system/templatesManagement', [TemplateManagementController::class, 'index'])
        ->name('admin.system.templatesManagement.index');

    Route::get('/admin/system/templatesManagement/doc', [TemplateManagementController::class, 'docIndex'])
        ->name('admin.system.templatesManagement.doc.index');

    Route::get('/admin/system/templatesManagement/doc/create', [TemplateManagementController::class, 'docCreate'])
        ->name('admin.system.templatesManagement.doc.create');

    Route::get('/admin/system/templatesManagement/doc/edit/{id}', [TemplateManagementController::class, 'docEdit'])
        ->name('admin.system.templatesManagement.doc.edit');
});
