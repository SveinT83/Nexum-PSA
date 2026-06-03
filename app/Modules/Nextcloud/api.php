<?php

// Nextcloud Talk webhook — public endpoint, no auth required.
// Nextcloud calls this URL to deliver incoming bot messages.
// Legitimacy is verified via HMAC-SHA256 signature headers.
// This file is loaded by routes/api.php — do NOT put auth middleware here.

use App\Modules\Nextcloud\Controllers\TalkWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('nextcloud/talk/webhook', TalkWebhookController::class)
    ->name('nextcloud.talk.webhook');
