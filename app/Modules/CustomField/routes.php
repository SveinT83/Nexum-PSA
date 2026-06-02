<?php

use App\Modules\CustomField\Controllers\Admin\CustomFieldDefinitionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['admin'])->group(function (): void {
    Route::get('/admin/settings/custom-fields', [CustomFieldDefinitionController::class, 'index'])
        ->name('admin.settings.custom-fields.index');

    Route::post('/admin/settings/custom-fields', [CustomFieldDefinitionController::class, 'store'])
        ->name('admin.settings.custom-fields.store');

    Route::patch('/admin/settings/custom-fields/{definition}', [CustomFieldDefinitionController::class, 'update'])
        ->name('admin.settings.custom-fields.update');

    Route::delete('/admin/settings/custom-fields/{definition}', [CustomFieldDefinitionController::class, 'destroy'])
        ->name('admin.settings.custom-fields.destroy');
});
