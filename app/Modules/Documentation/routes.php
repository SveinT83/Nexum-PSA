<?php

use App\Modules\Documentation\Controllers\Admin\TemplateManagementController;
use App\Modules\Documentation\Controllers\Portal\PortalDocumentationController;
use App\Modules\Documentation\Controllers\Tech\DocumentationController;
use App\Modules\Documentation\Controllers\Tech\VendorController;
use App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess;
use Illuminate\Support\Facades\Route;

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
