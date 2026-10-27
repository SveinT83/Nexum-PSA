<?php

use App\Modules\Relationship\Controllers\Api\RelationshipDocumentationWebhookController;
use App\Modules\Relationship\Controllers\Api\RelationshipKnowledgeWebhookController;
use App\Modules\Relationship\Controllers\Api\RelationshipTicketWebhookController;
// This file owns browser, official staff API and signed machine routes.
use App\Modules\Relationship\Controllers\Api\V1\RelationshipController;
use Illuminate\Support\Facades\Route;

if (($relationshipPublicApiRoutes ?? false) === true) {
    Route::prefix('v1/nexum/relationships')
        ->name('nexum.relationships.')
        ->group(function (): void {
            Route::post('tickets', [RelationshipTicketWebhookController::class, 'store'])
                ->name('tickets.store');
            Route::post('tickets/{remoteTicketId}/messages', [RelationshipTicketWebhookController::class, 'message'])
                ->name('tickets.messages.store');
            Route::post('tickets/{remoteTicketId}/status', [RelationshipTicketWebhookController::class, 'status'])
                ->name('tickets.status.store');
            Route::post('documentation', RelationshipDocumentationWebhookController::class)
                ->name('documentation.store');
            Route::post('knowledge/articles', RelationshipKnowledgeWebhookController::class)
                ->name('knowledge.articles.store');
        });

    return;
}

if (($relationshipApiRoutes ?? false) === true) {
    \Illuminate\Support\Facades\Route::middleware(\App\Modules\Relationship\Support\RelationshipApiAccess::class)->group(function () {
        \Illuminate\Support\Facades\Route::get('relationships', [RelationshipController::class, 'index'])
            ->name('relationships.1')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.read')
            ->defaults('relationship_permission', 'relationships.view')->defaults('relationship_ability', 'relationships.read')
            ->defaults('relationship_fields', ['q', 'status', 'direction', 'client_id', 'vendor_id', 'page', 'per_page']);
        \Illuminate\Support\Facades\Route::get('relationships/context', [RelationshipController::class, 'context'])
            ->name('relationships.2')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.manage')
            ->defaults('relationship_permission', 'relationships.manage')->defaults('relationship_ability', 'relationships.manage')
            ->defaults('relationship_fields', []);
        \Illuminate\Support\Facades\Route::get('relationships/lookups', [RelationshipController::class, 'lookups'])
            ->name('relationships.3')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.manage')
            ->defaults('relationship_permission', 'relationships.manage')->defaults('relationship_ability', 'relationships.manage')
            ->defaults('relationship_fields', ['type', 'q', 'page', 'per_page']);
        \Illuminate\Support\Facades\Route::post('relationships', [RelationshipController::class, 'store'])
            ->name('relationships.4')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.manage')
            ->defaults('relationship_permission', 'relationships.manage')->defaults('relationship_ability', 'relationships.manage')
            ->defaults('relationship_fields', ['name', 'direction', 'relationship_type', 'client_id', 'vendor_id', 'remote_base_url', 'remote_instance_id', 'remote_organization_name', 'remote_organization_identifier', 'status', 'capabilities', 'ticket_queue_id', 'ticket_auto_create_queue', 'documentation_two_way', 'attachment_max_mb', 'attachment_allowed_content_types', 'service_areas', 'status_mapping', 'outbound_token', 'inbound_token', 'webhook_secret']);
        \Illuminate\Support\Facades\Route::get('relationships/operations', [RelationshipController::class, 'operations'])
            ->name('relationships.5')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.operations.read')
            ->defaults('relationship_permission', 'relationships.view|relationships.manage|relationships.sync')->defaults('relationship_ability', 'relationships.operations.read')
            ->defaults('relationship_fields', ['page', 'per_page']);
        \Illuminate\Support\Facades\Route::get('relationships/operations/{operation}', [RelationshipController::class, 'operation'])
            ->name('relationships.6')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.operations.read')
            ->defaults('relationship_permission', 'relationships.view|relationships.manage|relationships.sync')->defaults('relationship_ability', 'relationships.operations.read')
            ->defaults('relationship_fields', []);
        \Illuminate\Support\Facades\Route::get('relationships/{relationship}', [RelationshipController::class, 'show'])
            ->name('relationships.7')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.read')
            ->defaults('relationship_permission', 'relationships.view')->defaults('relationship_ability', 'relationships.read')
            ->defaults('relationship_fields', []);
        \Illuminate\Support\Facades\Route::get('relationships/{relationship}/context', [RelationshipController::class, 'context'])
            ->name('relationships.8')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.manage')
            ->defaults('relationship_permission', 'relationships.manage')->defaults('relationship_ability', 'relationships.manage')
            ->defaults('relationship_fields', []);
        \Illuminate\Support\Facades\Route::patch('relationships/{relationship}', [RelationshipController::class, 'update'])
            ->name('relationships.9')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.manage')
            ->defaults('relationship_permission', 'relationships.manage')->defaults('relationship_ability', 'relationships.manage')
            ->defaults('relationship_fields', ['name', 'direction', 'relationship_type', 'client_id', 'vendor_id', 'remote_base_url', 'remote_instance_id', 'remote_organization_name', 'remote_organization_identifier', 'status', 'capabilities', 'ticket_queue_id', 'ticket_auto_create_queue', 'documentation_two_way', 'attachment_max_mb', 'attachment_allowed_content_types', 'service_areas', 'status_mapping', 'outbound_token', 'inbound_token', 'webhook_secret']);
        \Illuminate\Support\Facades\Route::post('relationships/{relationship}/rotate-secrets', [RelationshipController::class, 'rotate'])
            ->name('relationships.10')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.manage,relationships.credentials.write')
            ->defaults('relationship_permission', 'relationships.manage')->defaults('relationship_ability', 'relationships.manage')
            ->defaults('relationship_fields', ['rotate_inbound_token', 'rotate_webhook_secret']);
        \Illuminate\Support\Facades\Route::get('relationships/{relationship}/health', [RelationshipController::class, 'health'])
            ->name('relationships.11')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.read')
            ->defaults('relationship_permission', 'relationships.view')->defaults('relationship_ability', 'relationships.read')
            ->defaults('relationship_fields', []);
        \Illuminate\Support\Facades\Route::get('relationships/{relationship}/sync-links', [RelationshipController::class, 'links'])
            ->name('relationships.12')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.read')
            ->defaults('relationship_permission', 'relationships.view')->defaults('relationship_ability', 'relationships.read')
            ->defaults('relationship_fields', ['page', 'per_page']);
        \Illuminate\Support\Facades\Route::get('relationships/{relationship}/sync-links/{link}', [RelationshipController::class, 'link'])
            ->whereNumber('link')->name('relationships.13')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.read')
            ->defaults('relationship_permission', 'relationships.view')->defaults('relationship_ability', 'relationships.read')
            ->defaults('relationship_fields', []);
        \Illuminate\Support\Facades\Route::get('relationships/{relationship}/sync-events', [RelationshipController::class, 'events'])
            ->name('relationships.14')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.read')
            ->defaults('relationship_permission', 'relationships.view')->defaults('relationship_ability', 'relationships.read')
            ->defaults('relationship_fields', ['page', 'per_page']);
        \Illuminate\Support\Facades\Route::get('relationships/{relationship}/sync-events/{event}', [RelationshipController::class, 'event'])
            ->whereNumber('event')->name('relationships.15')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.read')
            ->defaults('relationship_permission', 'relationships.view')->defaults('relationship_ability', 'relationships.read')
            ->defaults('relationship_fields', []);
        \Illuminate\Support\Facades\Route::get('relationships/{relationship}/eligible-documentations', [RelationshipController::class, 'eligible'])
            ->name('relationships.16')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.sync')
            ->defaults('relationship_permission', 'relationships.sync')->defaults('relationship_ability', 'relationships.sync')
            ->defaults('relationship_fields', ['page', 'per_page'])->defaults('relationship_content', 'documentation');
        \Illuminate\Support\Facades\Route::get('relationships/{relationship}/eligible-knowledge', [RelationshipController::class, 'eligible'])
            ->name('relationships.17')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.sync')
            ->defaults('relationship_permission', 'relationships.sync')->defaults('relationship_ability', 'relationships.sync')
            ->defaults('relationship_fields', ['page', 'per_page'])->defaults('relationship_content', 'knowledge');
        \Illuminate\Support\Facades\Route::post('relationships/{relationship}/documentations/{source}/preview', [RelationshipController::class, 'preview'])
            ->whereNumber('source')->name('relationships.18')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.sync')
            ->defaults('relationship_permission', 'relationships.sync')->defaults('relationship_ability', 'relationships.sync')
            ->defaults('relationship_fields', [])->defaults('relationship_content', 'documentation');
        \Illuminate\Support\Facades\Route::post('relationships/{relationship}/documentations/{source}/push', [RelationshipController::class, 'push'])
            ->whereNumber('source')->name('relationships.19')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.sync,relationships.providers.execute')
            ->defaults('relationship_permission', 'relationships.sync')->defaults('relationship_ability', 'relationships.sync')
            ->defaults('relationship_fields', ['source_version'])->defaults('relationship_content', 'documentation');
        \Illuminate\Support\Facades\Route::post('relationships/{relationship}/knowledge/{source}/preview', [RelationshipController::class, 'preview'])
            ->whereNumber('source')->name('relationships.20')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.sync')
            ->defaults('relationship_permission', 'relationships.sync')->defaults('relationship_ability', 'relationships.sync')
            ->defaults('relationship_fields', [])->defaults('relationship_content', 'knowledge');
        \Illuminate\Support\Facades\Route::post('relationships/{relationship}/knowledge/{source}/push', [RelationshipController::class, 'push'])
            ->whereNumber('source')->name('relationships.21')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':relationships.sync,relationships.providers.execute')
            ->defaults('relationship_permission', 'relationships.sync')->defaults('relationship_ability', 'relationships.sync')
            ->defaults('relationship_fields', ['source_version'])->defaults('relationship_content', 'knowledge');
    });

    return;
}

use App\Modules\Relationship\Controllers\Admin\NexumRelationshipController;
use App\Modules\Relationship\Controllers\Tech\TicketRelationshipController;

Route::middleware('admin')->group(function (): void {
    Route::get('/admin/system/relationships', [NexumRelationshipController::class, 'index'])
        ->name('admin.system.relationships.index');
    Route::get('/admin/system/relationships/create', [NexumRelationshipController::class, 'create'])
        ->name('admin.system.relationships.create');
    Route::post('/admin/system/relationships', [NexumRelationshipController::class, 'store'])
        ->name('admin.system.relationships.store');
    Route::get('/admin/system/relationships/{relationship}', [NexumRelationshipController::class, 'show'])
        ->name('admin.system.relationships.show');
    Route::get('/admin/system/relationships/{relationship}/edit', [NexumRelationshipController::class, 'edit'])
        ->name('admin.system.relationships.edit');
    Route::patch('/admin/system/relationships/{relationship}', [NexumRelationshipController::class, 'update'])
        ->name('admin.system.relationships.update');
    Route::post('/admin/system/relationships/{relationship}/rotate-secrets', [NexumRelationshipController::class, 'rotateSecrets'])
        ->name('admin.system.relationships.rotate-secrets');
    Route::post('/admin/system/relationships/{relationship}/documentations/{documentation}/push', [NexumRelationshipController::class, 'pushDocumentation'])
        ->name('admin.system.relationships.documentations.push');
    Route::post('/admin/system/relationships/{relationship}/knowledge/{article}/push', [NexumRelationshipController::class, 'pushKnowledgeArticle'])
        ->name('admin.system.relationships.knowledge.push');
});

Route::post('/tickets/{ticket}/relationships/escalate', TicketRelationshipController::class)
    ->name('tickets.relationships.escalate');
