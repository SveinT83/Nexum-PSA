<?php

use App\Modules\Email\Controllers\Api\V1\ConversationClassificationController;
use App\Modules\Email\Controllers\Api\V1\InboxController;
use App\Modules\Email\Controllers\Api\V1\MailboxDraftAttachmentsController;
use App\Modules\Email\Controllers\Api\V1\MailboxDraftsController;
use App\Modules\Email\Controllers\Api\V1\MailboxPlacementOperationsController;
use App\Modules\Email\Controllers\Api\V1\MailboxPresenceController;
use App\Modules\Email\Controllers\Api\V1\OutboundSubmissionsController;
use App\Modules\Email\Controllers\Api\V1\RemoteOperationsController;
use App\Modules\Email\Controllers\Api\V1\RulesController;
use App\Modules\Email\Controllers\Api\V1\SharedMailboxDraftsController;
use App\Modules\Email\Controllers\Api\V1\SmartInboxSuggestionsController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('email/inbox/messages', [InboxController::class, 'messages'])
    ->name('email.inbox.messages.index')
    ->middleware(CheckAbilities::class.':email.read');

Route::get('email/inbox/messages/{message}', [InboxController::class, 'show'])
    ->name('email.inbox.messages.show')
    ->middleware(CheckAbilities::class.':email.read');

Route::post('email/inbox/messages/{message}/spam', [InboxController::class, 'markSpam'])
    ->name('email.inbox.messages.spam')
    ->middleware(CheckAbilities::class.':email.update');

Route::post('email/inbox/poll', [InboxController::class, 'poll'])
    ->name('email.inbox.poll')
    ->middleware(CheckAbilities::class.':email.update');

Route::post('email/mailbox/placements/{placement}/operations', [MailboxPlacementOperationsController::class, 'store'])
    ->name('email.mailbox.placements.operations.store')
    ->middleware(CheckAbilities::class.':email.update');

Route::get('email/mailbox/drafts', [MailboxDraftsController::class, 'index'])
    ->name('email.mailbox.drafts.index')
    ->middleware(CheckAbilities::class.':email.drafts.read');

Route::post('email/mailbox/drafts', [MailboxDraftsController::class, 'store'])
    ->name('email.mailbox.drafts.store')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::get('email/mailbox/drafts/{draft}', [MailboxDraftsController::class, 'show'])
    ->name('email.mailbox.drafts.show')
    ->middleware(CheckAbilities::class.':email.drafts.read');

Route::patch('email/mailbox/drafts/{draft}', [MailboxDraftsController::class, 'update'])
    ->name('email.mailbox.drafts.update')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::delete('email/mailbox/drafts/{draft}', [MailboxDraftsController::class, 'discard'])
    ->name('email.mailbox.drafts.discard')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::post('email/mailbox/drafts/{draft}/attachments', [MailboxDraftAttachmentsController::class, 'store'])
    ->name('email.mailbox.drafts.attachments.store')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::delete('email/mailbox/drafts/{draft}/attachments/{attachment}', [MailboxDraftAttachmentsController::class, 'destroy'])
    ->name('email.mailbox.drafts.attachments.destroy')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::post('email/mailbox/drafts/{draft}/provider-sync', [MailboxDraftsController::class, 'syncProvider'])
    ->name('email.mailbox.drafts.provider-sync')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::post('email/mailbox/drafts/{draft}/preview', [MailboxDraftsController::class, 'preview'])
    ->name('email.mailbox.drafts.preview')
    ->middleware(CheckAbilities::class.':email.send');

Route::post('email/mailbox/drafts/{draft}/send', [MailboxDraftsController::class, 'send'])
    ->name('email.mailbox.drafts.send')
    ->middleware(CheckAbilities::class.':email.send');

Route::post('email/mailbox/drafts/{draft}/share', [SharedMailboxDraftsController::class, 'share'])
    ->name('email.mailbox.drafts.share')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::get('email/mailbox/shared-drafts/{draft}', [SharedMailboxDraftsController::class, 'show'])
    ->name('email.mailbox.shared-drafts.show')
    ->middleware(CheckAbilities::class.':email.drafts.read');

Route::post('email/mailbox/shared-drafts/{draft}/lease', [SharedMailboxDraftsController::class, 'acquire'])
    ->name('email.mailbox.shared-drafts.lease.acquire')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::patch('email/mailbox/shared-drafts/{draft}/lease', [SharedMailboxDraftsController::class, 'renew'])
    ->name('email.mailbox.shared-drafts.lease.renew')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::delete('email/mailbox/shared-drafts/{draft}/lease', [SharedMailboxDraftsController::class, 'release'])
    ->name('email.mailbox.shared-drafts.lease.release')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::patch('email/mailbox/shared-drafts/{draft}', [SharedMailboxDraftsController::class, 'update'])
    ->name('email.mailbox.shared-drafts.update')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::delete('email/mailbox/shared-drafts/{draft}', [SharedMailboxDraftsController::class, 'discard'])
    ->name('email.mailbox.shared-drafts.discard')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::post('email/mailbox/shared-drafts/{draft}/attachments', [SharedMailboxDraftsController::class, 'storeAttachments'])
    ->name('email.mailbox.shared-drafts.attachments.store')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::delete('email/mailbox/shared-drafts/{draft}/attachments/{attachment}', [SharedMailboxDraftsController::class, 'removeAttachment'])
    ->name('email.mailbox.shared-drafts.attachments.destroy')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::post('email/mailbox/shared-drafts/{draft}/rebase-preview', [SharedMailboxDraftsController::class, 'rebasePreview'])
    ->name('email.mailbox.shared-drafts.rebase.preview')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::post('email/mailbox/shared-drafts/{draft}/rebase', [SharedMailboxDraftsController::class, 'rebase'])
    ->name('email.mailbox.shared-drafts.rebase')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::post('email/mailbox/shared-drafts/{draft}/preview', [SharedMailboxDraftsController::class, 'preview'])
    ->name('email.mailbox.shared-drafts.preview')
    ->middleware(CheckAbilities::class.':email.send');

Route::post('email/mailbox/shared-drafts/{draft}/send', [SharedMailboxDraftsController::class, 'send'])
    ->name('email.mailbox.shared-drafts.send')
    ->middleware(CheckAbilities::class.':email.send');

Route::get('email/mailbox/conversations/{conversation}/presence', [MailboxPresenceController::class, 'show'])
    ->name('email.mailbox.conversations.presence.show')
    ->middleware(CheckAbilities::class.':email.drafts.read');

Route::post('email/mailbox/conversations/{conversation}/presence', [MailboxPresenceController::class, 'heartbeat'])
    ->name('email.mailbox.conversations.presence.heartbeat')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::delete('email/mailbox/conversations/{conversation}/presence', [MailboxPresenceController::class, 'leave'])
    ->name('email.mailbox.conversations.presence.leave')
    ->middleware(CheckAbilities::class.':email.drafts.write');

Route::get('email/mailbox/submissions/{submission}', [OutboundSubmissionsController::class, 'show'])
    ->name('email.mailbox.submissions.show')
    ->middleware(CheckAbilities::class.':email.send');

Route::get('email/mailbox/submissions/{submission}/sent-reconciliation', [OutboundSubmissionsController::class, 'sentReconciliation'])
    ->name('email.mailbox.submissions.sent-reconciliation.show')
    ->middleware(CheckAbilities::class.':email.send');

Route::get('email/mailbox/remote-operations', [RemoteOperationsController::class, 'index'])
    ->name('email.mailbox.remote-operations.index')
    ->middleware(CheckAbilities::class.':email.read');

Route::get('email/mailbox/remote-operations/{operation}', [RemoteOperationsController::class, 'show'])
    ->name('email.mailbox.remote-operations.show')
    ->middleware(CheckAbilities::class.':email.read');

Route::post('email/mailbox/remote-operations/{operation}/retry', [RemoteOperationsController::class, 'retry'])
    ->name('email.mailbox.remote-operations.retry')
    ->middleware(CheckAbilities::class.':email.update');

Route::post('email/mailbox/remote-operations/{operation}/cancel', [RemoteOperationsController::class, 'cancel'])
    ->name('email.mailbox.remote-operations.cancel')
    ->middleware(CheckAbilities::class.':email.update');

Route::get('email/mailbox/remote-operations/{operation}/undo', [RemoteOperationsController::class, 'undoEligibility'])
    ->name('email.mailbox.remote-operations.undo.show')
    ->middleware(CheckAbilities::class.':email.read');

Route::post('email/mailbox/remote-operations/{operation}/undo', [RemoteOperationsController::class, 'undo'])
    ->name('email.mailbox.remote-operations.undo.store')
    ->middleware(CheckAbilities::class.':email.update');

Route::get('email/mailbox/conversations/{conversation}/classification', [ConversationClassificationController::class, 'show'])
    ->name('email.mailbox.conversations.classification.show')
    ->middleware(CheckAbilities::class.':email.read');

Route::put('email/mailbox/conversations/{conversation}/classification', [ConversationClassificationController::class, 'update'])
    ->name('email.mailbox.conversations.classification.update')
    ->middleware(CheckAbilities::class.':email.update');

Route::delete('email/mailbox/conversations/{conversation}/classification', [ConversationClassificationController::class, 'destroy'])
    ->name('email.mailbox.conversations.classification.destroy')
    ->middleware(CheckAbilities::class.':email.update');

Route::get('email/smart-inbox/suggestions/count', [SmartInboxSuggestionsController::class, 'count'])
    ->name('email.smart-inbox.suggestions.count')
    ->middleware(CheckAbilities::class.':email.read');

Route::get('email/smart-inbox/suggestions', [SmartInboxSuggestionsController::class, 'index'])
    ->name('email.smart-inbox.suggestions.index')
    ->middleware(CheckAbilities::class.':email.read');

Route::get('email/smart-inbox/suggestions/{suggestion}', [SmartInboxSuggestionsController::class, 'show'])
    ->name('email.smart-inbox.suggestions.show')
    ->middleware(CheckAbilities::class.':email.read');

Route::post('email/mailbox/conversations/{conversation}/smart-inbox/analyze', [SmartInboxSuggestionsController::class, 'analyze'])
    ->name('email.mailbox.conversations.smart-inbox.analyze')
    ->middleware(CheckAbilities::class.':email.read');

Route::post('email/smart-inbox/suggestions/{suggestion}/dismiss', [SmartInboxSuggestionsController::class, 'dismiss'])
    ->name('email.smart-inbox.suggestions.dismiss')
    ->middleware(CheckAbilities::class.':email.update');

Route::patch('email/smart-inbox/suggestions/{suggestion}', [SmartInboxSuggestionsController::class, 'correct'])
    ->name('email.smart-inbox.suggestions.correct')
    ->middleware(CheckAbilities::class.':email.update');

Route::post('email/smart-inbox/suggestions/{suggestion}/apply', [SmartInboxSuggestionsController::class, 'apply'])
    ->name('email.smart-inbox.suggestions.apply')
    ->middleware(CheckAbilities::class.':email.update');

Route::get('email/rules', [RulesController::class, 'index'])
    ->name('email.rules.index')
    ->middleware(CheckAbilities::class.':email.rules.read');

Route::get('email/rules/{rule}', [RulesController::class, 'show'])
    ->name('email.rules.show')
    ->middleware(CheckAbilities::class.':email.rules.read');

Route::post('email/rules/{rule}/preview', [RulesController::class, 'preview'])
    ->name('email.rules.preview')
    ->middleware(CheckAbilities::class.':email.rules.read');

Route::get('email/rules/executions/{attempt}', [RulesController::class, 'execution'])
    ->name('email.rules.executions.show')
    ->middleware(CheckAbilities::class.':email.rules.read');

Route::get('email/rules/executions/{attempt}/undo', [RulesController::class, 'undoEligibility'])
    ->name('email.rules.executions.undo.show')
    ->middleware(CheckAbilities::class.':email.rules.read');

Route::post('email/rules/executions/{attempt}/undo', [RulesController::class, 'undo'])
    ->name('email.rules.executions.undo.store')
    ->middleware(CheckAbilities::class.':email.rules.execute');
