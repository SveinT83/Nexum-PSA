<?php

use App\Modules\Signal\Controllers\Tech\SignalController;
use App\Modules\Signal\Controllers\Tech\SignalRuleController;
use App\Modules\Signal\Controllers\Tech\SignalSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/system/signals', [SignalController::class, 'index'])
    ->name('admin.system.signals.index');

Route::get('/admin/system/signals/rules', [SignalRuleController::class, 'index'])
    ->name('admin.system.signals.rules.index');
Route::get('/admin/system/signals/rules/create', [SignalRuleController::class, 'create'])
    ->name('admin.system.signals.rules.create');
Route::post('/admin/system/signals/rules', [SignalRuleController::class, 'store'])
    ->name('admin.system.signals.rules.store');
Route::get('/admin/system/signals/rules/{rule}', [SignalRuleController::class, 'show'])
    ->name('admin.system.signals.rules.show');
Route::put('/admin/system/signals/rules/{rule}', [SignalRuleController::class, 'update'])
    ->name('admin.system.signals.rules.update');

Route::get('/admin/system/signals/settings', [SignalSettingsController::class, 'edit'])
    ->name('admin.system.signals.settings.edit');
Route::put('/admin/system/signals/settings', [SignalSettingsController::class, 'update'])
    ->name('admin.system.signals.settings.update');

Route::get('/admin/system/signals/{signal}', [SignalController::class, 'show'])
    ->name('admin.system.signals.show');
Route::post('/admin/system/signals/{signal}/executions/{execution}/retry', [SignalController::class, 'retryExecution'])
    ->name('admin.system.signals.executions.retry');

Route::get('/signals', [SignalController::class, 'index'])
    ->name('signals.index');
Route::get('/signals/rules', [SignalRuleController::class, 'index'])
    ->name('signals.rules.index');
Route::get('/signals/rules/create', [SignalRuleController::class, 'create'])
    ->name('signals.rules.create');
Route::post('/signals/rules', [SignalRuleController::class, 'store'])
    ->name('signals.rules.store');
Route::get('/signals/rules/{rule}', [SignalRuleController::class, 'show'])
    ->name('signals.rules.show');
Route::put('/signals/rules/{rule}', [SignalRuleController::class, 'update'])
    ->name('signals.rules.update');
Route::get('/signals/{signal}', [SignalController::class, 'show'])
    ->name('signals.show');
Route::post('/signals/{signal}/executions/{execution}/retry', [SignalController::class, 'retryExecution'])
    ->name('signals.executions.retry');
