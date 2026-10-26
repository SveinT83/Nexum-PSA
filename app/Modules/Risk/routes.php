<?php

use App\Modules\Risk\Controllers\Admin\RiskSettingsController;
use App\Modules\Risk\Controllers\Api\V1\RiskContextController;
use App\Modules\Risk\Controllers\Api\V1\RiskController as RiskApiController;
use App\Modules\Risk\Controllers\Tech\RiskController;
use App\Modules\Risk\Http\Middleware\RiskApiErrors;
use App\Modules\Risk\Http\Middleware\RiskApiGuard;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// Domain-owned APIs; api.php only supplies the surrounding authenticated API group.
if ($riskApiRoutes ?? false) {
    Route::match(['get'], 'risk/context', [RiskContextController::class, 'context'])->name('risk.context')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read');
    Route::match(['get'], 'risk/context/clients', [RiskContextController::class, 'clients'])->name('risk.context.clients')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read');
    Route::match(['get'], 'risk/context/categories', [RiskContextController::class, 'categories'])->name('risk.context.categories')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read');
    Route::match(['get'], 'risk/settings', [RiskApiController::class, 'settings'])->name('risk.settings')->middleware([CheckAbilities::class.':risk.settings.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.manage_settings'])->defaults('risk_scope', 'risk.settings.read');
    Route::match(['put'], 'risk/settings', [RiskApiController::class, 'updateSettings'])->name('risk.updateSettings.put')->middleware([CheckAbilities::class.':risk.settings.update', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.manage_settings'])->defaults('risk_scope', 'risk.settings.update');
    Route::match(['patch'], 'risk/settings', [RiskApiController::class, 'updateSettings'])->name('risk.updateSettings.patch')->middleware([CheckAbilities::class.':risk.settings.update', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.manage_settings'])->defaults('risk_scope', 'risk.settings.update');
    Route::match(['get'], 'risk/operations/{command}', [RiskApiController::class, 'operation'])->name('risk.operation')->middleware([CheckAbilities::class.':risk.operations.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', [])->defaults('risk_scope', 'risk.operations.read');
    Route::match(['get'], 'risk/assessments', [RiskApiController::class, 'assessments'])->name('risk.assessments.index')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read');
    Route::match(['post'], 'risk/assessments', [RiskApiController::class, 'storeAssessment'])->name('risk.assessments.store')->middleware([CheckAbilities::class.':risk.create', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.create'])->defaults('risk_scope', 'risk.create');
    Route::match(['get'], 'risk/assessments/{assessment}', [RiskApiController::class, 'showAssessment'])->name('risk.assessments.show')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read')->whereNumber('assessment');
    Route::match(['put', 'patch'], 'risk/assessments/{assessment}', [RiskApiController::class, 'updateAssessment'])->name('risk.assessments.update')->middleware([CheckAbilities::class.':risk.update', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.update'])->defaults('risk_scope', 'risk.update')->whereNumber('assessment');
    Route::match(['delete'], 'risk/assessments/{assessment}', [RiskApiController::class, 'destroyAssessment'])->name('risk.destroyAssessment')->middleware([CheckAbilities::class.':risk.delete', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.delete'])->defaults('risk_scope', 'risk.delete')->whereNumber('assessment');
    Route::match(['get'], 'risk/assessments/{assessment}/context', [RiskContextController::class, 'assessment'])->name('risk.assessment')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read')->whereNumber('assessment');
    Route::match(['get'], 'risk/assessments/{assessment}/items', [RiskApiController::class, 'items'])->name('risk.items')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read')->whereNumber('assessment');
    Route::match(['post'], 'risk/assessments/{assessment}/items', [RiskApiController::class, 'storeItem'])->name('risk.assessments.items.store')->middleware([CheckAbilities::class.':risk.create', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.update'])->defaults('risk_scope', 'risk.create')->whereNumber('assessment');
    Route::match(['post'], 'risk/assessments/{assessment}/approve', [RiskApiController::class, 'approve'])->name('risk.approve')->middleware([CheckAbilities::class.':risk.approve', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.approve')->whereNumber('assessment');
    Route::match(['get'], 'risk/assessments/{assessment}/pdf', [RiskApiController::class, 'pdf'])->name('risk.pdf')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read')->whereNumber('assessment');
    Route::match(['get'], 'risk/items/{item}', [RiskApiController::class, 'showItem'])->name('risk.items.show')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read')->whereNumber('item');
    Route::match(['put', 'patch'], 'risk/items/{item}', [RiskApiController::class, 'updateItem'])->name('risk.items.update')->middleware([CheckAbilities::class.':risk.update', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.update'])->defaults('risk_scope', 'risk.update')->whereNumber('item');
    Route::match(['delete'], 'risk/items/{item}', [RiskApiController::class, 'destroyItem'])->name('risk.destroyItem')->middleware([CheckAbilities::class.':risk.delete', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.delete'])->defaults('risk_scope', 'risk.delete')->whereNumber('item');
    Route::match(['get'], 'risk/items/{item}/context', [RiskContextController::class, 'item'])->name('risk.item')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read')->whereNumber('item');
    Route::match(['get'], 'risk/items/{item}/updates', [RiskApiController::class, 'updates'])->name('risk.updates')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read')->whereNumber('item');
    Route::match(['post'], 'risk/items/{item}/updates', [RiskApiController::class, 'storeItemUpdate'])->name('risk.items.updates.store')->middleware([CheckAbilities::class.':risk.update', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.update')->whereNumber('item');
    Route::match(['get'], 'risk/updates/{update}', [RiskApiController::class, 'showUpdate'])->name('risk.showUpdate')->middleware([CheckAbilities::class.':risk.read', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.view'])->defaults('risk_scope', 'risk.read')->whereNumber('update');
    Route::match(['delete'], 'risk/updates/{update}', [RiskApiController::class, 'destroyUpdate'])->name('risk.destroyUpdate')->middleware([CheckAbilities::class.':risk.delete', RiskApiErrors::class, RiskApiGuard::class])->defaults('risk_permissions', ['risk.delete'])->defaults('risk_scope', 'risk.delete')->whereNumber('update');

    return;
}

// Existing browser URLs remain unchanged.
Route::get('/admin/settings/risk', [RiskSettingsController::class, 'edit'])->name('admin.settings.risk');
Route::put('/admin/settings/risk', [RiskSettingsController::class, 'update'])->name('admin.settings.risk.update');

// Assessment lifecycle: list, create, read, update, and soft-delete.
Route::get('/risk', [RiskController::class, 'index'])->name('risk.index');
Route::get('/risk/create', [RiskController::class, 'create'])->name('risk.create');
Route::post('/risk/store', [RiskController::class, 'store'])->name('risk.store');
Route::get('/risk/show/{risk}', [RiskController::class, 'show'])->name('risk.show');
Route::get('/risk/edit/{risk}', [RiskController::class, 'edit'])->name('risk.edit');
Route::put('/risk/update/{risk}', [RiskController::class, 'update'])->name('risk.update');
Route::delete('/risk/destroy/{risk}', [RiskController::class, 'destroy'])->name('risk.destroy');

// Assessment-level operations that depend on the current item state.
Route::post('/risk/show/{risk}/items', [RiskController::class, 'storeItem'])->name('risk.items.store');
Route::post('/risk/show/{risk}/approve', [RiskController::class, 'approve'])->name('risk.approve');
Route::get('/risk/show/{risk}/pdf', [RiskController::class, 'exportPdf'])->name('risk.pdf');

// Risk item current-state screens and descriptive edits.
Route::get('/risk/items/{item}', [RiskController::class, 'showItem'])->name('risk.items.show');
Route::put('/risk/items/{item}', [RiskController::class, 'updateItem'])->name('risk.items.update');
Route::delete('/risk/items/{item}', [RiskController::class, 'destroyItem'])->name('risk.items.destroy');

// Risk item history. Updating likelihood, impact, or status should go here.
Route::post('/risk/items/{item}/updates', [RiskController::class, 'storeItemUpdate'])->name('risk.items.updates.store');
Route::delete('/risk/updates/{update}', [RiskController::class, 'destroyUpdate'])->name('risk.updates.destroy');
