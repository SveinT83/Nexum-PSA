<?php

use App\Modules\Nextcloud\Controllers\TalkWebhookController;
use Illuminate\Support\Facades\Route;

if (($tdpsaLoadingPublicApiRoutes ?? false) === true) {
    // Nextcloud calls this public endpoint to deliver incoming bot messages.
    // Legitimacy is verified by HMAC-SHA256 signature headers in the controller.
    Route::post('nextcloud/talk/webhook', TalkWebhookController::class)
        ->name('nextcloud.talk.webhook');

    return;
}

// Protected Nextcloud API routes belong below. This file is also loaded inside
// the auth:sanctum API group by routes/api.php.
