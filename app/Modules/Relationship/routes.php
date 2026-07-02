<?php

use App\Modules\Relationship\Controllers\Admin\NexumRelationshipController;
use App\Modules\Relationship\Controllers\Tech\TicketRelationshipController;
use Illuminate\Support\Facades\Route;

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
