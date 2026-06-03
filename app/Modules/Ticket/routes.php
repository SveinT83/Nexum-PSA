<?php

use App\Modules\Ticket\Controllers\Admin\TicketAssignmentSettingsAdminController;
use App\Modules\Ticket\Controllers\Admin\AssignmentRuleAdminController;
use App\Modules\Ticket\Controllers\Admin\TicketSettingsController;
use App\Modules\Ticket\Controllers\Tech\TicketAssignmentSettingsController;
use App\Modules\Ticket\Controllers\Tech\TicketController;
use App\Modules\Ticket\Controllers\Tech\TicketSlaReportController;
use Illuminate\Support\Facades\Route;

Route::get('/reports/tickets/sla', [TicketSlaReportController::class, 'index'])
    ->name('reports.tickets.sla');

Route::get('/tickets', [TicketController::class, 'index'])
    ->name('tickets.index');

Route::get('/tickets/create', [TicketController::class, 'create'])
    ->name('tickets.create');

Route::get('/tickets/profile', [TicketAssignmentSettingsController::class, 'edit'])
    ->name('tickets.profile.edit');

Route::patch('/tickets/profile', [TicketAssignmentSettingsController::class, 'update'])
    ->name('tickets.profile.update');

Route::post('/tickets', [TicketController::class, 'store'])
    ->name('tickets.store');

Route::post('/tickets/merge', [TicketController::class, 'mergeSelected'])
    ->name('tickets.merge');

Route::post('/tickets/merge-suggestions/dismiss', [TicketController::class, 'dismissMergeSuggestion'])
    ->name('tickets.merge-suggestions.dismiss');

Route::get('/tickets/{ticket}/edit', [TicketController::class, 'edit'])
    ->name('tickets.edit');

Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
    ->withTrashed()
    ->name('tickets.show');

Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])
    ->name('tickets.update');

Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])
    ->name('tickets.close');

Route::post('/tickets/{ticket}/workflow/{transition}', [TicketController::class, 'transition'])
    ->name('tickets.workflow.transition');

Route::post('/tickets/{ticket}/documentation-request', [TicketController::class, 'requestDocumentation'])
    ->name('tickets.documentation-request');

Route::post('/tickets/{ticket}/messages', [TicketController::class, 'addMessage'])
    ->name('tickets.messages.store');

Route::post('/tickets/{ticket}/time-entries', [TicketController::class, 'storeTimeEntry'])
    ->name('tickets.time-entries.store');

Route::post('/tickets/{ticket}/time-entries/draft', [TicketController::class, 'draftTimeEntryInvoiceText'])
    ->name('tickets.time-entries.draft');

Route::patch('/tickets/{ticket}/time-entries/{timeEntry}', [TicketController::class, 'updateTimeEntry'])
    ->name('tickets.time-entries.update');

Route::post('/tickets/{ticket}/cost-entries', [TicketController::class, 'storeCostEntry'])
    ->name('tickets.cost-entries.store');

Route::patch('/tickets/{ticket}/cost-entries/{costEntry}', [TicketController::class, 'updateCostEntry'])
    ->name('tickets.cost-entries.update');

Route::post('/tickets/{ticket}/cost-entries/{costEntry}/pick', [TicketController::class, 'pickCostEntry'])
    ->name('tickets.cost-entries.pick');

Route::post('/tickets/{ticket}/messages/{message}/read', [TicketController::class, 'markMessageRead'])
    ->name('tickets.messages.read');

Route::post('/tickets/{ticket}/messages/{message}/solution', [TicketController::class, 'markMessageSolution'])
    ->name('tickets.messages.solution');

Route::get('/tickets/{ticket}/attachments/{attachment}/download', [TicketController::class, 'downloadAttachment'])
    ->name('tickets.attachments.download');

Route::post('/tickets/{ticket}/read', [TicketController::class, 'markRead'])
    ->name('tickets.read');

Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])
    ->name('tickets.assign');

Route::post('/tickets/{ticket}/not-ticket', [TicketController::class, 'markNotTicket'])
    ->name('tickets.not-ticket');

Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])
    ->name('tickets.destroy');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/tickets', [TicketSettingsController::class, 'index'])
        ->name('admin.settings.tickets');
    Route::post('/admin/settings/tickets/default-email-account', [TicketSettingsController::class, 'updateDefaultEmailAccount'])
        ->name('admin.settings.tickets.default-email-account.update');
    Route::post('/admin/settings/tickets/solution-policy', [TicketSettingsController::class, 'updateSolutionPolicy'])
        ->name('admin.settings.tickets.solution-policy.update');
    Route::post('/admin/settings/tickets/merge-settings', [TicketSettingsController::class, 'updateMergeSettings'])
        ->name('admin.settings.tickets.merge-settings.update');
    Route::post('/admin/settings/tickets/queues', [TicketSettingsController::class, 'storeQueue'])
        ->name('admin.settings.tickets.queues.store');
    Route::put('/admin/settings/tickets/queues/{queue}', [TicketSettingsController::class, 'updateQueue'])
        ->name('admin.settings.tickets.queues.update');
    Route::delete('/admin/settings/tickets/queues/{queue}', [TicketSettingsController::class, 'destroyQueue'])
        ->name('admin.settings.tickets.queues.destroy');
    Route::post('/admin/settings/tickets/types', [TicketSettingsController::class, 'storeType'])
        ->name('admin.settings.tickets.types.store');
    Route::put('/admin/settings/tickets/types/{type}', [TicketSettingsController::class, 'updateType'])
        ->name('admin.settings.tickets.types.update');
    Route::delete('/admin/settings/tickets/types/{type}', [TicketSettingsController::class, 'destroyType'])
        ->name('admin.settings.tickets.types.destroy');

    // Ticket lifecycle and urgency records are managed inside the Ticket module settings surface.
    Route::post('/admin/settings/tickets/statuses', [TicketSettingsController::class, 'storeStatus'])
        ->name('admin.settings.tickets.statuses.store');
    Route::put('/admin/settings/tickets/statuses/{status}', [TicketSettingsController::class, 'updateStatus'])
        ->name('admin.settings.tickets.statuses.update');
    Route::delete('/admin/settings/tickets/statuses/{status}', [TicketSettingsController::class, 'destroyStatus'])
        ->name('admin.settings.tickets.statuses.destroy');
    Route::post('/admin/settings/tickets/priorities', [TicketSettingsController::class, 'storePriority'])
        ->name('admin.settings.tickets.priorities.store');
    Route::put('/admin/settings/tickets/priorities/{priority}', [TicketSettingsController::class, 'updatePriority'])
        ->name('admin.settings.tickets.priorities.update');
    Route::delete('/admin/settings/tickets/priorities/{priority}', [TicketSettingsController::class, 'destroyPriority'])
        ->name('admin.settings.tickets.priorities.destroy');

    Route::get('/admin/settings/tickets/technicians', [TicketAssignmentSettingsAdminController::class, 'index'])
        ->name('admin.settings.tickets.technicians');
    Route::post('/admin/settings/tickets/technicians', [TicketAssignmentSettingsAdminController::class, 'store'])
        ->name('admin.settings.tickets.technicians.store');
    Route::get('/admin/settings/tickets/technicians/{profile}/edit', [TicketAssignmentSettingsAdminController::class, 'edit'])
        ->name('admin.settings.tickets.technicians.edit');
    Route::patch('/admin/settings/tickets/technicians/{profile}', [TicketAssignmentSettingsAdminController::class, 'update'])
        ->name('admin.settings.tickets.technicians.update');

    Route::get('/admin/settings/tickets/assignment-rules', [AssignmentRuleAdminController::class, 'index'])
        ->name('admin.settings.tickets.assignment-rules');
    Route::post('/admin/settings/tickets/assignment-rules', [AssignmentRuleAdminController::class, 'store'])
        ->name('admin.settings.tickets.assignment-rules.store');
    Route::delete('/admin/settings/tickets/assignment-rules/{rule}', [AssignmentRuleAdminController::class, 'destroy'])
        ->name('admin.settings.tickets.assignment-rules.destroy');

    Route::get('/admin/settings/tickets/rules', [TicketSettingsController::class, 'rules'])
        ->name('admin.settings.tickets.rules');
    Route::get('/admin/settings/tickets/rules/create', [TicketSettingsController::class, 'createRule'])
        ->name('admin.settings.tickets.rules.create');
    Route::post('/admin/settings/tickets/rules', [TicketSettingsController::class, 'storeRule'])
        ->name('admin.settings.tickets.rules.store');
    Route::get('/admin/settings/tickets/rules/{rule}/edit', [TicketSettingsController::class, 'editRule'])
        ->name('admin.settings.tickets.rules.edit');
    Route::put('/admin/settings/tickets/rules/{rule}', [TicketSettingsController::class, 'updateRule'])
        ->name('admin.settings.tickets.rules.update');
    Route::post('/admin/settings/tickets/rules/{rule}/toggle', [TicketSettingsController::class, 'toggleRule'])
        ->name('admin.settings.tickets.rules.toggle');
    Route::delete('/admin/settings/tickets/rules/{rule}', [TicketSettingsController::class, 'destroyRule'])
        ->name('admin.settings.tickets.rules.destroy');

    Route::get('/admin/settings/tickets/workflows', [TicketSettingsController::class, 'workflows'])
        ->name('admin.settings.tickets.workflows');
    Route::get('/admin/settings/tickets/workflows/create', [TicketSettingsController::class, 'createWorkflow'])
        ->name('admin.settings.tickets.workflows.create');
    Route::post('/admin/settings/tickets/workflows', [TicketSettingsController::class, 'storeWorkflow'])
        ->name('admin.settings.tickets.workflows.store');
    Route::get('/admin/settings/tickets/workflows/{workflow}/edit', [TicketSettingsController::class, 'editWorkflow'])
        ->name('admin.settings.tickets.workflows.edit');
    Route::put('/admin/settings/tickets/workflows/{workflow}', [TicketSettingsController::class, 'updateWorkflow'])
        ->name('admin.settings.tickets.workflows.update');
});
