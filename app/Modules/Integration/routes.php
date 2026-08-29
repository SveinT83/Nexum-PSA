<?php

use App\Modules\Integration\Controllers\Admin\AiIntegrationController;
use App\Modules\Integration\Controllers\Admin\AiPrivacyController;
use App\Modules\Integration\Controllers\Admin\AiTelemetryController;
use App\Modules\Integration\Controllers\Admin\ApiController;
use App\Modules\Integration\Controllers\Admin\CloudFactoryController;
use App\Modules\Integration\Controllers\Admin\EmailProviderController;
use App\Modules\Integration\Controllers\Admin\IntegrationsController;
use App\Modules\Integration\Controllers\Admin\RmmAlertRuleController;
use App\Modules\Integration\Controllers\Public\CloudFactoryWebhookController;
use App\Modules\Integration\Controllers\Tech\AiChatController;
use Illuminate\Support\Facades\Route;

if (($tdpsaLoadingCloudFactoryPublicRoutes ?? false) === true) {
    Route::post('v1/integrations/cloudfactory/webhook/{integration}', CloudFactoryWebhookController::class)
        ->middleware('throttle:60,1')
        ->name('api.v1.integrations.cloudfactory.webhook');

    return;
}

Route::middleware('admin')->group(function () {
    Route::get('/admin/system/integrations', [IntegrationsController::class, 'index'])
        ->name('admin.system.integrations.index');

    // RMM Alert Rules are the shared provider-neutral pre-routing layer.
    Route::get('/admin/system/integrations/rmm-alert-rules', [RmmAlertRuleController::class, 'index'])
        ->name('admin.system.integrations.rmm-alert-rules.index');
    Route::get('/admin/system/integrations/rmm-alert-rules/create', [RmmAlertRuleController::class, 'create'])
        ->name('admin.system.integrations.rmm-alert-rules.create');
    Route::post('/admin/system/integrations/rmm-alert-rules', [RmmAlertRuleController::class, 'store'])
        ->name('admin.system.integrations.rmm-alert-rules.store');
    Route::get('/admin/system/integrations/rmm-alert-rules/{rule}/edit', [RmmAlertRuleController::class, 'edit'])
        ->name('admin.system.integrations.rmm-alert-rules.edit');
    Route::put('/admin/system/integrations/rmm-alert-rules/{rule}', [RmmAlertRuleController::class, 'update'])
        ->name('admin.system.integrations.rmm-alert-rules.update');
    Route::delete('/admin/system/integrations/rmm-alert-rules/{rule}', [RmmAlertRuleController::class, 'destroy'])
        ->name('admin.system.integrations.rmm-alert-rules.destroy');

    // Email providers are independent Integration records. The generic
    // single-record toggle must never mutate this multi-record lifecycle.
    Route::get('/admin/system/integrations/email-providers', [EmailProviderController::class, 'index'])
        ->name('admin.system.integrations.email-providers.index');
    Route::get('/admin/system/integrations/email-providers/create', [EmailProviderController::class, 'create'])
        ->name('admin.system.integrations.email-providers.create');
    Route::post('/admin/system/integrations/email-providers', [EmailProviderController::class, 'store'])
        ->name('admin.system.integrations.email-providers.store');
    Route::get('/admin/system/integrations/email-providers/{connection}', [EmailProviderController::class, 'show'])
        ->whereUuid('connection')
        ->name('admin.system.integrations.email-providers.show');
    Route::post('/admin/system/integrations/email-providers/{connection}/credentials', [EmailProviderController::class, 'stageCredential'])
        ->whereUuid('connection')
        ->name('admin.system.integrations.email-providers.credentials.stage');
    Route::post('/admin/system/integrations/email-providers/{connection}/credentials/{version}/verify', [EmailProviderController::class, 'verifyCredential'])
        ->whereUuid('connection')->whereNumber('version')
        ->name('admin.system.integrations.email-providers.credentials.verify');
    Route::post('/admin/system/integrations/email-providers/{connection}/credentials/{version}/activate', [EmailProviderController::class, 'activateCredential'])
        ->whereUuid('connection')->whereNumber('version')
        ->name('admin.system.integrations.email-providers.credentials.activate');
    Route::post('/admin/system/integrations/email-providers/{connection}/credentials/{version}/revoke', [EmailProviderController::class, 'revokeCredential'])
        ->whereUuid('connection')->whereNumber('version')
        ->name('admin.system.integrations.email-providers.credentials.revoke');

    Route::post('/admin/system/integrations/email-providers/migrations/preview', [EmailProviderController::class, 'previewMigration'])
        ->name('admin.system.integrations.email-providers.migrations.preview');
    Route::get('/admin/system/integrations/email-providers/migrations/{run}', [EmailProviderController::class, 'showMigration'])
        ->whereUuid('run')
        ->name('admin.system.integrations.email-providers.migrations.show');
    Route::post('/admin/system/integrations/email-providers/migrations/{run}/stage', [EmailProviderController::class, 'stageMigration'])
        ->whereUuid('run')
        ->name('admin.system.integrations.email-providers.migrations.stage');
    Route::post('/admin/system/integrations/email-providers/migrations/{run}/items/{item}/verify', [EmailProviderController::class, 'verifyMigrationItem'])
        ->whereUuid('run')->whereNumber('item')
        ->name('admin.system.integrations.email-providers.migrations.items.verify');
    Route::post('/admin/system/integrations/email-providers/migrations/{run}/items/{item}/activate', [EmailProviderController::class, 'activateMigrationItem'])
        ->whereUuid('run')->whereNumber('item')
        ->name('admin.system.integrations.email-providers.migrations.items.activate');
    Route::post('/admin/system/integrations/email-providers/migrations/{run}/items/{item}/pause', [EmailProviderController::class, 'pauseAccount'])
        ->whereUuid('run')->whereNumber('item')
        ->name('admin.system.integrations.email-providers.migrations.items.pause');
    Route::post('/admin/system/integrations/email-providers/migrations/{run}/items/{item}/resume', [EmailProviderController::class, 'resumeAccount'])
        ->whereUuid('run')->whereNumber('item')
        ->name('admin.system.integrations.email-providers.migrations.items.resume');
    Route::post('/admin/system/integrations/email-providers/migrations/{run}/cutover-preview', [EmailProviderController::class, 'previewCutover'])
        ->whereUuid('run')
        ->name('admin.system.integrations.email-providers.migrations.cutover-preview');
    Route::post('/admin/system/integrations/email-providers/migrations/{run}/cutover', [EmailProviderController::class, 'applyCutover'])
        ->whereUuid('run')
        ->name('admin.system.integrations.email-providers.migrations.cutover');
    Route::post('/admin/system/integrations/email-providers/migrations/{run}/rollback', [EmailProviderController::class, 'rollbackCutover'])
        ->whereUuid('run')
        ->name('admin.system.integrations.email-providers.migrations.rollback');

    Route::get('/admin/system/integrations/cloudfactory', [CloudFactoryController::class, 'index'])
        ->name('admin.system.integrations.cloudfactory.index');
    Route::post('/admin/system/integrations/cloudfactory/connect', [CloudFactoryController::class, 'connect'])
        ->name('admin.system.integrations.cloudfactory.connect');
    Route::post('/admin/system/integrations/cloudfactory/capabilities/refresh', [CloudFactoryController::class, 'refreshCapabilities'])
        ->name('admin.system.integrations.cloudfactory.capabilities.refresh');
    Route::put('/admin/system/integrations/cloudfactory/settings', [CloudFactoryController::class, 'update'])
        ->name('admin.system.integrations.cloudfactory.update');
    Route::post('/admin/system/integrations/cloudfactory/revoke', [CloudFactoryController::class, 'revoke'])
        ->name('admin.system.integrations.cloudfactory.revoke');
    Route::post('/admin/system/integrations/cloudfactory/sync', [CloudFactoryController::class, 'sync'])
        ->name('admin.system.integrations.cloudfactory.sync');
    Route::get('/admin/system/integrations/cloudfactory/sync/{run}', [CloudFactoryController::class, 'syncStatus'])
        ->name('admin.system.integrations.cloudfactory.sync.status');
    Route::post('/admin/system/integrations/cloudfactory/webhooks/enable', [CloudFactoryController::class, 'enableWebhooks'])
        ->name('admin.system.integrations.cloudfactory.webhooks.enable');
    Route::post('/admin/system/integrations/cloudfactory/webhooks/disable', [CloudFactoryController::class, 'disableWebhooks'])
        ->name('admin.system.integrations.cloudfactory.webhooks.disable');
    Route::post('/admin/system/integrations/cloudfactory/validation', [CloudFactoryController::class, 'completeValidation'])
        ->name('admin.system.integrations.cloudfactory.validation');
    Route::get('/admin/system/integrations/cloudfactory/catalogue', [CloudFactoryController::class, 'catalogue'])
        ->name('admin.system.integrations.cloudfactory.catalogue');
    Route::patch(
        '/admin/system/integrations/cloudfactory/catalogue/vendors/{vendorLink}',
        [CloudFactoryController::class, 'updateVendorLink']
    )->name('admin.system.integrations.cloudfactory.catalogue.vendors.update');
    Route::patch(
        '/admin/system/integrations/cloudfactory/catalogue/{offer}',
        [CloudFactoryController::class, 'updateOffer']
    )->name('admin.system.integrations.cloudfactory.catalogue.update');
    Route::post(
        '/admin/system/integrations/cloudfactory/conflicts/{conflict}/link-client',
        [CloudFactoryController::class, 'linkClient']
    )->name('admin.system.integrations.cloudfactory.conflicts.link-client');

    Route::post('/admin/system/integrations/toggle', [IntegrationsController::class, 'toggle'])
        ->name('admin.system.integrations.toggle');

    Route::get('/admin/system/integrations/nable-rmm', [IntegrationsController::class, 'nableRmmSettings'])
        ->name('admin.system.integrations.nable_rmm.settings');

    Route::post('/admin/system/integrations/nable-rmm', [IntegrationsController::class, 'nableRmmUpdate'])
        ->name('admin.system.integrations.nable_rmm.update');

    Route::post('/admin/system/integrations/nable-rmm/settings', [IntegrationsController::class, 'nableRmmUpdateSettings'])
        ->name('admin.system.integrations.nable_rmm.update_settings');

    Route::post('/admin/system/integrations/nable-rmm/sync-from', [IntegrationsController::class, 'nableRmmSyncFrom'])
        ->name('admin.system.integrations.nable_rmm.sync_from');

    Route::post('/admin/system/integrations/nable-rmm/sync-to', [IntegrationsController::class, 'nableRmmSyncTo'])
        ->name('admin.system.integrations.nable_rmm.sync_to');

    Route::post('/admin/system/integrations/nable-rmm/sync-sites-from', [IntegrationsController::class, 'nableRmmSyncSitesFrom'])
        ->name('admin.system.integrations.nable_rmm.sync_sites_from');

    Route::post('/admin/system/integrations/nable-rmm/sync-sites-to', [IntegrationsController::class, 'nableRmmSyncSitesTo'])
        ->name('admin.system.integrations.nable_rmm.sync_sites_to');

    Route::get('/admin/system/integrations/tactical-rmm', [IntegrationsController::class, 'tacticalRmmSettings'])
        ->name('admin.system.integrations.tactical_rmm.settings');

    Route::post('/admin/system/integrations/tactical-rmm', [IntegrationsController::class, 'tacticalRmmUpdate'])
        ->name('admin.system.integrations.tactical_rmm.update');

    Route::post('/admin/system/integrations/tactical-rmm/settings', [IntegrationsController::class, 'tacticalRmmUpdateSettings'])
        ->name('admin.system.integrations.tactical_rmm.update_settings');

    Route::get('/admin/system/integrations/book-stack', [IntegrationsController::class, 'bookStackSettings'])
        ->name('admin.system.integrations.book_stack.settings');

    Route::post('/admin/system/integrations/book-stack', [IntegrationsController::class, 'bookStackUpdate'])
        ->name('admin.system.integrations.book_stack.update');

    Route::post('/admin/system/integrations/book-stack/test', [IntegrationsController::class, 'bookStackTestConnection'])
        ->name('admin.system.integrations.book_stack.test');

    Route::post('/admin/system/integrations/book-stack/sync', [IntegrationsController::class, 'bookStackSync'])
        ->name('admin.system.integrations.book_stack.sync');

    Route::post('/admin/system/integrations/book-stack/push', [IntegrationsController::class, 'bookStackPush'])
        ->name('admin.system.integrations.book_stack.push');

    Route::get('/admin/system/integrations/ai', [AiIntegrationController::class, 'index'])
        ->name('admin.system.integrations.ai.index');
    Route::get('/admin/system/integrations/ai/telemetry', [AiTelemetryController::class, 'index'])
        ->name('admin.system.integrations.ai.telemetry.index');
    Route::get('/admin/system/integrations/ai/telemetry/{event}', [AiTelemetryController::class, 'show'])
        ->name('admin.system.integrations.ai.telemetry.show');
    Route::get('/admin/system/integrations/ai/rate-cards', [AiTelemetryController::class, 'rateCards'])
        ->name('admin.system.integrations.ai.rate-cards.index');
    Route::post('/admin/system/integrations/ai/providers', [AiIntegrationController::class, 'storeProvider'])
        ->name('admin.system.integrations.ai.providers.store');

    Route::put('/admin/system/integrations/ai/providers/{provider}', [AiIntegrationController::class, 'updateProvider'])
        ->name('admin.system.integrations.ai.providers.update');

    Route::delete('/admin/system/integrations/ai/providers/{provider}', [AiIntegrationController::class, 'destroyProvider'])
        ->name('admin.system.integrations.ai.providers.destroy');

    Route::post('/admin/system/integrations/ai/agents', [AiIntegrationController::class, 'storeAgent'])
        ->name('admin.system.integrations.ai.agents.store');

    Route::put('/admin/system/integrations/ai/agents/{agent}', [AiIntegrationController::class, 'updateAgent'])
        ->name('admin.system.integrations.ai.agents.update');

    Route::delete('/admin/system/integrations/ai/agents/{agent}', [AiIntegrationController::class, 'destroyAgent'])
        ->name('admin.system.integrations.ai.agents.destroy');

    Route::get('/admin/system/integrations/ai/privacy', [AiPrivacyController::class, 'index'])
        ->name('admin.system.integrations.ai.privacy.index');
    Route::put('/admin/system/integrations/ai/privacy/policy', [AiPrivacyController::class, 'updatePolicy'])
        ->name('admin.system.integrations.ai.privacy.policy.update');
    Route::put('/admin/system/integrations/ai/privacy/providers/{provider}', [AiPrivacyController::class, 'updateProvider'])
        ->name('admin.system.integrations.ai.privacy.providers.update');
    Route::put('/admin/system/integrations/ai/privacy/providers/{provider}/models', [AiPrivacyController::class, 'updateModel'])
        ->name('admin.system.integrations.ai.privacy.models.update');
    Route::put('/admin/system/integrations/ai/privacy/agents/{agent}', [AiPrivacyController::class, 'updateAgent'])
        ->name('admin.system.integrations.ai.privacy.agents.update');
    Route::post('/admin/system/integrations/ai/privacy/workloads', [AiPrivacyController::class, 'storeWorkload'])
        ->name('admin.system.integrations.ai.privacy.workloads.store');
    Route::post('/admin/system/integrations/ai/privacy/workloads/internal', [AiPrivacyController::class, 'storeInternalWorkload'])
        ->name('admin.system.integrations.ai.privacy.workloads.internal.store');
    Route::post('/admin/system/integrations/ai/privacy/workloads/{workload}/tokens', [AiPrivacyController::class, 'storeToken'])
        ->name('admin.system.integrations.ai.privacy.workloads.tokens.store');
    Route::delete('/admin/system/integrations/ai/privacy/token-bindings/{binding}', [AiPrivacyController::class, 'revokeToken'])
        ->name('admin.system.integrations.ai.privacy.bindings.revoke');

    Route::get('/admin/system/integrations/api', [ApiController::class, 'index'])
        ->name('admin.system.integrations.api.index');

    Route::post('/admin/system/integrations/api/store', [ApiController::class, 'store'])
        ->name('admin.system.integrations.api.store');

    Route::delete('/admin/system/integrations/api/{apiKey}', [ApiController::class, 'destroy'])
        ->name('admin.system.integrations.api.destroy');

    Route::get('/admin/system/integrations/api/docs', [ApiController::class, 'documentation'])
        ->name('admin.system.integrations.api.docs');
});

Route::get('/knowledge/ai', [AiChatController::class, 'index'])
    ->name('ai.chats.index');

Route::post('/knowledge/ai/chats', [AiChatController::class, 'store'])
    ->name('ai.chats.store');

Route::post('/knowledge/ai/chats/{chat}/messages', [AiChatController::class, 'message'])
    ->name('ai.chats.messages.store');

Route::bind('workload', fn (string $value) => \App\Modules\Integration\Models\AiWorkloadProfile::query()->findOrFail($value));
Route::bind('binding', fn (string $value) => \App\Modules\Integration\Models\AiWorkloadTokenBinding::query()->findOrFail($value));
