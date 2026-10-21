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

// Mail API parity registrations share business actions with the browser.
if (($tdpsaLoadingEmailApiRoutes ?? false) === true) {

    Route::get('email/inbox/messages', [\App\Modules\Email\Controllers\Api\V1\InboxController::class, 'messages'])
        ->name('email.inbox.messages.index')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');

    Route::get('email/inbox/messages/{message}', [\App\Modules\Email\Controllers\Api\V1\InboxController::class, 'show'])
        ->name('email.inbox.messages.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');

    Route::post('email/inbox/messages/{message}/spam', [\App\Modules\Email\Controllers\Api\V1\InboxController::class, 'markSpam'])
        ->name('email.inbox.messages.spam')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::post('email/inbox/poll', [\App\Modules\Email\Controllers\Api\V1\InboxController::class, 'poll'])
        ->name('email.inbox.poll')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::post('email/mailbox/placements/{placement}/operations', [\App\Modules\Email\Controllers\Api\V1\MailboxPlacementOperationsController::class, 'store'])
        ->name('email.mailbox.placements.operations.store')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::get('email/mailbox/drafts', [\App\Modules\Email\Controllers\Api\V1\MailboxDraftsController::class, 'index'])
        ->name('email.mailbox.drafts.index')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.read');

    Route::post('email/mailbox/drafts', [\App\Modules\Email\Controllers\Api\V1\MailboxDraftsController::class, 'store'])
        ->name('email.mailbox.drafts.store')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::get('email/mailbox/drafts/{draft}', [\App\Modules\Email\Controllers\Api\V1\MailboxDraftsController::class, 'show'])
        ->name('email.mailbox.drafts.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.read');

    Route::patch('email/mailbox/drafts/{draft}', [\App\Modules\Email\Controllers\Api\V1\MailboxDraftsController::class, 'update'])
        ->name('email.mailbox.drafts.update')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::delete('email/mailbox/drafts/{draft}', [\App\Modules\Email\Controllers\Api\V1\MailboxDraftsController::class, 'discard'])
        ->name('email.mailbox.drafts.discard')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::post('email/mailbox/drafts/{draft}/attachments', [\App\Modules\Email\Controllers\Api\V1\MailboxDraftAttachmentsController::class, 'store'])
        ->name('email.mailbox.drafts.attachments.store')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::delete('email/mailbox/drafts/{draft}/attachments/{attachment}', [\App\Modules\Email\Controllers\Api\V1\MailboxDraftAttachmentsController::class, 'destroy'])
        ->name('email.mailbox.drafts.attachments.destroy')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::post('email/mailbox/drafts/{draft}/provider-sync', [\App\Modules\Email\Controllers\Api\V1\MailboxDraftsController::class, 'syncProvider'])
        ->name('email.mailbox.drafts.provider-sync')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::post('email/mailbox/drafts/{draft}/preview', [\App\Modules\Email\Controllers\Api\V1\MailboxDraftsController::class, 'preview'])
        ->name('email.mailbox.drafts.preview')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.send');

    Route::post('email/mailbox/drafts/{draft}/send', [\App\Modules\Email\Controllers\Api\V1\MailboxDraftsController::class, 'send'])
        ->name('email.mailbox.drafts.send')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.send');

    Route::post('email/mailbox/drafts/{draft}/share', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'share'])
        ->name('email.mailbox.drafts.share')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::get('email/mailbox/shared-drafts/{draft}', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'show'])
        ->name('email.mailbox.shared-drafts.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.read');

    Route::post('email/mailbox/shared-drafts/{draft}/lease', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'acquire'])
        ->name('email.mailbox.shared-drafts.lease.acquire')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::patch('email/mailbox/shared-drafts/{draft}/lease', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'renew'])
        ->name('email.mailbox.shared-drafts.lease.renew')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::delete('email/mailbox/shared-drafts/{draft}/lease', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'release'])
        ->name('email.mailbox.shared-drafts.lease.release')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::patch('email/mailbox/shared-drafts/{draft}', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'update'])
        ->name('email.mailbox.shared-drafts.update')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::delete('email/mailbox/shared-drafts/{draft}', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'discard'])
        ->name('email.mailbox.shared-drafts.discard')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::post('email/mailbox/shared-drafts/{draft}/attachments', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'storeAttachments'])
        ->name('email.mailbox.shared-drafts.attachments.store')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::delete('email/mailbox/shared-drafts/{draft}/attachments/{attachment}', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'removeAttachment'])
        ->name('email.mailbox.shared-drafts.attachments.destroy')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::post('email/mailbox/shared-drafts/{draft}/rebase-preview', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'rebasePreview'])
        ->name('email.mailbox.shared-drafts.rebase.preview')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::post('email/mailbox/shared-drafts/{draft}/rebase', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'rebase'])
        ->name('email.mailbox.shared-drafts.rebase')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::post('email/mailbox/shared-drafts/{draft}/preview', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'preview'])
        ->name('email.mailbox.shared-drafts.preview')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.send');

    Route::post('email/mailbox/shared-drafts/{draft}/send', [\App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController::class, 'send'])
        ->name('email.mailbox.shared-drafts.send')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.send');

    Route::get('email/mailbox/conversations/{conversation}/presence', [\App\Modules\Email\Controllers\Api\V1\MailboxPresenceController::class, 'show'])
        ->name('email.mailbox.conversations.presence.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.read');

    Route::post('email/mailbox/conversations/{conversation}/presence', [\App\Modules\Email\Controllers\Api\V1\MailboxPresenceController::class, 'heartbeat'])
        ->name('email.mailbox.conversations.presence.heartbeat')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::delete('email/mailbox/conversations/{conversation}/presence', [\App\Modules\Email\Controllers\Api\V1\MailboxPresenceController::class, 'leave'])
        ->name('email.mailbox.conversations.presence.leave')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write');

    Route::get('email/mailbox/submissions/{submission}', [\App\Modules\Email\Controllers\Api\V1\OutboundSubmissionsController::class, 'show'])
        ->name('email.mailbox.submissions.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.send');

    Route::get('email/mailbox/submissions/{submission}/sent-reconciliation', [\App\Modules\Email\Controllers\Api\V1\OutboundSubmissionsController::class, 'sentReconciliation'])
        ->name('email.mailbox.submissions.sent-reconciliation.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.send');

    Route::get('email/mailbox/remote-operations', [\App\Modules\Email\Controllers\Api\V1\RemoteOperationsController::class, 'index'])
        ->name('email.mailbox.remote-operations.index')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');

    Route::get('email/mailbox/remote-operations/{operation}', [\App\Modules\Email\Controllers\Api\V1\RemoteOperationsController::class, 'show'])
        ->name('email.mailbox.remote-operations.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');

    Route::post('email/mailbox/remote-operations/{operation}/retry', [\App\Modules\Email\Controllers\Api\V1\RemoteOperationsController::class, 'retry'])
        ->name('email.mailbox.remote-operations.retry')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::post('email/mailbox/remote-operations/{operation}/cancel', [\App\Modules\Email\Controllers\Api\V1\RemoteOperationsController::class, 'cancel'])
        ->name('email.mailbox.remote-operations.cancel')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::get('email/mailbox/remote-operations/{operation}/undo', [\App\Modules\Email\Controllers\Api\V1\RemoteOperationsController::class, 'undoEligibility'])
        ->name('email.mailbox.remote-operations.undo.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');

    Route::post('email/mailbox/remote-operations/{operation}/undo', [\App\Modules\Email\Controllers\Api\V1\RemoteOperationsController::class, 'undo'])
        ->name('email.mailbox.remote-operations.undo.store')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::get('email/mailbox/conversations/{conversation}/classification', [\App\Modules\Email\Controllers\Api\V1\ConversationClassificationController::class, 'show'])
        ->name('email.mailbox.conversations.classification.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');

    Route::put('email/mailbox/conversations/{conversation}/classification', [\App\Modules\Email\Controllers\Api\V1\ConversationClassificationController::class, 'update'])
        ->name('email.mailbox.conversations.classification.update')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::delete('email/mailbox/conversations/{conversation}/classification', [\App\Modules\Email\Controllers\Api\V1\ConversationClassificationController::class, 'destroy'])
        ->name('email.mailbox.conversations.classification.destroy')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::post('email/mailbox/conversation-actions/preview', [\App\Modules\Email\Controllers\Api\V1\ConversationActionsController::class, 'preview'])
        ->name('email.mailbox.conversation-actions.preview')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');
    Route::get('email/mailbox/conversation-actions/{run}', [\App\Modules\Email\Controllers\Api\V1\ConversationActionsController::class, 'show'])
        ->name('email.mailbox.conversation-actions.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');
    Route::post('email/mailbox/conversation-actions/{run}/apply', [\App\Modules\Email\Controllers\Api\V1\ConversationActionsController::class, 'apply'])
        ->name('email.mailbox.conversation-actions.apply')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');
    Route::post('email/mailbox/conversation-actions/{run}/retry', [\App\Modules\Email\Controllers\Api\V1\ConversationActionsController::class, 'retry'])
        ->name('email.mailbox.conversation-actions.retry')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');
    Route::post('email/mailbox/conversation-actions/{run}/cancel', [\App\Modules\Email\Controllers\Api\V1\ConversationActionsController::class, 'cancel'])
        ->name('email.mailbox.conversation-actions.cancel')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::get('email/smart-inbox/suggestions/count', [\App\Modules\Email\Controllers\Api\V1\SmartInboxSuggestionsController::class, 'count'])
        ->name('email.smart-inbox.suggestions.count')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');

    Route::get('email/smart-inbox/suggestions', [\App\Modules\Email\Controllers\Api\V1\SmartInboxSuggestionsController::class, 'index'])
        ->name('email.smart-inbox.suggestions.index')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');

    Route::get('email/smart-inbox/suggestions/{suggestion}', [\App\Modules\Email\Controllers\Api\V1\SmartInboxSuggestionsController::class, 'show'])
        ->name('email.smart-inbox.suggestions.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');

    Route::post('email/mailbox/conversations/{conversation}/smart-inbox/analyze', [\App\Modules\Email\Controllers\Api\V1\SmartInboxSuggestionsController::class, 'analyze'])
        ->name('email.mailbox.conversations.smart-inbox.analyze')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read');

    Route::post('email/smart-inbox/suggestions/{suggestion}/dismiss', [\App\Modules\Email\Controllers\Api\V1\SmartInboxSuggestionsController::class, 'dismiss'])
        ->name('email.smart-inbox.suggestions.dismiss')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::patch('email/smart-inbox/suggestions/{suggestion}', [\App\Modules\Email\Controllers\Api\V1\SmartInboxSuggestionsController::class, 'correct'])
        ->name('email.smart-inbox.suggestions.correct')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::post('email/smart-inbox/suggestions/{suggestion}/apply', [\App\Modules\Email\Controllers\Api\V1\SmartInboxSuggestionsController::class, 'apply'])
        ->name('email.smart-inbox.suggestions.apply')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    Route::get('email/rule-reprocess-runs/{run}', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'run'])
        ->name('email.rules.runs.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.read');
    Route::post('email/rule-reprocess-runs/{run}/apply', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'applyRun'])
        ->name('email.rules.runs.apply')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.execute');
    Route::post('email/rule-reprocess-runs/{run}/cancel', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'cancelRun'])
        ->name('email.rules.runs.cancel')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.execute');
    Route::post('email/rule-reprocess-runs/{run}/retry', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'retryRun'])
        ->name('email.rules.runs.retry')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.execute');
    Route::post('email/rule-reprocess-runs/{run}/full-rerun', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'fullRerun'])
        ->name('email.rules.runs.full-rerun')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.execute');

    Route::post('email/rules/drafts', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'createDraft'])
        ->name('email.rules.drafts.create')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.write');

    Route::get('email/rules', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'index'])
        ->name('email.rules.index')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.read');

    Route::get('email/rules/{rule}', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'show'])
        ->name('email.rules.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.read');

    Route::get('email/rules/{rule}/draft', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'draft'])
        ->name('email.rules.drafts.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.read');
    Route::put('email/rules/{rule}/draft', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'saveDraft'])
        ->name('email.rules.drafts.update')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.write');
    Route::post('email/rules/{rule}/draft/validate', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'validateDraft'])
        ->name('email.rules.drafts.validate')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.write');
    Route::get('email/rules/{rule}/publish-preview', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'publishPreview'])
        ->name('email.rules.publish-preview')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.write');
    Route::post('email/rules/{rule}/publish', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'publish'])
        ->name('email.rules.publish')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.write');
    Route::get('email/rules/{rule}/versions', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'versions'])
        ->name('email.rules.versions.index')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.read');
    Route::post('email/rules/{rule}/reprocess-preview', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'reprocessPreview'])
        ->name('email.rules.reprocess-preview')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.execute');

    Route::post('email/rules/{rule}/preview', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'preview'])
        ->name('email.rules.preview')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.read');

    Route::get('email/rules/executions/{attempt}', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'execution'])
        ->name('email.rules.executions.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.read');

    Route::get('email/rules/executions/{attempt}/undo', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'undoEligibility'])
        ->name('email.rules.executions.undo.show')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.read');

    Route::post('email/rules/executions/{attempt}/undo', [\App\Modules\Email\Controllers\Api\V1\RulesController::class, 'undo'])
        ->name('email.rules.executions.undo.store')
        ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.execute');

    Route::get('email/mailbox/context', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'context'])
        ->name('email.mailbox.context')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');
    Route::get('email/mailbox/accounts', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'accounts'])
        ->name('email.mailbox.accounts.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');
    Route::get('email/mailbox/accounts/{account}/folders', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'folders'])
        ->name('email.mailbox.folders.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');
    Route::get('email/mailbox/conversations', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'index'])
        ->name('email.mailbox.conversations.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');
    Route::get('email/mailbox/conversations/{conversation}', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'conversation'])
        ->name('email.mailbox.conversations.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');
    Route::get('email/mailbox/placements/{placement}', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'show'])
        ->name('email.mailbox.placements.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');
    Route::get('email/mailbox/placements/{placement}/conversation', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'thread'])
        ->name('email.mailbox.placements.conversation')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');
    Route::post('email/mailbox/placements/{placement}/opened', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'opened'])
        ->name('email.mailbox.placements.opened')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.personal-state.write');
    Route::post('email/mailbox/placements/{placement}/read-state', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'readState'])
        ->name('email.mailbox.placements.read-state')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.personal-state.write');
    Route::get('email/mailbox/placements/{placement}/attachments/{attachment}/download', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'attachment'])
        ->name('email.mailbox.attachments.download')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.attachments.read');
    Route::get('email/mailbox/placements/{placement}/raw-source', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'raw'])
        ->name('email.mailbox.raw-source.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.raw.read');
    Route::post('email/mailbox/accounts/{account}/folders', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'store'])
        ->name('email.mailbox.folders.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.folders.manage');
    Route::get('email/mailbox/folders/{folder}/context', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'context'])
        ->name('email.mailbox.folders.context')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.folders.manage');
    Route::post('email/mailbox/folders/{folder}/rename', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'rename'])
        ->name('email.mailbox.folders.rename')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.folders.manage');
    Route::post('email/mailbox/folders/{folder}/move', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'move'])
        ->name('email.mailbox.folders.move')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.folders.manage');
    Route::post('email/mailbox/folders/{folder}/empty-into', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'emptyInto'])
        ->name('email.mailbox.folders.empty-into')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.folders.manage');
    Route::delete('email/mailbox/folders/{folder}', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'destroy'])
        ->name('email.mailbox.folders.destroy')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.folders.manage');
    Route::delete('email/mailbox/placements/{placement}', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'trash'])
        ->name('email.mailbox.placements.trash')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');
    Route::delete('email/inbox/messages/{message}', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'legacyTrash'])
        ->name('email.inbox.messages.trash')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');
    Route::post('email/mailbox/accounts/{account}/refresh', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'refresh'])
        ->name('email.mailbox.refresh')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.sync.execute');
    Route::get('email/mailbox/accounts/{account}/refresh', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'refreshStatus'])
        ->name('email.mailbox.refresh.status')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.sync.execute');
    Route::get('email/mailbox/preferences', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'preferences'])
        ->name('email.mailbox.preferences')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');
    Route::patch('email/mailbox/preferences', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'updatePreferences'])
        ->name('email.mailbox.preferences.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.personal-state.write');
    Route::get('email/mailbox/signature', [\App\Modules\Email\Controllers\Api\V1\MailboxAssistController::class, 'signature'])
        ->name('email.mailbox.signature.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.signature.read');
    Route::patch('email/mailbox/signature', [\App\Modules\Email\Controllers\Api\V1\MailboxAssistController::class, 'updateSignature'])
        ->name('email.mailbox.signature.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.signature.write');
    Route::post('email/mailbox/placements/{placement}/summary', [\App\Modules\Email\Controllers\Api\V1\MailboxAssistController::class, 'summary'])
        ->name('email.mailbox.summary')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.ai.use');
    Route::post('email/mailbox/placements/{placement}/composer-assist', [\App\Modules\Email\Controllers\Api\V1\MailboxAssistController::class, 'composerAssist'])
        ->name('email.mailbox.composer-assist')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.ai.use');
    Route::post('email/mailbox/placements/{placement}/personal-rule', [\App\Modules\Email\Controllers\Api\V1\MailboxAssistController::class, 'personalRule'])
        ->name('email.mailbox.personal-rule')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.personal-rules.write');
    Route::get('email/admin/accounts/context', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'accountContext'])
        ->name('email.admin.accounts.context')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.accounts.manage']);
    Route::get('email/admin/accounts', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'accounts'])
        ->name('email.admin.accounts.index')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.accounts.read']);
    Route::post('email/admin/accounts', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'accountStore'])
        ->name('email.admin.accounts.store')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.accounts.manage']);
    Route::get('email/admin/accounts/{account}', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'accountShow'])
        ->name('email.admin.accounts.show')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.accounts.manage']);
    Route::put('email/admin/accounts/{account}', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'accountUpdate'])
        ->name('email.admin.accounts.update')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.accounts.manage']);
    Route::put('email/admin/accounts/{account}/activation', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'accountActivate'])
        ->name('email.admin.accounts.activation')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.accounts.manage']);
    Route::post('email/admin/accounts/{account}/test', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'accountTest'])
        ->name('email.admin.accounts.test')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.accounts.manage']);
    Route::get('email/settings', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'settings'])
        ->name('email.settings.show')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.settings.read']);
    Route::put('email/settings', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'updateSettings'])
        ->name('email.settings.update')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.settings.manage']);
    Route::get('email/templates/context', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'templateContext'])
        ->name('email.templates.context')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.template_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.templates.read']);
    Route::get('email/templates', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'templates'])
        ->name('email.templates.index')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.template_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.templates.read']);
    Route::post('email/templates/defaults', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'templateDefaults'])
        ->name('email.templates.defaults')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.template_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.templates.manage']);
    Route::get('email/templates/{template}', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'templateShow'])
        ->name('email.templates.show')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.template_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.templates.read']);
    Route::post('email/templates', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'templateStore'])
        ->name('email.templates.store')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.template_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.templates.manage']);
    Route::put('email/templates/{template}', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'templateUpdate'])
        ->name('email.templates.update')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.template_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.templates.manage']);
    Route::post('email/templates/preview', [\App\Modules\Email\Controllers\Api\V1\EmailAdministrationController::class, 'templatePreview'])
        ->name('email.templates.preview')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.template_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.templates.read']);
    Route::get('email/rules-context', [\App\Modules\Email\Controllers\Api\V1\MailboxRulesCompletionController::class, 'context'])
        ->name('email.rules.context')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.read');
    Route::delete('email/rules/{rule}', [\App\Modules\Email\Controllers\Api\V1\MailboxRulesCompletionController::class, 'destroy'])
        ->name('email.rules.destroy')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.write');
    Route::delete('email/rules/{rule}/draft', [\App\Modules\Email\Controllers\Api\V1\MailboxRulesCompletionController::class, 'discardDraft'])
        ->name('email.rules.drafts.discard')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.write');
    Route::get('email/mailbox/placements/{placement}/rule-executions', [\App\Modules\Email\Controllers\Api\V1\MailboxRulesCompletionController::class, 'executions'])
        ->name('email.mailbox.rule-executions.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');
    Route::get('email/rule-reprocess-runs', [\App\Modules\Email\Controllers\Api\V1\MailboxRulesCompletionController::class, 'runs'])
        ->name('email.rules.runs.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.rules.read');
    Route::post('email/smart-inbox/batch-apply', [\App\Modules\Email\Controllers\Api\V1\MailboxRulesCompletionController::class, 'applyBatch'])
        ->name('email.smart-inbox.batch-apply')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');
    Route::post('email/smart-inbox/suggestions/{suggestion}/rule-prefill', [\App\Modules\Email\Controllers\Api\V1\MailboxRulesCompletionController::class, 'rulePrefill'])
        ->name('email.smart-inbox.rule-prefill')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.personal-rules.write');
    Route::post('email/mailbox/accounts/{account}/composer-assist', [\App\Modules\Email\Controllers\Api\V1\MailboxAssistController::class, 'newComposerAssist'])->name('email.mailbox.new-composer-assist')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.ai.use');
    Route::get('email/mailbox/access', [\App\Modules\Email\Controllers\Api\V1\MailboxAccessApiController::class, 'context'])->name('email.mailbox.access.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.access.read']);
    Route::get('email/mailbox/access-history', [\App\Modules\Email\Controllers\Api\V1\MailboxAccessApiController::class, 'history'])->name('email.mailbox.access.history')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.access.read']);
    Route::post('email/mailbox/accounts/{account}/delegations', [\App\Modules\Email\Controllers\Api\V1\MailboxAccessApiController::class, 'delegate'])->name('email.mailbox.delegations.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.access.manage']);
    Route::post('email/mailbox/accounts/{account}/delegations/{delegation}/revoke', [\App\Modules\Email\Controllers\Api\V1\MailboxAccessApiController::class, 'revokeDelegate'])->name('email.mailbox.delegations.revoke')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.access.manage']);
    Route::get('email/admin/emergency-access', [\App\Modules\Email\Controllers\Api\V1\MailboxAccessApiController::class, 'emergencyContext'])->name('email.admin.emergency.index')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.break_glass_activate', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.access.read']);
    Route::post('email/admin/emergency-access', [\App\Modules\Email\Controllers\Api\V1\MailboxAccessApiController::class, 'emergency'])->name('email.admin.emergency.store')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.break_glass_activate', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.emergency.activate']);
    Route::post('email/mailbox/emergency-access/{emergency}/revoke', [\App\Modules\Email\Controllers\Api\V1\MailboxAccessApiController::class, 'revokeEmergency'])->name('email.mailbox.emergency.revoke')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.access.manage']);
    Route::get('email/mailbox/accounts/{account}/unread-handovers', [\App\Modules\Email\Controllers\Api\V1\MailboxAccessApiController::class, 'handoverContext'])->name('email.mailbox.handovers.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.handovers.manage']);
    Route::get('email/mailbox/accounts/{account}/unread-handovers/{run}', [\App\Modules\Email\Controllers\Api\V1\MailboxAccessApiController::class, 'handoverShow'])->name('email.mailbox.handovers.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.handovers.manage']);
    Route::post('email/mailbox/accounts/{account}/unread-handovers/preview', [\App\Modules\Email\Controllers\Api\V1\MailboxAccessApiController::class, 'handoverPreview'])->name('email.mailbox.handovers.preview')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.handovers.manage']);
    Route::post('email/mailbox/accounts/{account}/unread-handovers/{run}/apply', [\App\Modules\Email\Controllers\Api\V1\MailboxAccessApiController::class, 'handoverApply'])->name('email.mailbox.handovers.apply')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.handovers.manage']);
    Route::get('email/admin/accounts/{account}/maintenance', [\App\Modules\Email\Controllers\Api\V1\MailboxMaintenanceApiController::class, 'index'])->name('email.maintenance.mailboxmaintenance.index')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.read']);
    Route::post('email/admin/accounts/{account}/historical-import/preview', [\App\Modules\Email\Controllers\Api\V1\MailboxMaintenanceApiController::class, 'previewHistoricalImport'])->name('email.maintenance.mailboxmaintenance.previewHistoricalImport')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/accounts/{account}/historical-import', [\App\Modules\Email\Controllers\Api\V1\MailboxMaintenanceApiController::class, 'startHistoricalImport'])->name('email.maintenance.mailboxmaintenance.startHistoricalImport')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/accounts/{account}/historical-import/{run}/cancel', [\App\Modules\Email\Controllers\Api\V1\MailboxMaintenanceApiController::class, 'cancelHistoricalImport'])->name('email.maintenance.mailboxmaintenance.cancelHistoricalImport')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/accounts/{account}/provider-reconciliation', [\App\Modules\Email\Controllers\Api\V1\MailboxMaintenanceApiController::class, 'startProviderReconciliation'])->name('email.maintenance.mailboxmaintenance.startProviderReconciliation')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/accounts/{account}/provider-reconciliation/{run}/cancel', [\App\Modules\Email\Controllers\Api\V1\MailboxMaintenanceApiController::class, 'cancelProviderReconciliation'])->name('email.maintenance.mailboxmaintenance.cancelProviderReconciliation')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/accounts/{account}/folders/{folder}/cursor-rebaseline/preview', [\App\Modules\Email\Controllers\Api\V1\MailboxMaintenanceApiController::class, 'previewCursorRebaseline'])->name('email.maintenance.mailboxmaintenance.previewCursorRebaseline')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/accounts/{account}/folders/{folder}/cursor-rebaseline', [\App\Modules\Email\Controllers\Api\V1\MailboxMaintenanceApiController::class, 'applyCursorRebaseline'])->name('email.maintenance.mailboxmaintenance.applyCursorRebaseline')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::get('email/admin/canonical-cutover', [\App\Modules\Email\Controllers\Api\V1\CanonicalCutoverApiController::class, 'index'])->name('email.maintenance.canonicalcutover.index')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.canonical_cutover_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.read']);
    Route::post('email/admin/canonical-cutover/backfill', [\App\Modules\Email\Controllers\Api\V1\CanonicalCutoverApiController::class, 'backfill'])->name('email.maintenance.canonicalcutover.backfill')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.canonical_cutover_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/canonical-cutover/audit', [\App\Modules\Email\Controllers\Api\V1\CanonicalCutoverApiController::class, 'audit'])->name('email.maintenance.canonicalcutover.audit')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.canonical_cutover_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/canonical-cutover/merge', [\App\Modules\Email\Controllers\Api\V1\CanonicalCutoverApiController::class, 'merge'])->name('email.maintenance.canonicalcutover.merge')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.canonical_cutover_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/canonical-cutover/mode', [\App\Modules\Email\Controllers\Api\V1\CanonicalCutoverApiController::class, 'mode'])->name('email.maintenance.canonicalcutover.mode')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.canonical_cutover_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::get('email/admin/canonical-cutover/{run}', [\App\Modules\Email\Controllers\Api\V1\CanonicalCutoverApiController::class, 'show'])->name('email.maintenance.canonicalcutover.show')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.canonical_cutover_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.read']);
    Route::post('email/admin/canonical-cutover/{run}/apply', [\App\Modules\Email\Controllers\Api\V1\CanonicalCutoverApiController::class, 'apply'])->name('email.maintenance.canonicalcutover.apply')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.canonical_cutover_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/canonical-cutover/{run}/rollback', [\App\Modules\Email\Controllers\Api\V1\CanonicalCutoverApiController::class, 'rollback'])->name('email.maintenance.canonicalcutover.rollback')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.canonical_cutover_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::get('email/admin/correlation', [\App\Modules\Email\Controllers\Api\V1\CanonicalCorrelationApiController::class, 'index'])->name('email.maintenance.canonicalcorrelation.index')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.mailbox_sync_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.read']);
    Route::post('email/admin/correlation', [\App\Modules\Email\Controllers\Api\V1\CanonicalCorrelationApiController::class, 'store'])->name('email.maintenance.canonicalcorrelation.store')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.mailbox_sync_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::get('email/admin/correlation/{run}', [\App\Modules\Email\Controllers\Api\V1\CanonicalCorrelationApiController::class, 'show'])->name('email.maintenance.canonicalcorrelation.show')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.mailbox_sync_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.read']);
    Route::post('email/admin/correlation/candidates/{candidate}/inspect', [\App\Modules\Email\Controllers\Api\V1\CanonicalCorrelationApiController::class, 'inspect'])->name('email.maintenance.canonicalcorrelation.inspect')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.mailbox_sync_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/correlation/candidates/{candidate}/review', [\App\Modules\Email\Controllers\Api\V1\CanonicalCorrelationApiController::class, 'review'])->name('email.maintenance.canonicalcorrelation.review')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.mailbox_sync_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/correlation/{run}/resume', [\App\Modules\Email\Controllers\Api\V1\CanonicalCorrelationApiController::class, 'resume'])->name('email.maintenance.canonicalcorrelation.resume')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.mailbox_sync_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::post('email/admin/correlation/{run}/cancel', [\App\Modules\Email\Controllers\Api\V1\CanonicalCorrelationApiController::class, 'cancel'])->name('email.maintenance.canonicalcorrelation.cancel')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.mailbox_sync_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::get('email/admin/ticket-correlation-conflicts', [\App\Modules\Email\Controllers\Api\V1\TicketCorrelationConflictApiController::class, 'index'])->name('email.maintenance.ticketcorrelationconflict.index')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.read']);
    Route::post('email/admin/ticket-correlation-conflicts/{conflict}/resolve', [\App\Modules\Email\Controllers\Api\V1\TicketCorrelationConflictApiController::class, 'resolve'])->name('email.maintenance.ticketcorrelationconflict.resolve')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.execute']);
    Route::get('email/mailbox/placements/{placement}/tickets', [\App\Modules\Email\Controllers\Api\V1\MailboxTicketApiController::class, 'context'])->name('email.mailbox.tickets.context')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read,tickets.read');
    Route::post('email/mailbox/placements/{placement}/tickets', [\App\Modules\Email\Controllers\Api\V1\MailboxTicketApiController::class, 'create'])->name('email.mailbox.tickets.create')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.tickets.write,tickets.create');
    Route::post('email/mailbox/placements/{placement}/ticket-links', [\App\Modules\Email\Controllers\Api\V1\MailboxTicketApiController::class, 'link'])->name('email.mailbox.tickets.link')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.tickets.write,tickets.update');
    Route::post('email/mailbox/placements/{placement}/ticket-suppression', [\App\Modules\Email\Controllers\Api\V1\MailboxTicketApiController::class, 'suppress'])->name('email.mailbox.tickets.suppress')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.tickets.write');
    Route::get('email/mailbox/live', [\App\Modules\Email\Controllers\Api\V1\MailboxLiveApiController::class, 'catchUp'])->name('email.mailbox.live.catch-up')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');
    Route::post('email/mailbox/live/acknowledge', [\App\Modules\Email\Controllers\Api\V1\MailboxLiveApiController::class, 'acknowledge'])->name('email.mailbox.live.acknowledge')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.personal-state.write');
    Route::post('email/mailbox/live/broadcast-auth', [\App\Modules\Email\Controllers\Api\V1\MailboxLiveApiController::class, 'broadcast'])->name('email.mailbox.live.broadcast-auth')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read', 'throttle:email-mail-broadcast-auth']);
    Route::get('email/admin/accounts/{account}/historical-import/{run}', [\App\Modules\Email\Controllers\Api\V1\MailboxMaintenanceApiController::class, 'historicalStatus'])->name('email.maintenance.historicalStatus')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.read']);
    Route::get('email/admin/accounts/{account}/provider-reconciliation/{run}', [\App\Modules\Email\Controllers\Api\V1\MailboxMaintenanceApiController::class, 'reconciliationStatus'])->name('email.maintenance.reconciliationStatus')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.read']);
    Route::get('email/admin/accounts/{account}/cursor-rebaseline/{run}', [\App\Modules\Email\Controllers\Api\V1\MailboxMaintenanceApiController::class, 'rebaselineStatus'])->name('email.maintenance.rebaselineStatus')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':email.account_manage', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.maintenance.read']);
    Route::get('email/mailbox/senders', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'senders'])->name('email.mailbox.senders')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.read');
    Route::get('email/mailbox/placements/{placement}/awareness', [\App\Modules\Email\Controllers\Api\V1\MailboxReadController::class, 'awareness'])->name('email.mailbox.awareness')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');
    Route::get('email/mailbox/placements/{placement}/rule-context', [\App\Modules\Email\Controllers\Api\V1\MailboxRulesCompletionController::class, 'placementContext'])->name('email.mailbox.rule-context')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.mailbox.read');

    Route::post('email/mailbox/placements/{placement}/spam', [\App\Modules\Email\Controllers\Api\V1\MailboxFoldersController::class, 'spam'])->name('email.mailbox.placements.spam')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.update');

    // End Mail API parity registrations.
    return;
}

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
    Route::post('/admin/settings/email/rules/{rule}/reprocess-preview', [RulesController::class, 'reprocessPreview'])
        ->name('admin.settings.email.rules.reprocess-preview');
    Route::post('/admin/settings/email/rules/reprocess-runs/{run}/apply', [RulesController::class, 'applyReprocess'])
        ->name('admin.settings.email.rules.reprocess-runs.apply');
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
