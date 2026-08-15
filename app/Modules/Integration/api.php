<?php

use App\Modules\Integration\Controllers\Api\V1\BookStackSyncController;
use App\Modules\Integration\Controllers\Api\V1\IntegrationHub\AuditController as IntegrationHubAuditController;
use App\Modules\Integration\Controllers\Api\V1\IntegrationHub\CapabilityController as IntegrationHubCapabilityController;
use App\Modules\Integration\Controllers\Api\V1\IntegrationHub\ClientSiteController as IntegrationHubClientSiteController;
use App\Modules\Integration\Controllers\Api\V1\IntegrationHub\DomainController as IntegrationHubDomainController;
use App\Modules\Integration\Controllers\Api\V1\IntegrationHub\EmergencyControlController as IntegrationHubEmergencyControlController;
use App\Modules\Integration\Controllers\Api\V1\IntegrationHub\ExecutionController as IntegrationHubExecutionController;
use App\Modules\Integration\Controllers\Api\V1\IntegrationHub\GrantController as IntegrationHubGrantController;
use App\Modules\Integration\Controllers\Api\V1\IntegrationHub\HostingController as IntegrationHubHostingController;
use App\Modules\Integration\Controllers\Api\V1\IntegrationHub\IdentityController as IntegrationHubIdentityController;
use App\Modules\Integration\Controllers\Api\V1\IntegrationHub\IntegrationController as IntegrationHubIntegrationController;
use App\Modules\Integration\Http\Middleware\RequireIntegrationHubGrant;
use App\Modules\Integration\Http\Middleware\RequireIntegrationHubOperator;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('integrations/book-stack/status', [BookStackSyncController::class, 'status'])
    ->name('integrations.book-stack.status')
    ->middleware(CheckAbilities::class.':integration.bookstack.read');

Route::post('integrations/book-stack/test', [BookStackSyncController::class, 'test'])
    ->name('integrations.book-stack.test')
    ->middleware(CheckAbilities::class.':integration.bookstack.run');

Route::post('integrations/book-stack/pull', [BookStackSyncController::class, 'pull'])
    ->name('integrations.book-stack.pull')
    ->middleware(CheckAbilities::class.':integration.bookstack.run');

Route::post('integrations/book-stack/push', [BookStackSyncController::class, 'push'])
    ->name('integrations.book-stack.push')
    ->middleware(CheckAbilities::class.':integration.bookstack.run');

Route::prefix('integration-hub')->name('integration-hub.')->group(function (): void {
    Route::post('grants', [IntegrationHubGrantController::class, 'store'])
        ->name('grants.store')
        ->middleware('throttle:integration-hub-grants');

    Route::get('controls', [IntegrationHubEmergencyControlController::class, 'index'])
        ->name('controls.index')
        ->middleware(RequireIntegrationHubOperator::class.':integration-hub.controls.manage,integration.ai_governance_manage');
    Route::get('readiness', [IntegrationHubEmergencyControlController::class, 'readiness'])
        ->name('readiness.show')
        ->middleware(RequireIntegrationHubOperator::class.':integration-hub.controls.manage,integration.ai_governance_manage');
    Route::post('controls', [IntegrationHubEmergencyControlController::class, 'store'])
        ->name('controls.store')
        ->middleware(RequireIntegrationHubOperator::class.':integration-hub.controls.manage,integration.ai_governance_manage');

    Route::get('capabilities', [IntegrationHubCapabilityController::class, 'index'])
        ->name('capabilities.index')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.capabilities.read,1.0', 'throttle:integration-hub-service']);
    Route::get('capabilities/{key}/{version}', [IntegrationHubCapabilityController::class, 'show'])
        ->where(['key' => '[A-Za-z0-9._-]+', 'version' => '\\d+(?:\\.\\d+)?'])
        ->name('capabilities.show')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.capabilities.read,1.0', 'throttle:integration-hub-service']);

    Route::get('identity', IntegrationHubIdentityController::class)
        ->name('identity.show')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.identity.read,1.0', 'throttle:integration-hub-service']);

    Route::get('clients', [IntegrationHubClientSiteController::class, 'clients'])
        ->name('clients.index')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.clients.read,1.0', 'throttle:integration-hub-service']);
    Route::get('clients/{client}', [IntegrationHubClientSiteController::class, 'client'])
        ->whereNumber('client')->name('clients.show')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.clients.read,1.0', 'throttle:integration-hub-service']);
    Route::get('sites', [IntegrationHubClientSiteController::class, 'sites'])
        ->name('sites.index')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.sites.read,1.0', 'throttle:integration-hub-service']);
    Route::get('sites/{site}', [IntegrationHubClientSiteController::class, 'site'])
        ->whereNumber('site')->name('sites.show')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.sites.read,1.0', 'throttle:integration-hub-service']);

    Route::get('domains', [IntegrationHubDomainController::class, 'index'])
        ->name('domains.index')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.domains.read,1.0', 'throttle:integration-hub-service']);
    Route::get('domains/{domain}', [IntegrationHubDomainController::class, 'show'])
        ->whereUuid('domain')->name('domains.show')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.domains.read,1.0', 'throttle:integration-hub-service']);

    Route::get('integrations', [IntegrationHubIntegrationController::class, 'index'])
        ->name('integrations.index')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.integrations.read,1.0', 'throttle:integration-hub-service']);
    Route::get('integrations/{integration}', [IntegrationHubIntegrationController::class, 'show'])
        ->whereUuid('integration')->name('integrations.show')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.integrations.read,1.0', 'throttle:integration-hub-service']);
    Route::get('integrations/{integration}/health', [IntegrationHubIntegrationController::class, 'health'])
        ->whereUuid('integration')->name('integrations.health')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.integrations.read,1.0', 'throttle:integration-hub-service']);

    Route::get('executions', [IntegrationHubExecutionController::class, 'index'])
        ->name('executions.index')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.executions.read,1.0', 'throttle:integration-hub-service']);
    Route::get('executions/{execution}', [IntegrationHubExecutionController::class, 'show'])
        ->whereUuid('execution')->name('executions.show')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.executions.read,1.0', 'throttle:integration-hub-service']);

    Route::get('audit-events', [IntegrationHubAuditController::class, 'index'])
        ->name('audit-events.index')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.audit.read,1.0', 'throttle:integration-hub-service']);

    Route::get('hosting/sites/{site}/inspect', [IntegrationHubHostingController::class, 'inspect'])
        ->whereNumber('site')->name('hosting.sites.inspect')
        ->middleware([RequireIntegrationHubGrant::class.':nexum.hosting.sites.inspect,1.0', 'throttle:integration-hub-service']);
});
