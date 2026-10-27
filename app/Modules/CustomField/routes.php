<?php

use App\Modules\CustomField\Controllers\Admin\CustomFieldDefinitionController;
use App\Modules\CustomField\Controllers\Api\V1\CustomFieldDefinitionController as ApiController;
use App\Modules\CustomField\Support\CustomFieldApiAccess;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// The existing API loader selects this branch; all domain routes remain module-owned.
if (($customFieldApiRoutes ?? false) === true) {
    Route::middleware(CustomFieldApiAccess::class)->group(function (): void {
        foreach ([
            ['get', 'custom-fields', 'index', 'read', false],
            ['get', 'custom-fields/context', 'context', 'read', true],
            ['get', 'custom-fields/operations/{operation}', 'operation', 'operations.read', true],
            ['get', 'custom-fields/{definition}', 'show', 'read', false],
            ['post', 'custom-fields', 'store', 'create', true],
            ['patch', 'custom-fields/{definition}', 'update', 'update', true],
            ['delete', 'custom-fields/{definition}', 'destroy', 'delete', true],
        ] as [$method, $path, $action, $scope, $manage]) {
            Route::$method($path, [ApiController::class, $action])->name('custom-fields.'.$action)
                ->middleware(CheckAbilities::class.':custom-fields.'.$scope)
                ->defaults('customfield_ability', 'custom-fields.'.$scope)->defaults('customfield_manage', $manage)
                ->whereNumber('definition')->whereUuid('operation');
        }
    });

    return;
}

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
