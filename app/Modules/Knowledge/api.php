<?php

use App\Modules\Knowledge\Controllers\Api\V1\KnowledgeArticleController;
use App\Modules\Knowledge\Controllers\Api\V1\KnowledgeBookController;
use App\Modules\Knowledge\Controllers\Api\V1\KnowledgeChapterController;
use App\Modules\Knowledge\Controllers\Api\V1\KnowledgeShelfController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('knowledge/shelves', [KnowledgeShelfController::class, 'index'])
    ->name('knowledge.shelves.index')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::post('knowledge/shelves', [KnowledgeShelfController::class, 'store'])
    ->name('knowledge.shelves.store')
    ->middleware(CheckAbilities::class.':knowledge.create');

Route::get('knowledge/shelves/{shelf}', [KnowledgeShelfController::class, 'show'])
    ->name('knowledge.shelves.show')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::match(['put', 'patch'], 'knowledge/shelves/{shelf}', [KnowledgeShelfController::class, 'update'])
    ->name('knowledge.shelves.update')
    ->middleware(CheckAbilities::class.':knowledge.update');

Route::delete('knowledge/shelves/{shelf}', [KnowledgeShelfController::class, 'destroy'])
    ->name('knowledge.shelves.destroy')
    ->middleware(CheckAbilities::class.':knowledge.update');

Route::get('knowledge/books', [KnowledgeBookController::class, 'index'])
    ->name('knowledge.books.index')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::post('knowledge/books', [KnowledgeBookController::class, 'store'])
    ->name('knowledge.books.store')
    ->middleware(CheckAbilities::class.':knowledge.create');

Route::get('knowledge/books/{book}', [KnowledgeBookController::class, 'show'])
    ->name('knowledge.books.show')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::match(['put', 'patch'], 'knowledge/books/{book}', [KnowledgeBookController::class, 'update'])
    ->name('knowledge.books.update')
    ->middleware(CheckAbilities::class.':knowledge.update');

Route::delete('knowledge/books/{book}', [KnowledgeBookController::class, 'destroy'])
    ->name('knowledge.books.destroy')
    ->middleware(CheckAbilities::class.':knowledge.update');

Route::get('knowledge/chapters', [KnowledgeChapterController::class, 'index'])
    ->name('knowledge.chapters.index')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::post('knowledge/chapters', [KnowledgeChapterController::class, 'store'])
    ->name('knowledge.chapters.store')
    ->middleware(CheckAbilities::class.':knowledge.create');

Route::get('knowledge/chapters/{chapter}', [KnowledgeChapterController::class, 'show'])
    ->name('knowledge.chapters.show')
    ->middleware(CheckAbilities::class.':knowledge.read');

Route::match(['put', 'patch'], 'knowledge/chapters/{chapter}', [KnowledgeChapterController::class, 'update'])
    ->name('knowledge.chapters.update')
    ->middleware(CheckAbilities::class.':knowledge.update');

Route::delete('knowledge/chapters/{chapter}', [KnowledgeChapterController::class, 'destroy'])
    ->name('knowledge.chapters.destroy')
    ->middleware(CheckAbilities::class.':knowledge.update');

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

Route::delete('knowledge/articles/{article}', [KnowledgeArticleController::class, 'destroy'])
    ->name('knowledge.articles.destroy')
    ->middleware(CheckAbilities::class.':knowledge.update');
