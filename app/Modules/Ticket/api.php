<?php

use App\Modules\Ticket\Controllers\Api\V1\TicketController;
use App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController;
use App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowDefinitionController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('tickets', [TicketController::class, 'index'])
    ->name('tickets.index')
    ->middleware(CheckAbilities::class.':tickets.read');

Route::post('tickets', [TicketController::class, 'store'])
    ->name('tickets.store')
    ->middleware(CheckAbilities::class.':tickets.create');

Route::get('tickets/{ticket}', [TicketController::class, 'show'])
    ->name('tickets.show')
    ->middleware(CheckAbilities::class.':tickets.read');

Route::match(['put', 'patch'], 'tickets/{ticket}', [TicketController::class, 'update'])
    ->name('tickets.update')
    ->middleware(CheckAbilities::class.':tickets.update');

Route::post('tickets/{ticket}/external-messages', [TicketController::class, 'storeExternalMessage'])
    ->name('tickets.external-messages.store')
    ->middleware(CheckAbilities::class.':tickets.update');

Route::post('tickets/{ticket}/timer/start', [TicketWorkflowActionController::class, 'startTimer'])
    ->name('tickets.timer.start')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/time-entries', [TicketWorkflowActionController::class, 'storeTimeEntry'])
    ->name('tickets.time-entries.store')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/cost-entries', [TicketWorkflowActionController::class, 'storeCostEntry'])
    ->name('tickets.cost-entries.store')
    ->middleware(CheckAbilities::class.':tickets.actions');

Route::get('tickets/{ticket}/workflow-decisions', [TicketWorkflowActionController::class, 'decisions'])
    ->name('tickets.workflow-decisions.show')
    ->middleware(CheckAbilities::class.':tickets.workflow.read');
Route::post('tickets/{ticket}/workflow-transitions/{transitionKey}', [TicketWorkflowActionController::class, 'transition'])
    ->name('tickets.workflow-transitions.store')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/workflow-escalations/{pathKey}', [TicketWorkflowActionController::class, 'escalate'])
    ->name('tickets.workflow-escalations.store')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/close', [TicketWorkflowActionController::class, 'close'])
    ->name('tickets.close')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/planned-lines', [TicketWorkflowActionController::class, 'storePlannedLine'])
    ->name('tickets.planned-lines.store')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::delete('tickets/{ticket}/planned-lines/{plannedLine}', [TicketWorkflowActionController::class, 'destroyPlannedLine'])
    ->name('tickets.planned-lines.destroy')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/planned-lines/{plannedLine}/convert', [TicketWorkflowActionController::class, 'convertPlannedLine'])
    ->name('tickets.planned-lines.convert')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/planned-lines/{plannedLine}/purchase', [TicketWorkflowActionController::class, 'requestPurchase'])
    ->name('tickets.planned-lines.purchase')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/sales-quote', [TicketWorkflowActionController::class, 'createQuote'])
    ->name('tickets.sales-quote.store')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/sales-quote/send', [TicketWorkflowActionController::class, 'sendQuote'])
    ->name('tickets.sales-quote.send')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/messages/{message}/quote-versions/{version}/accept', [TicketWorkflowActionController::class, 'acceptQuoteFromMessage'])
    ->name('tickets.sales-quote.accept-message')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/workflow-reviews', [TicketWorkflowActionController::class, 'requestReview'])
    ->name('tickets.workflow-reviews.store')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/workflow-reviews/{review}/decision', [TicketWorkflowActionController::class, 'decideReview'])
    ->name('tickets.workflow-reviews.decide')
    ->middleware(CheckAbilities::class.':tickets.actions');
Route::post('tickets/{ticket}/workflow-evidence', [TicketWorkflowActionController::class, 'classifyEvidence'])
    ->name('tickets.workflow-evidence.store')
    ->middleware(CheckAbilities::class.':tickets.actions');

Route::get('ticket-workflow-catalog', [TicketWorkflowDefinitionController::class, 'catalog'])
    ->name('ticket-workflows.catalog')
    ->middleware(CheckAbilities::class.':tickets.workflow.read');
Route::get('ticket-workflows', [TicketWorkflowDefinitionController::class, 'index'])
    ->name('ticket-workflows.index')
    ->middleware(CheckAbilities::class.':tickets.workflow.manage');
Route::post('ticket-workflows', [TicketWorkflowDefinitionController::class, 'store'])
    ->name('ticket-workflows.store')
    ->middleware(CheckAbilities::class.':tickets.workflow.manage');
Route::get('ticket-workflows/{workflow}', [TicketWorkflowDefinitionController::class, 'show'])
    ->name('ticket-workflows.show')
    ->middleware(CheckAbilities::class.':tickets.workflow.manage');
Route::put('ticket-workflows/{workflow}', [TicketWorkflowDefinitionController::class, 'update'])
    ->name('ticket-workflows.update')
    ->middleware(CheckAbilities::class.':tickets.workflow.manage');
Route::post('ticket-workflows/{workflow}/publish', [TicketWorkflowDefinitionController::class, 'publish'])
    ->name('ticket-workflows.publish')
    ->middleware(CheckAbilities::class.':tickets.workflow.publish');
Route::post('ticket-workflows/{workflow}/migration-preview', [TicketWorkflowDefinitionController::class, 'migrationPreview'])
    ->name('ticket-workflows.migration-preview')
    ->middleware(CheckAbilities::class.':tickets.workflow.manage');
Route::post('ticket-workflows/{workflow}/migrations', [TicketWorkflowDefinitionController::class, 'migrate'])
    ->name('ticket-workflows.migrations.store')
    ->middleware(CheckAbilities::class.':tickets.workflow.publish');
