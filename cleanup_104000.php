<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

if (Schema::hasColumn('email_message_user_states', 'access_epoch')) {
    try {
        Schema::table('email_message_user_states', function (Blueprint $table) {
             $table->dropUnique('em_msg_state_message_user_epoch_uq');
        });
    } catch (\Exception $e) {}

    try {
        Schema::table('email_message_user_states', function (Blueprint $table) {
             $table->dropIndex('em_msg_state_user_epoch_unread_ix');
        });
    } catch (\Exception $e) {}

    Schema::table('email_message_user_states', function (Blueprint $table) {
        $table->dropColumn('access_epoch');
    });
}

Schema::dropIfExists('email_account_user_read_baselines');

echo "Cleanup 104000 done.\n";
