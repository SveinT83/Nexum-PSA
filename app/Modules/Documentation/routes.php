<?php

use App\Modules\Documentation\Controllers\Admin\TemplateManagementController;
use App\Modules\Documentation\Controllers\Tech\DocumentationController;
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

Route::post('/context/set', [DocumentationController::class, 'setContext'])
    ->name('context.set');

Route::get('/documentations', [DocumentationController::class, 'index'])
    ->name('documentations.index');

Route::get('/documentations/create', [DocumentationController::class, 'create'])
    ->name('documentations.create');

Route::post('/documentations/create', [DocumentationController::class, 'store'])
    ->name('documentations.store');

Route::get('/documentations/show/{documentation}', [DocumentationController::class, 'show'])
    ->name('documentations.show');

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
