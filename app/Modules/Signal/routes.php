<?php

use App\Modules\Signal\Controllers\Tech\SignalController;
use App\Modules\Signal\Controllers\Tech\SignalRuleController;
use App\Modules\Signal\Controllers\Tech\SignalSettingsController;
use Illuminate\Support\Facades\Route;

// Domain-owned API registrations; api.php is only the compatibility loader.
if (($signalApiRoutes ?? false) === true) {
    Route::middleware([\App\Modules\Signal\Support\SignalApiAccess::class, \App\Modules\Signal\Actions\ExecuteSignalApiCommand::class])->group(function (): void {
        Route::get('signals/context', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'context'])->name('signals.context')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.read')->defaults('signal_permissions', ['signal.view'])->defaults('signal_ability', 'signals.read')->defaults('signal_fields', []);
        Route::get('signals/lookups/{kind}', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'lookups'])->name('signals.lookups')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.rules.write')->defaults('signal_permissions', ['signal.rule.manage'])->defaults('signal_ability', 'signals.rules.write')->defaults('signal_fields', ['q', 'page', 'per_page']);
        Route::get('signals/related/clients/{client}', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'relatedClient'])->name('signals.related.clients')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.read')->defaults('signal_permissions', ['signal.view', 'client.view'])->defaults('signal_ability', 'signals.read')->defaults('signal_fields', ['per_page']);
        Route::get('signals/related/contacts/{contact}', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'relatedContact'])->name('signals.related.contacts')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.read')->defaults('signal_permissions', ['signal.view', 'contact.view'])->defaults('signal_ability', 'signals.read')->defaults('signal_fields', ['per_page']);
        Route::get('signals/rules/context', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'ruleContext'])->name('signals.rules.context')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.rules.write')->defaults('signal_permissions', ['signal.rule.manage'])->defaults('signal_ability', 'signals.rules.write')->defaults('signal_fields', ['source_domain', 'intake_form_id']);
        Route::get('signals/rules', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'rules'])->name('signals.rules.index')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.rules.read')->defaults('signal_permissions', ['signal.view'])->defaults('signal_ability', 'signals.rules.read')->defaults('signal_fields', ['page', 'per_page']);
        Route::get('signals/rules/{rule}', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'rule'])->name('signals.rules.show')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.rules.read')->defaults('signal_permissions', ['signal.view'])->defaults('signal_ability', 'signals.rules.read')->defaults('signal_fields', ['page', 'per_page'])->defaults('signal_target', 'rule');
        Route::get('signals/{signal}/executions/{execution}', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'execution'])->name('signals.executions.show')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.read')->defaults('signal_permissions', ['signal.view'])->defaults('signal_ability', 'signals.read')->defaults('signal_fields', [])->defaults('signal_target', 'execution');
        Route::get('signals/{signal}/executions', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'executions'])->name('signals.executions.index')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.read')->defaults('signal_permissions', ['signal.view'])->defaults('signal_ability', 'signals.read')->defaults('signal_fields', ['page', 'per_page'])->defaults('signal_target', 'signal');
        Route::post('signals/rules', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'storeRule'])->name('signals.rules.store')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.rules.write')->defaults('signal_permissions', ['signal.rule.manage'])->defaults('signal_ability', 'signals.rules.write')->defaults('signal_fields', ['name', 'description', 'is_active', 'stop_processing', 'priority', 'use_advanced_json', 'conditions_json', 'actions_json', 'conditions', 'actions'])->defaults('signal_key_required', true);
        Route::put('signals/rules/{rule}', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'updateRule'])->name('signals.rules.update')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.rules.write')->defaults('signal_permissions', ['signal.rule.manage'])->defaults('signal_ability', 'signals.rules.write')->defaults('signal_fields', ['name', 'description', 'is_active', 'stop_processing', 'priority', 'use_advanced_json', 'conditions_json', 'actions_json', 'conditions', 'actions'])->defaults('signal_target', 'rule')->defaults('signal_key_required', true)->defaults('signal_match_required', true);
        Route::get('signals/settings', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'settings'])->name('signals.settings.show')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.settings.read')->defaults('signal_permissions', ['signal.rule.manage'])->defaults('signal_ability', 'signals.settings.read')->defaults('signal_fields', [])->defaults('signal_target', 'settings');
        Route::put('signals/settings', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'updateSettings'])->name('signals.settings.update')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.settings.write')->defaults('signal_permissions', ['signal.rule.manage'])->defaults('signal_ability', 'signals.settings.write')->defaults('signal_fields', ['ai_classification_enabled', 'ai_min_confidence', 'ai_source_domains', 'ai_allowed_signal_types', 'ai_stop_ticket_routing_types', 'ai_classification_prompt'])->defaults('signal_target', 'settings')->defaults('signal_key_required', true)->defaults('signal_match_required', true);
        Route::post('signals/{signal}/executions/{execution}/retry-preview', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'retryPreview'])->name('signals.executions.retry-preview')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.actions.execute')->defaults('signal_permissions', ['signal.action.execute'])->defaults('signal_ability', 'signals.actions.execute')->defaults('signal_fields', ['mode'])->defaults('signal_target', 'execution')->defaults('signal_preview', true);
        Route::post('signals/{signal}/executions/{execution}/retry', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'retry'])->name('signals.executions.retry')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.actions.execute')->defaults('signal_permissions', ['signal.action.execute'])->defaults('signal_ability', 'signals.actions.execute')->defaults('signal_fields', ['mode'])->defaults('signal_target', 'execution')->defaults('signal_key_required', true)->defaults('signal_match_required', true);
        Route::get('signals/{signal}', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'show'])->name('signals.show')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.read')->defaults('signal_permissions', ['signal.view'])->defaults('signal_ability', 'signals.read')->defaults('signal_fields', [])->defaults('signal_target', 'signal');
        Route::get('signals', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'index'])->name('signals.index')
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.read')->defaults('signal_permissions', ['signal.view'])->defaults('signal_ability', 'signals.read')->defaults('signal_fields', ['range', 'from', 'to', 'q', 'source_domain', 'signal_type', 'severity', 'status', 'sort', 'direction', 'page', 'per_page']);
    });
    Route::post('signals', [\App\Modules\Signal\Controllers\Api\V1\SignalController::class, 'store'])->name('signals.store')
        ->middleware([\App\Modules\Signal\Support\SignalApiAccess::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.create', \App\Modules\Signal\Actions\ExecuteSignalApiCommand::class])
        ->defaults('signal_permissions', ['signal.action.execute'])->defaults('signal_ability', 'signals.create')
        ->defaults('signal_fields', ['source_domain', 'source_type', 'source_id', 'subject_type', 'subject_id', 'contact_id', 'client_id', 'signal_type', 'severity', 'confidence', 'status', 'summary', 'payload', 'occurred_at', 'process_rules']);
    Route::get('signals/operations/{operation}', [\App\Modules\Signal\Controllers\Api\V1\SignalWorkspaceController::class, 'operation'])->name('signals.operations.show')
        ->middleware([\App\Modules\Signal\Support\SignalApiAccess::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':signals.operations.read', \App\Modules\Signal\Actions\ExecuteSignalApiCommand::class])
        ->defaults('signal_permissions', [])->defaults('signal_ability', 'signals.operations.read')->defaults('signal_fields', []);

    return;
}

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
