<?php

use App\Modules\Task\Controllers\Admin\TaskSettingsController;
use App\Modules\Task\Controllers\Admin\TaskTemplateController;
use App\Modules\Task\Controllers\Tech\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/settings/tasks', [TaskSettingsController::class, 'edit'])->name('admin.settings.tasks');
Route::put('/admin/settings/tasks', [TaskSettingsController::class, 'update'])->name('admin.settings.tasks.update');
Route::get('/admin/task-templates', [TaskTemplateController::class, 'index'])->name('admin.task-templates.index');
Route::get('/admin/task-templates/create', [TaskTemplateController::class, 'create'])->name('admin.task-templates.create');
Route::post('/admin/task-templates', [TaskTemplateController::class, 'store'])->name('admin.task-templates.store');
Route::get('/admin/task-templates/{template}', [TaskTemplateController::class, 'show'])->name('admin.task-templates.show');
Route::get('/admin/task-templates/{template}/edit', [TaskTemplateController::class, 'edit'])->name('admin.task-templates.edit');
Route::put('/admin/task-templates/{template}', [TaskTemplateController::class, 'update'])->name('admin.task-templates.update');
Route::delete('/admin/task-templates/{template}', [TaskTemplateController::class, 'destroy'])->name('admin.task-templates.destroy');
Route::post('/admin/task-templates/{template}/items', [TaskTemplateController::class, 'storeItem'])->name('admin.task-templates.items.store');
Route::put('/admin/task-templates/{template}/items/{item}', [TaskTemplateController::class, 'updateItem'])->name('admin.task-templates.items.update');
Route::delete('/admin/task-templates/{template}/items/{item}', [TaskTemplateController::class, 'destroyItem'])->name('admin.task-templates.items.destroy');
Route::post('/admin/task-templates/{template}/schedules', [TaskTemplateController::class, 'storeSchedule'])->name('admin.task-templates.schedules.store');
Route::put('/admin/task-templates/{template}/schedules/{schedule}', [TaskTemplateController::class, 'updateSchedule'])->name('admin.task-templates.schedules.update');
Route::patch('/admin/task-templates/{template}/schedules/{schedule}/toggle', [TaskTemplateController::class, 'toggleSchedule'])->name('admin.task-templates.schedules.toggle');
Route::delete('/admin/task-templates/{template}/schedules/{schedule}', [TaskTemplateController::class, 'destroySchedule'])->name('admin.task-templates.schedules.destroy');
Route::post('/admin/task-templates/{template}/schedules/{schedule}/run', [TaskTemplateController::class, 'runSchedule'])->name('admin.task-templates.schedules.run');
Route::get('/task-templates/choose', [TaskTemplateController::class, 'choose'])->name('task-templates.choose');
Route::get('/task-templates/{template}/preview', [TaskTemplateController::class, 'preview'])->name('task-templates.preview');
Route::post('/task-templates/{template}/apply', [TaskTemplateController::class, 'apply'])->name('task-templates.apply');

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
Route::post('/tasks/{task}/time-entries', [TaskController::class, 'storeTimeEntry'])->name('tasks.time-entries.store');
Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
Route::patch('/tasks/{task}/checklist/{item}', [TaskController::class, 'toggleChecklistItem'])->name('tasks.checklist.toggle');
