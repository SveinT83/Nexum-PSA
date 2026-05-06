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
Route::get('/knowledge/create', [KnowledgeController::class, 'create'])->name('knowledge.create');
Route::post('/knowledge/store', [KnowledgeController::class, 'store'])->name('knowledge.store');
Route::get('/knowledge/show/{article}', [KnowledgeController::class, 'show'])->name('knowledge.show');
Route::get('/knowledge/edit/{article}', [KnowledgeController::class, 'edit'])->name('knowledge.edit');
Route::put('/knowledge/update/{article}', [KnowledgeController::class, 'update'])->name('knowledge.update');
Route::delete('/knowledge/destroy/{article}', [KnowledgeController::class, 'destroy'])->name('knowledge.destroy');
