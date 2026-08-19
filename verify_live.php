<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\Email\Services\EmailLiveInvalidator;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailLiveProjectionPublication;
use App\Modules\Email\Models\EmailLiveProjectionDelivery;
use Illuminate\Support\Facades\DB;

$invalidator = app(EmailLiveInvalidator::class);

DB::transaction(function() use ($invalidator) {
    echo "Recording invalidation...\n";
    $invalidator->record([
        'account' => [
            1 => [EmailLiveProjectionChange::TYPE_MAIL_PROJECTION]
        ],
        'conversations' => [12345]
    ]);
});

echo "Waiting for publisher and worker (3s)...\n";
sleep(3);

$change = EmailLiveProjectionChange::latest()->first();
echo "Latest Change ID: {$change->id}, Status: {$change->publication_status}\n";

$publication = EmailLiveProjectionPublication::where('source_change_id', $change->id)->first();
if ($publication) {
    echo "Publication found: ID {$publication->id}, Status: {$publication->status}, Phase: {$publication->phase}\n";
} else {
    echo "Publication NOT found!\n";
}

$delivery = EmailLiveProjectionDelivery::where('source_change_id', $change->id)->first();
if ($delivery) {
    echo "Delivery found: ID {$delivery->id}, User ID: {$delivery->user_id}, Status: {$delivery->status}\n";
} else {
    echo "Delivery NOT found!\n";
}
