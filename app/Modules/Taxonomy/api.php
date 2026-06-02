<?php

use App\Modules\Taxonomy\Controllers\Api\V1\CategoryController;
use App\Modules\Taxonomy\Controllers\Api\V1\TagController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('taxonomy/categories', [CategoryController::class, 'index'])
    ->name('taxonomy.categories.index')
    ->middleware(CheckAbilities::class.':taxonomy.read');

Route::post('taxonomy/categories', [CategoryController::class, 'store'])
    ->name('taxonomy.categories.store')
    ->middleware(CheckAbilities::class.':taxonomy.create');

Route::get('taxonomy/categories/{category}', [CategoryController::class, 'show'])
    ->name('taxonomy.categories.show')
    ->middleware(CheckAbilities::class.':taxonomy.read');

Route::match(['put', 'patch'], 'taxonomy/categories/{category}', [CategoryController::class, 'update'])
    ->name('taxonomy.categories.update')
    ->middleware(CheckAbilities::class.':taxonomy.update');

Route::delete('taxonomy/categories/{category}', [CategoryController::class, 'destroy'])
    ->name('taxonomy.categories.destroy')
    ->middleware(CheckAbilities::class.':taxonomy.delete');

Route::get('taxonomy/tags', [TagController::class, 'index'])
    ->name('taxonomy.tags.index')
    ->middleware(CheckAbilities::class.':taxonomy.read');

Route::post('taxonomy/tags', [TagController::class, 'store'])
    ->name('taxonomy.tags.store')
    ->middleware(CheckAbilities::class.':taxonomy.create');

Route::get('taxonomy/tags/{tag}', [TagController::class, 'show'])
    ->name('taxonomy.tags.show')
    ->middleware(CheckAbilities::class.':taxonomy.read');

Route::match(['put', 'patch'], 'taxonomy/tags/{tag}', [TagController::class, 'update'])
    ->name('taxonomy.tags.update')
    ->middleware(CheckAbilities::class.':taxonomy.update');

Route::delete('taxonomy/tags/{tag}', [TagController::class, 'destroy'])
    ->name('taxonomy.tags.destroy')
    ->middleware(CheckAbilities::class.':taxonomy.delete');
