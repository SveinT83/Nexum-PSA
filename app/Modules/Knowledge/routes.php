<?php

use App\Modules\Knowledge\Controllers\Tech\KnowledgeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Knowledge Module Routes
|--------------------------------------------------------------------------
|
| These routes are loaded from the authenticated /tech route group. Keep every
| Knowledge domain route in this file so the module remains independent of
| Laravel's default route files.
|
*/

Route::get('/knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
Route::get('/knowledge/shelves/create', [KnowledgeController::class, 'createShelf'])->name('knowledge.shelves.create');
Route::post('/knowledge/shelves', [KnowledgeController::class, 'storeShelf'])->name('knowledge.shelves.store');
Route::get('/knowledge/shelves/{shelf}', [KnowledgeController::class, 'shelf'])->name('knowledge.shelf');
Route::get('/knowledge/shelves/{shelf}/edit', [KnowledgeController::class, 'editShelf'])->name('knowledge.shelves.edit');
Route::put('/knowledge/shelves/{shelf}', [KnowledgeController::class, 'updateShelf'])->name('knowledge.shelves.update');
Route::delete('/knowledge/shelves/{shelf}', [KnowledgeController::class, 'destroyShelf'])->name('knowledge.shelves.destroy');
Route::get('/knowledge/shelves/{shelf}/books/create', [KnowledgeController::class, 'createBook'])->name('knowledge.books.create');
Route::post('/knowledge/shelves/{shelf}/books', [KnowledgeController::class, 'storeBook'])->name('knowledge.books.store');
Route::get('/knowledge/books/{book}', [KnowledgeController::class, 'book'])->name('knowledge.book');
Route::get('/knowledge/books/{book}/edit', [KnowledgeController::class, 'editBook'])->name('knowledge.books.edit');
Route::put('/knowledge/books/{book}', [KnowledgeController::class, 'updateBook'])->name('knowledge.books.update');
Route::delete('/knowledge/books/{book}', [KnowledgeController::class, 'destroyBook'])->name('knowledge.books.destroy');
Route::get('/knowledge/books/{book}/chapters/create', [KnowledgeController::class, 'createChapter'])->name('knowledge.chapters.create');
Route::post('/knowledge/books/{book}/chapters', [KnowledgeController::class, 'storeChapter'])->name('knowledge.chapters.store');
Route::get('/knowledge/chapters/{chapter}/edit', [KnowledgeController::class, 'editChapter'])->name('knowledge.chapters.edit');
Route::put('/knowledge/chapters/{chapter}', [KnowledgeController::class, 'updateChapter'])->name('knowledge.chapters.update');
Route::delete('/knowledge/chapters/{chapter}', [KnowledgeController::class, 'destroyChapter'])->name('knowledge.chapters.destroy');
Route::get('/knowledge/books/{book}/pages/create', [KnowledgeController::class, 'createPageInBook'])->name('knowledge.books.pages.create');
Route::get('/knowledge/create', [KnowledgeController::class, 'create'])->name('knowledge.create');
Route::post('/knowledge/store', [KnowledgeController::class, 'store'])->name('knowledge.store');
Route::get('/knowledge/show/{article}', [KnowledgeController::class, 'show'])->name('knowledge.show');
Route::get('/knowledge/edit/{article}', [KnowledgeController::class, 'edit'])->name('knowledge.edit');
Route::put('/knowledge/update/{article}', [KnowledgeController::class, 'update'])->name('knowledge.update');
Route::delete('/knowledge/destroy/{article}', [KnowledgeController::class, 'destroy'])->name('knowledge.destroy');
