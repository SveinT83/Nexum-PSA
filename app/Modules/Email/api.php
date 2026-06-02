<?php

use App\Modules\Email\Controllers\Api\V1\InboxController;
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
