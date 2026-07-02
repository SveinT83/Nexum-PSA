<?php

use App\Modules\Relationship\Controllers\Api\RelationshipDocumentationWebhookController;
use App\Modules\Relationship\Controllers\Api\RelationshipKnowledgeWebhookController;
use App\Modules\Relationship\Controllers\Api\RelationshipTicketWebhookController;
use Illuminate\Support\Facades\Route;

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
