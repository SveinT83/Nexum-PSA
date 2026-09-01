<?php

use App\Modules\Email\Controllers\Admin\AccountsController;
use App\Modules\Email\Controllers\Admin\CanonicalCorrelationController;
use App\Modules\Email\Controllers\Admin\CanonicalCutoverController;
use App\Modules\Email\Controllers\Admin\ConfigController;
use App\Modules\Email\Controllers\Admin\EmergencyMailboxAccessController;
use App\Modules\Email\Controllers\Admin\MailboxMaintenanceController;
use App\Modules\Email\Controllers\Admin\RulesController;
use App\Modules\Email\Controllers\Admin\Templates\EmailTemplateController;
use App\Modules\Email\Controllers\Admin\TicketCorrelationConflictController;
use App\Modules\Email\Controllers\Tech\EmailBroadcastingController;
use App\Modules\Email\Controllers\Tech\InboxController;
use App\Modules\Email\Controllers\Tech\MailAttachmentController;
use App\Modules\Email\Controllers\Tech\MailboxAccessController;
use App\Modules\Email\Controllers\Tech\MailboxAccessHistoryController;
use App\Modules\Email\Controllers\Tech\MailController;
use App\Modules\Email\Controllers\Tech\MailRawSourceController;
use App\Modules\Email\Controllers\Tech\SignatureController;
use App\Modules\Email\Controllers\Tech\UnreadHandoverController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email Module Routes
|--------------------------------------------------------------------------
|
| These routes are loaded inside the authenticated /tech route group. Public
| route names intentionally remain stable so existing navigation and Blade
| links continue to work while Email owns Inbox and email administration.
|
*/

Route::get('/mail', [MailController::class, 'index'])
    ->name('mail.index');
Route::get('/mail/placements/{placement}/attachments/{attachment}/download', [MailAttachmentController::class, 'download'])
    ->name('mail.attachments.download');
Route::get('/mail/placements/{placement}/raw-source', [MailRawSourceController::class, 'show'])
    ->name('mail.raw-source.show');
Route::patch('/mail/signature', [SignatureController::class, 'update'])
    ->name('mail.signature.update');
Route::get('/mail/access', [MailboxAccessController::class, 'index'])
    ->name('mail.access.index');
Route::post('/mail/access/accounts/{accountId}/delegations', [MailboxAccessController::class, 'store'])
    ->name('mail.access.store');
Route::post('/mail/access/accounts/{accountId}/delegations/{delegationId}/revoke', [MailboxAccessController::class, 'revokeDelegation'])
    ->name('mail.access.delegations.revoke');
Route::post('/mail/access/emergency/{accessId}/revoke', [MailboxAccessController::class, 'revokeBreakGlass'])
    ->name('mail.access.emergency.revoke');
Route::get('/mail/access-history', MailboxAccessHistoryController::class)
    ->name('mail.access.history');
Route::get('/mail/accounts/{account}/unread-handover', [UnreadHandoverController::class, 'index'])
    ->name('mail.unread-handover.index');
Route::post('/mail/accounts/{account}/unread-handover/preview', [UnreadHandoverController::class, 'preview'])
    ->name('mail.unread-handover.preview');
Route::post('/mail/accounts/{account}/unread-handover/{run}', [UnreadHandoverController::class, 'apply'])
    ->name('mail.unread-handover.apply');

Route::post('/mail/broadcasting/auth', EmailBroadcastingController::class)
    ->middleware('throttle:email-mail-broadcast-auth')
    ->name('mail.broadcast.auth');

Route::get('/inbox', [InboxController::class, 'index'])
    ->name('inbox.index');
Route::post('/inbox/poll', [InboxController::class, 'poll'])
    ->name('inbox.poll');
Route::get('/inbox/show/{message}', [InboxController::class, 'show'])
    ->name('inbox.show');
Route::post('/inbox/{message}/spam', [InboxController::class, 'markSpam'])
    ->name('inbox.spam');
Route::delete('/inbox/{message}', [InboxController::class, 'destroy'])
    ->name('inbox.delete');
Route::get('/inbox/attachments/{attachment}/download', [InboxController::class, 'download'])
    ->name('inbox.download');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/email/canonical-cutover', [CanonicalCutoverController::class, 'index'])
        ->name('admin.settings.email.canonical-cutover.index');
    Route::post('/admin/settings/email/canonical-cutover/backfill', [CanonicalCutoverController::class, 'backfill'])
        ->name('admin.settings.email.canonical-cutover.backfill');
    Route::post('/admin/settings/email/canonical-cutover/merge', [CanonicalCutoverController::class, 'merge'])
        ->name('admin.settings.email.canonical-cutover.merge');
    Route::post('/admin/settings/email/canonical-cutover/audit', [CanonicalCutoverController::class, 'audit'])
        ->name('admin.settings.email.canonical-cutover.audit');
    Route::post('/admin/settings/email/canonical-cutover/mode', [CanonicalCutoverController::class, 'mode'])
        ->name('admin.settings.email.canonical-cutover.mode');
    Route::get('/admin/settings/email/canonical-cutover/{run}', [CanonicalCutoverController::class, 'show'])
        ->name('admin.settings.email.canonical-cutover.show');
    Route::post('/admin/settings/email/canonical-cutover/{run}/apply', [CanonicalCutoverController::class, 'apply'])
        ->name('admin.settings.email.canonical-cutover.apply');
    Route::post('/admin/settings/email/canonical-cutover/{run}/rollback', [CanonicalCutoverController::class, 'rollback'])
        ->name('admin.settings.email.canonical-cutover.rollback');

    Route::get('/admin/settings/email/correlation', [CanonicalCorrelationController::class, 'index'])
        ->name('admin.settings.email.correlation.index');
    Route::post('/admin/settings/email/correlation', [CanonicalCorrelationController::class, 'store'])
        ->name('admin.settings.email.correlation.store');
    Route::get('/admin/settings/email/correlation/{run}', [CanonicalCorrelationController::class, 'show'])
        ->name('admin.settings.email.correlation.show');
    Route::post('/admin/settings/email/correlation/{run}/resume', [CanonicalCorrelationController::class, 'resume'])
        ->name('admin.settings.email.correlation.resume');
    Route::post('/admin/settings/email/correlation/{run}/cancel', [CanonicalCorrelationController::class, 'cancel'])
        ->name('admin.settings.email.correlation.cancel');
    Route::get('/admin/settings/email/correlation/candidates/{candidate}/inspect', [CanonicalCorrelationController::class, 'inspect'])
        ->name('admin.settings.email.correlation.candidates.inspect');
    Route::post('/admin/settings/email/correlation/candidates/{candidate}/review', [CanonicalCorrelationController::class, 'review'])
        ->name('admin.settings.email.correlation.candidates.review');

    Route::get('/admin/settings/email/emergency-access', [EmergencyMailboxAccessController::class, 'index'])
        ->name('admin.settings.email.emergency-access.index');
    Route::post('/admin/settings/email/emergency-access', [EmergencyMailboxAccessController::class, 'store'])
        ->name('admin.settings.email.emergency-access.store');
    Route::post('/admin/settings/email/emergency-access/{accessId}/revoke', [EmergencyMailboxAccessController::class, 'revoke'])
        ->name('admin.settings.email.emergency-access.revoke');

    Route::get('/admin/settings/email/accounts', [AccountsController::class, 'index'])
        ->name('admin.settings.email.accounts');
    Route::get('/admin/settings/email/ticket-correlation-conflicts', [TicketCorrelationConflictController::class, 'index'])
        ->name('admin.settings.email.ticket-correlation-conflicts.index');
    Route::post('/admin/settings/email/ticket-correlation-conflicts/{conflict}/resolve', [TicketCorrelationConflictController::class, 'resolve'])
        ->name('admin.settings.email.ticket-correlation-conflicts.resolve');
    Route::get('/admin/settings/email/accounts/create', [AccountsController::class, 'create'])
        ->name('admin.settings.email.accounts.create');
    Route::post('/admin/settings/email/accounts', [AccountsController::class, 'store'])
        ->name('admin.settings.email.accounts.store');
    Route::get('/admin/settings/email/accounts/{account}/edit', [AccountsController::class, 'edit'])
        ->name('admin.settings.email.accounts.edit');
    Route::put('/admin/settings/email/accounts/{account}', [AccountsController::class, 'update'])
        ->name('admin.settings.email.accounts.update');
    Route::post('/admin/settings/email/accounts/{account}/toggle', [AccountsController::class, 'toggleActive'])
        ->name('admin.settings.email.accounts.toggle');
    Route::post('/admin/settings/email/accounts/{account}/test', [AccountsController::class, 'test'])
        ->name('admin.settings.email.accounts.test');
    Route::get('/admin/settings/email/accounts/{account}/mailbox-maintenance', [MailboxMaintenanceController::class, 'index'])
        ->name('admin.settings.email.accounts.mailbox-maintenance');
    Route::post('/admin/settings/email/accounts/{account}/historical-import/preview', [MailboxMaintenanceController::class, 'previewHistoricalImport'])
        ->name('admin.settings.email.accounts.historical-import.preview');
    Route::post('/admin/settings/email/accounts/{account}/historical-import', [MailboxMaintenanceController::class, 'startHistoricalImport'])
        ->name('admin.settings.email.accounts.historical-import.start');
    Route::post('/admin/settings/email/accounts/{account}/historical-import/{run}/cancel', [MailboxMaintenanceController::class, 'cancelHistoricalImport'])
        ->name('admin.settings.email.accounts.historical-import.cancel');
    Route::post('/admin/settings/email/accounts/{account}/provider-reconciliation', [MailboxMaintenanceController::class, 'startProviderReconciliation'])
        ->name('admin.settings.email.accounts.provider-reconciliation.start');
    Route::post('/admin/settings/email/accounts/{account}/provider-reconciliation/{run}/cancel', [MailboxMaintenanceController::class, 'cancelProviderReconciliation'])
        ->name('admin.settings.email.accounts.provider-reconciliation.cancel');
    Route::post('/admin/settings/email/accounts/{account}/folders/{folder}/cursor-rebaseline/preview', [MailboxMaintenanceController::class, 'previewCursorRebaseline'])
        ->name('admin.settings.email.accounts.cursor-rebaseline.preview');
    Route::post('/admin/settings/email/accounts/{account}/folders/{folder}/cursor-rebaseline', [MailboxMaintenanceController::class, 'applyCursorRebaseline'])
        ->name('admin.settings.email.accounts.cursor-rebaseline.apply');

    Route::get('/admin/settings/email/config', [ConfigController::class, 'index'])
        ->name('admin.settings.email.config');
    Route::post('/admin/settings/email/config', [ConfigController::class, 'update'])
        ->name('admin.settings.email.config.update');

    Route::post('/admin/settings/email/rules/undo/{attempt}', [RulesController::class, 'undoExecution'])
        ->name('admin.settings.email.rules.undo');

    Route::get('/admin/settings/email/rules', [RulesController::class, 'index'])
        ->name('admin.settings.email.rules');
    Route::get('/admin/settings/email/rules/create', [RulesController::class, 'create'])
        ->name('admin.settings.email.rules.create');
    Route::post('/admin/settings/email/rules', [RulesController::class, 'store'])
        ->name('admin.settings.email.rules.store');
    Route::post('/admin/settings/email/rules/reprocess', [RulesController::class, 'reprocess'])
        ->name('admin.settings.email.rules.reprocess');
    Route::get('/admin/settings/email/rules/{rule}/edit', [RulesController::class, 'edit'])
        ->name('admin.settings.email.rules.edit');
    Route::put('/admin/settings/email/rules/{rule}', [RulesController::class, 'update'])
        ->name('admin.settings.email.rules.update');
    Route::post('/admin/settings/email/rules/{rule}/toggle', [RulesController::class, 'toggle'])
        ->name('admin.settings.email.rules.toggle');
    Route::delete('/admin/settings/email/rules/{rule}', [RulesController::class, 'destroy'])
        ->name('admin.settings.email.rules.destroy');

    Route::get('/admin/system/templatesManagement/email', [EmailTemplateController::class, 'index'])
        ->name('admin.system.templatesManagement.email.index');
    Route::get('/admin/system/templatesManagement/email/create', [EmailTemplateController::class, 'create'])
        ->name('admin.system.templatesManagement.email.create');
    Route::post('/admin/system/templatesManagement/email', [EmailTemplateController::class, 'store'])
        ->name('admin.system.templatesManagement.email.store');
    Route::post('/admin/system/templatesManagement/email/preview', [EmailTemplateController::class, 'preview'])
        ->name('admin.system.templatesManagement.email.preview');
    Route::get('/admin/system/templatesManagement/email/{template}/edit', [EmailTemplateController::class, 'edit'])
        ->name('admin.system.templatesManagement.email.edit');
    Route::put('/admin/system/templatesManagement/email/{template}', [EmailTemplateController::class, 'update'])
        ->name('admin.system.templatesManagement.email.update');
});
