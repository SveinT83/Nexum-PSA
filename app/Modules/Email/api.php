<?php

use App\Modules\Email\Controllers\Api\V1\ConversationClassificationController;
use App\Modules\Email\Controllers\Api\V1\InboxController;
use App\Modules\Email\Controllers\Api\V1\MailboxPlacementOperationsController;
use App\Modules\Email\Controllers\Api\V1\RemoteOperationsController;
use App\Modules\Email\Controllers\Api\V1\RulesController;
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
