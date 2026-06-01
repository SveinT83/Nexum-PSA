<?php

use App\Modules\Task\Controllers\Admin\TaskSettingsController;
use App\Modules\Task\Controllers\Tech\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/settings/tasks', [TaskSettingsController::class, 'edit'])->name('admin.settings.tasks');
Route::put('/admin/settings/tasks', [TaskSettingsController::class, 'update'])->name('admin.settings.tasks.update');

Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
Route::get('/tasks/create', [TaskController::class, 'create'])->name('tasks.create');
Route::post('/tasks/ai-suggest', [TaskController::class, 'aiSuggest'])->name('tasks.ai-suggest');
Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
Route::get('/tasks/docs', [TaskController::class, 'docs'])->name('tasks.docs');
Route::get('/tasks/{task}/edit', [TaskController::class, 'edit'])->name('tasks.edit');
Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
Route::patch('/tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.status.update');
Route::patch('/tasks/{task}/assign', [TaskController::class, 'assign'])->name('tasks.assign');
Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
Route::patch('/tasks/{task}/checklist/{item}', [TaskController::class, 'toggleChecklistItem'])->name('tasks.checklist.toggle');
