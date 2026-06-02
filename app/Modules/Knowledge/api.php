<?php

use App\Modules\Knowledge\Controllers\Api\V1\KnowledgeArticleController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('knowledge/articles', [KnowledgeArticleController::class, 'index'])
    ->name('knowledge.articles.index')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::post('knowledge/articles', [KnowledgeArticleController::class, 'store'])
    ->name('knowledge.articles.store')
    ->middleware(CheckAbilities::class.':knowledge.create');

Route::get('knowledge/articles/{article}', [KnowledgeArticleController::class, 'show'])
    ->name('knowledge.articles.show')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::match(['put', 'patch'], 'knowledge/articles/{article}', [KnowledgeArticleController::class, 'update'])
    ->name('knowledge.articles.update')
    ->middleware(CheckAbilities::class.':knowledge.update');
