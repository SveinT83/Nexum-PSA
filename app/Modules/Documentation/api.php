<?php

use App\Modules\Documentation\Controllers\Api\V1\DocumentationCategoryController;
use App\Modules\Documentation\Controllers\Api\V1\DocumentationController;
use App\Modules\Documentation\Controllers\Api\V1\DocumentationTemplateController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('knowledge/documentations', [DocumentationController::class, 'index'])
    ->name('knowledge.documentations.index')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::post('knowledge/documentations', [DocumentationController::class, 'store'])
    ->name('knowledge.documentations.store')
    ->middleware(CheckAbilities::class.':knowledge.create');

Route::get('knowledge/documentations/{documentation}', [DocumentationController::class, 'show'])
    ->name('knowledge.documentations.show')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::match(['put', 'patch'], 'knowledge/documentations/{documentation}', [DocumentationController::class, 'update'])
    ->name('knowledge.documentations.update')
    ->middleware(CheckAbilities::class.':knowledge.update');

Route::delete('knowledge/documentations/{documentation}', [DocumentationController::class, 'destroy'])
    ->name('knowledge.documentations.destroy')
    ->middleware(CheckAbilities::class.':knowledge.update');

Route::get('knowledge/documentation-categories', [DocumentationCategoryController::class, 'index'])
    ->name('knowledge.documentation-categories.index')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::post('knowledge/documentation-categories', [DocumentationCategoryController::class, 'store'])
    ->name('knowledge.documentation-categories.store')
    ->middleware(CheckAbilities::class.':knowledge.create');

Route::get('knowledge/documentation-categories/{category}', [DocumentationCategoryController::class, 'show'])
    ->name('knowledge.documentation-categories.show')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::match(['put', 'patch'], 'knowledge/documentation-categories/{category}', [DocumentationCategoryController::class, 'update'])
    ->name('knowledge.documentation-categories.update')
    ->middleware(CheckAbilities::class.':knowledge.update');

Route::get('knowledge/documentation-templates', [DocumentationTemplateController::class, 'index'])
    ->name('knowledge.documentation-templates.index')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::post('knowledge/documentation-templates', [DocumentationTemplateController::class, 'store'])
    ->name('knowledge.documentation-templates.store')
    ->middleware(CheckAbilities::class.':knowledge.create');

Route::get('knowledge/documentation-templates/{documentationTemplate}', [DocumentationTemplateController::class, 'show'])
    ->name('knowledge.documentation-templates.show')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::match(['put', 'patch'], 'knowledge/documentation-templates/{documentationTemplate}', [DocumentationTemplateController::class, 'update'])
    ->name('knowledge.documentation-templates.update')
    ->middleware(CheckAbilities::class.':knowledge.update');
